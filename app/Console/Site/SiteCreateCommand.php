<?php

declare(strict_types=1);

namespace Deployer\Console\Site;

use Deployer\Contracts\BaseCommand;
use Deployer\DTOs\SiteDTO;
use Deployer\DTOs\SiteServerDTO;
use Deployer\Exceptions\ValidationException;
use Deployer\Traits\PlaybooksTrait;
use Deployer\Traits\ServersTrait;
use Deployer\Traits\SitesTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'site:create',
    description: 'Create a new site on a server and add it to inventory'
)]
class SiteCreateCommand extends BaseCommand
{
    use PlaybooksTrait;
    use ServersTrait;
    use SitesTrait;

    // ----
    // Configuration
    // ----

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Domain name')
            ->addOption('server', null, InputOption::VALUE_REQUIRED, 'Server name')
            ->addOption('php-version', null, InputOption::VALUE_REQUIRED, 'PHP version to use')
            ->addOption('www-mode', null, InputOption::VALUE_REQUIRED, 'WWW handling mode (redirect-to-root, redirect-to-www)');
    }

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->h1('Create New Site');

        //
        // Select server
        // ----

        $server = $this->selectServerDeets();

        if (is_int($server) || null === $server->info) {
            return Command::FAILURE;
        }

        $serverInstalled = $this->ensureServerInstalled($server->info);

        if (is_int($serverInstalled)) {
            return $serverInstalled;
        }

        //
        // Gather site details
        // ----

        $siteInfo = $this->gatherSiteDeets($server->info);

        if (is_int($siteInfo)) {
            return Command::FAILURE;
        }

        [
            'domain' => $domain,
            'phpVersion' => $phpVersion,
            'wwwMode' => $wwwMode,
        ] = $siteInfo;

        $site = new SiteDTO(
            domain: $domain,
            repo: null,
            branch: null,
            server: $server->name,
            phpVersion: $phpVersion,
        );

        $siteServer = new SiteServerDTO($site, $server);

        $this->displaySiteDeets($site);

        //
        // Check if site already exists on remote server
        // ----

        $checkResult = $this->ssh->executeCommand(
            $server,
            sprintf('test -d /home/deployer/sites/%s', escapeshellarg($domain))
        );

        if (0 === $checkResult['exit_code']) {
            $this->warn("Site '{$domain}' already exists on the server but not in inventory");
            $this->info('To re-add to inventory, manually edit the inventory file');
            $this->info('To recreate the site, delete it first with <|cyan>site:delete</>');

            return Command::FAILURE;
        }

        //
        // Create site on server
        // ----

        $result = $this->executePlaybookSilently(
            $siteServer,
            'site-create',
            'Creating site on server...',
            [
                'DEPLOYER_WWW_MODE' => $wwwMode,
            ]
        );

        if (is_int($result)) {
            return $result;
        }

        //
        // Save to inventory
        // ----

        try {
            $this->sites->create($site);
        } catch (\RuntimeException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        $this->yay("Site '{$domain}' added to inventory");

        //
        // Display next steps
        // ----

        $this->info('Please update your DNS records:');

        $this->ul([
            'Point <fg=cyan>@</> (root) to <fg=cyan>' . $server->host . '</>',
            'Point <fg=cyan>www</> to <fg=cyan>' . $server->host . '</>',
            'Run <fg=cyan>site:https</> to enable HTTPS once you have your DNS records set up',
            'Deploy your new site with <fg=cyan>site:deploy</>'
        ]);

        //
        // Show command replay
        // ----

        $this->commandReplay('site:create', [
            'domain' => $domain,
            'server' => $server->name,
            'php-version' => $phpVersion,
            'www-mode' => $wwwMode,
        ]);

        return Command::SUCCESS;
    }

    // ----
    // Helpers
    // ----

    /**
     * Select PHP version to use for the site.
     *
     * If multiple PHP versions are installed, prompts user to select.
     * If only one version is installed, uses that automatically.
     *
     * @param array<string, mixed> $info Server information from serverInfo()
     * @return string|int Returns PHP version string or Command::FAILURE on error
     */
    private function selectPhpVersion(array $info): string|int
    {
        /** @var array{versions: array<array{version: string, extensions: array<string>}>, default?: string} $phpInfo */
        $phpInfo = $info['php'];
        $versions = $phpInfo['versions'];

        if ([] === $versions) {
            $this->nay('No PHP versions found on server');

            return Command::FAILURE;
        }

        $installedPhpVersions = array_map(
            fn (array $v): string => $v['version'],
            $versions
        );

        if (1 === count($installedPhpVersions)) {
            return $installedPhpVersions[0];
        }

        rsort($installedPhpVersions, SORT_NATURAL);

        $defaultVersionStr = $phpInfo['default'] ?? $installedPhpVersions[0];

        try {
            /** @var string $phpVersion */
            $phpVersion = $this->io->getValidatedOptionOrPrompt(
                'php-version',
                fn ($validate) => $this->io->promptSelect(
                    label: 'PHP version for this site:',
                    options: $installedPhpVersions,
                    default: $defaultVersionStr,
                    validate: $validate
                ),
                fn ($value) => $this->validatePhpVersionSelection($value, $installedPhpVersions)
            );
        } catch (ValidationException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        return $phpVersion;
    }

    /**
     * Gather site details from user input or CLI options.
     *
     * @param array<string, mixed> $info Server information from serverInfo()
     * @return array{domain: string, phpVersion: string, wwwMode: string}|int
     */
    protected function gatherSiteDeets(array $info): array|int
    {
        try {
            /** @var string $domain */
            $domain = $this->io->getValidatedOptionOrPrompt(
                'domain',
                fn ($validate) => $this->io->promptText(
                    label: 'Domain name:',
                    placeholder: 'example.com',
                    required: true,
                    validate: $validate
                ),
                fn ($value) => $this->validateSiteDomain($value)
            );

            // Normalize immediately after input
            $domain = $this->normalizeDomain($domain);

            //
            // Determine WWW handling
            // ----

            $wwwModes = [
                'redirect-to-root' => 'Redirect www to non-www',
                'redirect-to-www' => 'Redirect non-www to www',
            ];

            /** @var string $wwwMode */
            $wwwMode = $this->io->getValidatedOptionOrPrompt(
                'www-mode',
                fn ($validate) => $this->io->promptSelect(
                    label: "How should 'www.{$domain}' be handled?",
                    options: $wwwModes,
                    default: 'redirect-to-root',
                    validate: $validate
                ),
                fn ($value) => in_array($value, array_keys($wwwModes), true)
                    ? null
                    : sprintf("Invalid WWW mode '%s'. Allowed: %s", is_scalar($value) ? $value : gettype($value), implode(', ', array_keys($wwwModes)))
            );
        } catch (ValidationException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        //
        // Select PHP version
        // ----

        $phpVersion = $this->selectPhpVersion($info);

        if (is_int($phpVersion)) {
            return Command::FAILURE;
        }

        return [
            'domain' => $domain,
            'phpVersion' => $phpVersion,
            'wwwMode' => $wwwMode,
        ];
    }

    // ----
    // Validation
    // ----

    /**
     * Validate that server is ready to create a site.
     *
     * Checks for:
     * - Caddy web server installed
     * - PHP installed
     *
     * @param array<string, mixed> $info Server information from serverInfo()
     * @return int|null Returns Command::FAILURE if validation fails, null if successful
     */
    private function ensureServerInstalled(array $info): ?int
    {
        // Check if Caddy is installed
        $caddyInstalled = isset($info['caddy']) && is_array($info['caddy']) && true === ($info['caddy']['available'] ?? false);

        // Check if PHP is installed
        $phpInstalled = isset($info['php']) && is_array($info['php']) && isset($info['php']['versions']) && is_array($info['php']['versions']) && count($info['php']['versions']) > 0;

        if (! $caddyInstalled || ! $phpInstalled) {
            $this->warn('Server has not been installed yet');
            $this->info('Run <|cyan>server:install</> to install the server first');

            return Command::FAILURE;
        }

        return null;
    }

    /**
     * Validate PHP version selection.
     *
     * @param array<int, string> $installed Installed PHP versions
     *
     * @return string|null Error message if invalid, null if valid
     */
    private function validatePhpVersionSelection(mixed $value, array $installed): ?string
    {
        if (! is_string($value)) {
            return 'PHP version must be a string';
        }

        if (! in_array($value, $installed, true)) {
            return "PHP version {$value} is not installed. Available: " . implode(', ', $installed);
        }

        return null;
    }
}

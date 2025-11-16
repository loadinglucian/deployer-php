<?php

declare(strict_types=1);

namespace Bigpixelrocket\DeployerPHP\Console\Server;

use Bigpixelrocket\DeployerPHP\Contracts\BaseCommand;
use Bigpixelrocket\DeployerPHP\DTOs\ServerDTO;
use Bigpixelrocket\DeployerPHP\Enums\Distribution;
use Bigpixelrocket\DeployerPHP\Traits\PlaybooksTrait;
use Bigpixelrocket\DeployerPHP\Traits\ServersTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'server:install',
    description: 'Install and prepare server for hosting PHP applications'
)]
class ServerInstallCommand extends BaseCommand
{
    use PlaybooksTrait;
    use ServersTrait;

    // ----
    // Configuration
    // ----

    protected function configure(): void
    {
        parent::configure();

        $this->addOption('server', null, InputOption::VALUE_REQUIRED, 'Server name');
        $this->addOption('php-version', null, InputOption::VALUE_REQUIRED, 'PHP version to install');
        $this->addOption('php-default', null, InputOption::VALUE_NEGATABLE, 'Set as default PHP version');
        $this->addOption('php-extensions', null, InputOption::VALUE_REQUIRED, 'Comma-separated PHP extensions');
    }

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->heading('Install Server');

        //
        // Select server & display details
        // ----

        $server = $this->selectServer();

        if (is_int($server)) {
            return $server;
        }

        $this->displayServerDeets($server);

        //
        // Get server info (verifies SSH connection and validates distribution & permissions)
        // ----

        $info = $this->serverInfo($server);

        if (is_int($info)) {
            return $info;
        }

        [
            'distro' => $distro,
            'permissions' => $permissions,
        ] = $info;

        /** @var string $distro */
        /** @var string $permissions */

        //
        // Prepare packages
        // ----

        $packageList = $this->executePlaybook(
            $server,
            'package-list',
            'Preparing packages...',
            [
                'DEPLOYER_DISTRO' => $distro,
                'DEPLOYER_PERMS' => $permissions,
                'DEPLOYER_GATHER_PHP' => 'true',
            ],
            true
        );

        if (is_int($packageList)) {
            return $packageList;
        }

        //
        // Install base packages
        // ----

        $result = $this->executePlaybook(
            $server,
            'install-base',
            'Installing base packages...',
            [
                'DEPLOYER_DISTRO' => $distro,
                'DEPLOYER_PERMS' => $permissions,
            ],
            true
        );

        if (is_int($result)) {
            return $result;
        }

        //
        // Install PHP
        // ----

        $phpResult = $this->installPhp($server, $info, $packageList);

        if (is_int($phpResult)) {
            return $phpResult;
        }

        /** @var array{status: int, php_version: string, php_default: bool, php_default_prompted: bool, php_extensions: string} $phpResult */
        $phpVersion = $phpResult['php_version'];
        $phpDefault = $phpResult['php_default'];
        $phpDefaultPrompted = $phpResult['php_default_prompted'];
        $phpExtensions = $phpResult['php_extensions'];

        //
        // Install Bun
        // ----

        $bunResult = $this->executePlaybook(
            $server,
            'install-bun',
            'Installing Bun...',
            [
                'DEPLOYER_PERMS' => $permissions,
            ],
            true
        );

        if (is_int($bunResult)) {
            return $bunResult;
        }

        //
        // Setup deployer user
        // ----

        $deployerResult = $this->executePlaybook(
            $server,
            'install-deployer',
            'Setting up deployer user...',
            [
                'DEPLOYER_DISTRO' => $distro,
                'DEPLOYER_PERMS' => $permissions,
                'DEPLOYER_SERVER_NAME' => $server->name,
            ],
            true
        );

        if (is_int($deployerResult)) {
            $this->io->error('Deployer user setup failed');

            return $deployerResult;
        }

        $this->yay('Deployer user setup successful');

        //
        // Setup demo site
        // ----

        /** @var string $permissions */
        $demoResult = $this->executePlaybook(
            $server,
            'demo-site',
            'Setting up demo site...',
            [
                'DEPLOYER_DISTRO' => $distro,
                'DEPLOYER_PERMS' => $permissions,
            ],
            true
        );

        if (is_int($demoResult)) {
            $this->io->error('Demo site setup failed');

            return Command::FAILURE;
        }

        $this->yay('Demo site setup successful');

        //
        // Verify installation
        // ----

        // IPv6 addresses must be wrapped in brackets for URLs
        $host = $server->host;
        if (str_contains($host, ':')) {
            // Any IPv6 literal must be wrapped in brackets
            $url = "http://[{$host}]";
        } else {
            $url = "http://{$host}";
        }
        /** @var string|null $deployKey */
        $deployKey = $deployerResult['deploy_public_key'] ?? null;

        $verification = $this->io->promptSpin(
            fn () => $this->verifyInstallation($url, $deployKey),
            'Verifying installation...'
        );

        if ($verification['status'] === 'success') {
            $this->yay($verification['message']);
        } else {
            $this->io->warning($verification['message']);
        }

        if ($verification['lines'] !== []) {
            $this->io->writeln($verification['lines']);
        }

        //
        // Show command replay
        // ----

        $replayOptions = [
            'server' => $server->name,
            'php-version' => $phpVersion,
            'php-extensions' => $phpExtensions,
        ];

        if ($phpDefaultPrompted) {
            $replayOptions['php-default'] = $phpDefault;
        }

        $this->showCommandReplay('server:install', $replayOptions);

        return Command::SUCCESS;
    }

    //
    // PHP Installation
    // ----

    /**
     * Install PHP on a server.
     *
     * Prompts for PHP version selection and handles installation via playbook.
     * Automatically sets first PHP install as default, otherwise prompts user.
     *
     * @param ServerDTO $server Server to install PHP on
     * @param array<string, mixed> $info Server information from serverInfo()
     * @param array<string, mixed> $packageList Package list from package-list playbook
     * @return array{status: int, php_version: string, php_default: bool, php_default_prompted: bool, php_extensions: string}|int Returns array with status and values, or int on failure
     */
    private function installPhp(ServerDTO $server, array $info, array $packageList): array|int
    {
        //
        // Default extension list
        // ----

        $defaultExtensions = [
            'bcmath', 'cli', 'common', 'curl', 'fpm', 'gd', 'gmp',
            'igbinary', 'imagick', 'imap', 'intl', 'mbstring',
            'memcached', 'msgpack', 'mysql', 'opcache', 'pgsql',
            'readline', 'redis', 'soap', 'sqlite3', 'swoole', 'xml', 'zip',
        ];

        //
        // Extract available PHP versions
        // ----

        if (!isset($packageList['php']) || !is_array($packageList['php']) || empty($packageList['php'])) {
            $this->io->error('No PHP versions available in package list');

            return Command::FAILURE;
        }

        $phpVersions = array_keys($packageList['php']);
        rsort($phpVersions, SORT_NATURAL); // Newest first

        //
        // Extract installed PHP versions
        // ----

        $installedPhpVersions = [];
        if (isset($info['php']) && is_array($info['php']) && isset($info['php']['versions']) && is_array($info['php']['versions'])) {
            foreach ($info['php']['versions'] as $version) {
                // Handle both new format (array with version/extensions) and old format (string)
                if (is_array($version) && isset($version['version'])) {
                    /** @var string $versionStr */
                    $versionStr = $version['version'];
                    $installedPhpVersions[] = $versionStr;
                } elseif (is_string($version) || is_numeric($version)) {
                    $installedPhpVersions[] = (string) $version;
                }
            }
        }

        //
        // Prompt for version to install
        // ----

        $defaultVersion = in_array('8.4', $phpVersions) ? '8.4' : $phpVersions[0];
        $phpVersion = (string) $this->io->getOptionOrPrompt(
            'php-version',
            fn () => $this->io->promptSelect(
                label: 'PHP version:',
                options: $phpVersions,
                default: $defaultVersion
            )
        );

        // Validate CLI-provided version exists in available versions
        if (!in_array($phpVersion, $phpVersions, true)) {
            $this->io->error(
                "PHP version {$phpVersion} is not available. Available versions: " . implode(', ', $phpVersions)
            );

            return Command::FAILURE;
        }

        //
        // Select PHP extensions
        // ----

        /** @var array<int|string, string> $availableExtensions */
        $availableExtensions = [];

        /** @var array<string, mixed> $phpData */
        $phpData = $packageList['php'];
        /** @var mixed $versionData */
        $versionData = $phpData[$phpVersion] ?? null;
        if (is_array($versionData) && isset($versionData['extensions']) && is_array($versionData['extensions'])) {
            /** @var array<int|string, string> $extensions */
            $extensions = $versionData['extensions'];
            $availableExtensions = $extensions;
        }

        if (empty($availableExtensions)) {
            $this->io->error("No extensions available for PHP {$phpVersion}");

            return Command::FAILURE;
        }

        // Filter defaults to only those available for this version
        $preSelected = array_values(array_intersect($defaultExtensions, $availableExtensions));

        $selectedExtensions = $this->io->getOptionOrPrompt(
            'php-extensions',
            fn () => $this->io->promptMultiselect(
                label: 'Select PHP extensions:',
                options: $availableExtensions,
                default: $preSelected,
                scroll: 15
            )
        );

        // Handle both array (from prompt) and string (from CLI option)
        if (is_string($selectedExtensions)) {
            $selectedExtensions = array_filter(
                array_map(trim(...), explode(',', $selectedExtensions)),
                static fn (string $ext): bool => $ext !== ''
            );
        }

        if (!is_array($selectedExtensions)) {
            $this->io->error('Invalid PHP extensions selection');

            return Command::FAILURE;
        }

        // Validate all selected extensions exist for this PHP version
        $unknownExtensions = array_diff($selectedExtensions, $availableExtensions);
        if ($unknownExtensions !== []) {
            $this->io->error(
                'Unknown PHP extensions for PHP ' . $phpVersion . ': ' . implode(', ', $unknownExtensions)
            );

            return Command::FAILURE;
        }

        if ($selectedExtensions === []) {
            $this->io->error('At least one extension must be selected');

            return Command::FAILURE;
        }

        //
        // Determine if setting as default
        // ----

        $defaultPrompted = false;

        if (count($installedPhpVersions) === 0) {
            // First PHP install - automatically set as default
            $setAsDefault = true;
        } else {
            // Check if selected version is already the default
            /** @var array{default?: string|int|float}|null $phpInfo */
            $phpInfo = $info['php'] ?? null;
            $currentDefault = is_array($phpInfo) ? ($phpInfo['default'] ?? null) : null;
            $isAlreadyDefault = $currentDefault !== null && (string) $currentDefault === $phpVersion;

            if ($isAlreadyDefault) {
                // Selected version is already default - skip prompt
                $setAsDefault = true;
            } else {
                // PHP already installed but not default - ask user
                $defaultPrompted = true;
                $setAsDefault = (bool) $this->io->getOptionOrPrompt(
                    'php-default',
                    fn () => $this->io->promptConfirm(
                        label: "Set PHP {$phpVersion} as default?",
                        default: false
                    )
                );
            }
        }

        //
        // Execute installation playbook
        // ----

        /** @var string $distro */
        $distro = $info['distro'];
        /** @var string $permissions */
        $permissions = $info['permissions'];

        $result = $this->executePlaybook(
            $server,
            'install-php',
            "Installing PHP {$phpVersion}...",
            [
                'DEPLOYER_DISTRO' => $distro,
                'DEPLOYER_PERMS' => $permissions,
                'DEPLOYER_PHP_VERSION' => $phpVersion,
                'DEPLOYER_PHP_SET_DEFAULT' => $setAsDefault ? 'true' : 'false',
                'DEPLOYER_PHP_EXTENSIONS' => implode(',', $selectedExtensions),
            ],
            true
        );

        if (is_int($result)) {
            $this->io->error('PHP installation failed');

            return Command::FAILURE;
        }

        $defaultStatus = $setAsDefault ? ' (set as default)' : '';
        $this->yay("Installed PHP {$phpVersion} successfully{$defaultStatus}");

        return [
            'status' => Command::SUCCESS,
            'php_version' => $phpVersion,
            'php_default' => $setAsDefault,
            'php_default_prompted' => $defaultPrompted,
            'php_extensions' => implode(',', $selectedExtensions),
        ];
    }

    //
    // HTTP Verification
    // ----

    /**
     * Verify demo site is responding with expected content.
     *
     * @return array{status: 'success'|'warning', message: string, lines: array<int, string>}
     */
    private function verifyInstallation(string $url, ?string $deployKey): array
    {
        $result = $this->http->verifyUrl($url);

        if (!$result['success']) {
            // Network errors return status_code: 0
            if (0 === $result['status_code']) {
                return [
                    'status' => 'warning',
                    'message' => "Could not connect to demo site: {$result['body']}",
                    'lines' => [],
                ];
            }

            // HTTP protocol errors (got response but wrong status code)
            return [
                'status' => 'warning',
                'message' => "Demo site returned HTTP {$result['status_code']} (expected 200)",
                'lines' => [],
            ];
        }

        if (!str_contains($result['body'], 'hello, world')) {
            return [
                'status' => 'warning',
                'message' => 'Demo site is responding but content verification failed',
                'lines' => [],
            ];
        }

        $nextSteps = [
            'Next steps:',
            '  • Caddy running at <fg=cyan>' . $url . '</>',
            '  • Run <fg=cyan>site:add</> to deploy your first application',
        ];

        if ($deployKey !== null) {
            $nextSteps[] = '  • Add this key to your Git provider (GitHub, GitLab, etc.) to enable deployments:';
            $nextSteps[] = '';
            $nextSteps[] = '<fg=cyan>' . $deployKey . '</>';
        }

        $nextSteps[] = '';

        return [
            'status' => 'success',
            'message' => 'Server installation completed successfully',
            'lines' => $nextSteps,
        ];
    }

}

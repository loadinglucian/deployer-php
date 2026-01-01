<?php

declare(strict_types=1);

namespace DeployerPHP\Console\Server;

use DeployerPHP\Contracts\BaseCommand;
use DeployerPHP\DTOs\ServerDTO;
use DeployerPHP\Exceptions\ValidationException;
use DeployerPHP\Traits\KeysTrait;
use DeployerPHP\Traits\PlaybooksTrait;
use DeployerPHP\Traits\ServersTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'server:install',
    description: 'Install the server so it can host PHP applications'
)]
class ServerInstallCommand extends BaseCommand
{
    use KeysTrait;
    use PlaybooksTrait;
    use ServersTrait;

    // ----
    // Configuration
    // ----

    protected function configure(): void
    {
        parent::configure();

        $this->addOption('server', null, InputOption::VALUE_REQUIRED, 'Server name');
        $this->addOption('generate-deploy-key', null, InputOption::VALUE_NONE, 'Use server-generated deploy key');
        $this->addOption('custom-deploy-key', null, InputOption::VALUE_REQUIRED, 'Path to custom deploy key (public key expected at same path + .pub)');
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

        $this->h1('Install Server');

        //
        // Select server
        // ----

        $server = $this->selectServerDeets();

        if (is_int($server) || null === $server->info) {
            return Command::FAILURE;
        }

        //
        // Prepare packages
        // ----

        $packageList = $this->executePlaybook(
            $server,
            'package-list',
            'Preparing packages...',
            [
                'DEPLOYER_GATHER_PHP' => 'true',
            ],
        );

        if (is_int($packageList)) {
            return $packageList;
        }

        //
        // Install base packages
        // ----

        $result = $this->executePlaybook(
            $server,
            'base-install',
            'Installing base packages...',
        );

        if (is_int($result)) {
            return $result;
        }

        //
        // Install PHP
        // ----

        $phpResult = $this->installPhp($server, $server->info, $packageList);

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
            'bun-install',
            'Installing Bun...',
        );

        if (is_int($bunResult)) {
            return $bunResult;
        }

        //
        // Setup deployer user
        // ----

        $deployKeyResult = $this->setupDeployerUser($input, $server);

        if (is_int($deployKeyResult)) {
            return $deployKeyResult;
        }

        /** @var array{deploy_key_path: string|null, deploy_public_key: string} $deployKeyResult */
        $deployKeyPath = $deployKeyResult['deploy_key_path'];
        $deployPublicKey = $deployKeyResult['deploy_public_key'];

        $this->yay('Server installation completed successfully');

        $this->ul([
            'Run <|cyan>site:create</> to create a new site',
            'View server and service info with <|cyan>server:info</>',
            'Add the following <|yellow>public key</> to your Git provider (GitHub, GitLab, etc.) to enable deployments:',
        ]);

        // Intentionally not using $this->out() here to make the key stand out from the rest of our output
        $this->io->write([
            '',
            '<fg=yellow>' . $deployPublicKey . '</>',
            '',
            '↑ IMPORTANT: Add this public key to your Git provider to enable access to your repositories.'
        ], true);

        //
        // Show command replay
        // ----

        $replayOptions = [
            'server' => $server->name,
            'php-version' => $phpVersion,
            'php-extensions' => $phpExtensions,
        ];

        if ($deployKeyPath !== null) {
            $replayOptions['custom-deploy-key'] = $deployKeyPath;
        } else {
            $replayOptions['generate-deploy-key'] = true;
        }

        if ($phpDefaultPrompted) {
            $replayOptions['php-default'] = $phpDefault;
        }

        $this->commandReplay('server:install', $replayOptions);

        return Command::SUCCESS;
    }

    //
    // Deployer User Setup
    // ----

    /**
     * Setup deployer user and SSH deploy key.
     *
     * Handles deploy key generation or custom key upload based on user input.
     * Custom keys always overwrite existing; auto-generated keys preserve existing.
     *
     * @return array{deploy_key_path: string|null, deploy_public_key: string}|int
     */
    private function setupDeployerUser(InputInterface $input, ServerDTO $server): array|int
    {
        //
        // Get deploy key configuration
        // ----

        /** @var bool $generateKey */
        $generateKey = $input->getOption('generate-deploy-key');
        /** @var string|null $customKeyPath */
        $customKeyPath = $input->getOption('custom-deploy-key');

        if ($generateKey && $customKeyPath !== null) {
            $this->nay('Cannot use both --generate-deploy-key and --custom-deploy-key');

            return Command::FAILURE;
        }

        //
        // Determine deploy key path
        // ----

        try {
            if ($generateKey) {
                $deployKeyPath = null;
            } elseif ($customKeyPath !== null) {
                // CLI option provided - validate via trait method
                $deployKeyPath = $this->promptDeployKeyPairPath();
            } else {
                // Interactive: ask user to choose
                $choice = $this->io->promptSelect(
                    label: 'Deploy key:',
                    options: [
                        'generate' => 'Use server-generated key pair',
                        'custom' => 'Use your own key pair',
                    ],
                    default: 'generate',
                    hint: 'Used to access your repositories'
                );

                $deployKeyPath = ($choice === 'generate')
                    ? null
                    : $this->promptDeployKeyPairPath();
            }
        } catch (ValidationException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        //
        // Prepare playbook variables
        // ----

        $playbookVars = [];

        if ($deployKeyPath !== null) {
            // Path already validated and expanded by promptDeployKeyPairPath()
            try {
                $privateKeyContent = $this->fs->readFile($deployKeyPath);
                $publicKeyContent = $this->fs->readFile($deployKeyPath . '.pub');
            } catch (\RuntimeException $e) {
                $this->nay($e->getMessage());

                return Command::FAILURE;
            }

            $playbookVars['DEPLOYER_KEY_PRIVATE'] = base64_encode($privateKeyContent);
            $playbookVars['DEPLOYER_KEY_PUBLIC'] = base64_encode($publicKeyContent);
        }

        //
        // Execute playbook
        // ----

        $deployerResult = $this->executePlaybook(
            $server,
            'user-install',
            'Setting up deployer user...',
            $playbookVars,
        );

        if (is_int($deployerResult)) {
            return $deployerResult;
        }

        /** @var string|null $deployPublicKey */
        $deployPublicKey = $deployerResult['deploy_public_key'] ?? null;

        if ($deployPublicKey === null) {
            $this->nay('Failed to retrieve deploy key');

            return Command::FAILURE;
        }

        return [
            'deploy_key_path' => $deployKeyPath,
            'deploy_public_key' => $deployPublicKey,
        ];
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

        /** @var array<string, mixed> $phpPackages */
        $phpPackages = $packageList['php'] ?? [];

        if ($phpPackages === []) {
            $this->nay('No PHP versions available in package list');

            return Command::FAILURE;
        }

        $phpVersions = array_filter(
            array_keys($phpPackages),
            fn ($v) => str_starts_with((string) $v, '8.')
        );
        rsort($phpVersions, SORT_NATURAL); // Newest first

        if ($phpVersions === []) {
            $this->nay('No PHP 8.x versions available in package list');

            return Command::FAILURE;
        }

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

        $defaultVersion = in_array('8.5', $phpVersions) ? '8.5' : $phpVersions[0];

        try {
            /** @var string $phpVersion */
            $phpVersion = $this->io->getValidatedOptionOrPrompt(
                'php-version',
                fn ($validate) => $this->io->promptSelect(
                    label: 'PHP version:',
                    options: $phpVersions,
                    default: $defaultVersion,
                    validate: $validate
                ),
                fn ($value) => $this->validatePhpVersionInput($value, $phpVersions)
            );
        } catch (ValidationException $e) {
            $this->nay($e->getMessage());

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

        if (is_array($versionData)) {
            /** @var array<int|string, string> $extensions */
            $extensions = $versionData['extensions'] ?? [];
            $availableExtensions = $extensions;
        }

        if (empty($availableExtensions)) {
            $this->nay("No extensions available for PHP {$phpVersion}");

            return Command::FAILURE;
        }

        // Filter defaults to only those available for this version
        $preSelected = array_values(array_intersect($defaultExtensions, $availableExtensions));

        try {
            $selectedExtensions = $this->io->getValidatedOptionOrPrompt(
                'php-extensions',
                fn ($validate) => $this->io->promptMultiselect(
                    label: 'Select PHP extensions:',
                    options: $availableExtensions,
                    default: $preSelected,
                    scroll: 15,
                    validate: $validate
                ),
                fn ($value) => $this->validatePhpExtensionsInput($value, $availableExtensions)
            );
        } catch (ValidationException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        // Handle both array (from prompt) and string (from CLI option)
        if (is_string($selectedExtensions)) {
            $selectedExtensions = array_filter(
                array_map(trim(...), explode(',', $selectedExtensions)),
                static fn (string $ext): bool => $ext !== ''
            );
        }

        /** @var array<int, string> $selectedExtensions */

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
                $setAsDefault = $this->io->getBooleanOptionOrPrompt(
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

        $result = $this->executePlaybook(
            $server,
            'php-install',
            "Installing PHP...",
            [
                'DEPLOYER_PHP_VERSION' => $phpVersion,
                'DEPLOYER_PHP_SET_DEFAULT' => $setAsDefault ? 'true' : 'false',
                'DEPLOYER_PHP_EXTENSIONS' => implode(',', $selectedExtensions),
            ],
        );

        if (is_int($result)) {
            return $result;
        }

        return [
            'status' => Command::SUCCESS,
            'php_version' => $phpVersion,
            'php_default' => $setAsDefault,
            'php_default_prompted' => $defaultPrompted,
            'php_extensions' => implode(',', $selectedExtensions),
        ];
    }

    // ----
    // Validation
    // ----

    /**
     * Validate PHP version selection.
     *
     * @param array<int, string> $availableVersions Available PHP versions
     *
     * @return string|null Error message if invalid, null if valid
     */
    private function validatePhpVersionInput(mixed $value, array $availableVersions): ?string
    {
        if (! is_string($value)) {
            return 'PHP version must be a string';
        }

        if (! in_array($value, $availableVersions, true)) {
            return "PHP version {$value} is not available. Available versions: " . implode(', ', $availableVersions);
        }

        return null;
    }

    /**
     * Validate PHP extensions selection.
     *
     * @param array<int|string, string> $availableExtensions Available PHP extensions
     *
     * @return string|null Error message if invalid, null if valid
     */
    private function validatePhpExtensionsInput(mixed $value, array $availableExtensions): ?string
    {
        // CLI provides comma-separated string, prompt provides array
        $extensions = $value;
        if (is_string($extensions)) {
            $extensions = array_filter(
                array_map(trim(...), explode(',', $extensions)),
                static fn (string $ext): bool => $ext !== ''
            );
        }

        if (! is_array($extensions)) {
            return 'Invalid PHP extensions selection';
        }

        if ($extensions === []) {
            return 'At least one extension must be selected';
        }

        $unknownExtensions = array_diff($extensions, $availableExtensions);
        if ([] !== $unknownExtensions) {
            return 'Unknown extension(s): ' . implode(', ', $unknownExtensions);
        }

        return null;
    }
}

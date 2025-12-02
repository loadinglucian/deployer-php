<?php

declare(strict_types=1);

namespace PHPDeployer\Console\Server;

use PHPDeployer\Contracts\BaseCommand;
use PHPDeployer\DTOs\ServerDTO;
use PHPDeployer\Traits\KeysTrait;
use PHPDeployer\Traits\PlaybooksTrait;
use PHPDeployer\Traits\ServersTrait;
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

        $server = $this->selectServer();

        if (is_int($server) || $server->info === null) {
            return Command::FAILURE;
        }

        [
            'distro' => $distro,
            'permissions' => $permissions,
        ] = $server->info;

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
            [
                'DEPLOYER_DISTRO' => $distro,
                'DEPLOYER_PERMS' => $permissions,
            ],
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
            [
                'DEPLOYER_PERMS' => $permissions,
            ],
        );

        if (is_int($bunResult)) {
            return $bunResult;
        }

        //
        // Setup deployer user
        // ----

        $deployKeyResult = $this->setupDeployerUser($input, $server, $distro, $permissions);

        if (is_int($deployKeyResult)) {
            return $deployKeyResult;
        }

        /** @var array{deploy_key_path: string|null, deploy_public_key: string} $deployKeyResult */
        $deployKeyPath = $deployKeyResult['deploy_key_path'];
        $deployPublicKey = $deployKeyResult['deploy_public_key'];

        $this->yay('Server installation completed successfully');

        $this->ul([
            'Run <|cyan>site:add</> to add a new site',
            'Add the following <|yellow>public key</> to your Git provider (GitHub, GitLab, etc.) to enable deployments:',
        ]);

        // Intentionally not using $this->out() here to make the key stand out from the rest of the command output
        $this->io->write([
            '',
            '<fg=yellow>' . $deployPublicKey . '</>',
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
    private function setupDeployerUser(InputInterface $input, ServerDTO $server, string $distro, string $permissions): array|int
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

        if ($generateKey) {
            $deployKeyPath = null;
        } elseif ($customKeyPath !== null) {
            $deployKeyPath = $customKeyPath;
        } else {
            $choice = $this->io->promptSelect(
                label: 'Deploy key:',
                options: [
                    'generate' => 'Use server-generated key pair',
                    'custom' => 'Use your own key pair',
                ],
                default: 'generate'
            );

            if ($choice === 'generate') {
                $deployKeyPath = null;
            } else {
                $deployKeyPath = $this->io->promptText(
                    label: 'Path to private key:',
                    placeholder: '~/.ssh/deploy_key',
                    required: true,
                    hint: 'Public key expected at same path + .pub'
                );
            }
        }

        //
        // Prepare playbook variables
        // ----

        $playbookVars = [
            'DEPLOYER_DISTRO' => $distro,
            'DEPLOYER_PERMS' => $permissions,
            'DEPLOYER_SERVER_NAME' => $server->name,
        ];

        if ($deployKeyPath !== null && $deployKeyPath !== '') {
            $validationError = $this->validateDeployKeyPairInput($deployKeyPath);

            if ($validationError !== null) {
                $this->nay($validationError);

                return Command::FAILURE;
            }

            $expandedPath = $this->fs->expandPath($deployKeyPath);
            $privateKeyContent = $this->fs->readFile($expandedPath);
            $publicKeyContent = $this->fs->readFile($expandedPath . '.pub');

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
            $this->nay(
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
            $this->nay('Invalid PHP extensions selection');

            return Command::FAILURE;
        }

        // Validate all selected extensions exist for this PHP version
        $unknownExtensions = array_diff($selectedExtensions, $availableExtensions);
        if ($unknownExtensions !== []) {
            $this->nay(
                'Unknown PHP extensions for PHP ' . $phpVersion . ': ' . implode(', ', $unknownExtensions)
            );

            return Command::FAILURE;
        }

        if ($selectedExtensions === []) {
            $this->nay('At least one extension must be selected');

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
            'php-install',
            "Installing PHP...",
            [
                'DEPLOYER_DISTRO' => $distro,
                'DEPLOYER_PERMS' => $permissions,
                'DEPLOYER_PHP_VERSION' => $phpVersion,
                'DEPLOYER_PHP_SET_DEFAULT' => $setAsDefault ? 'true' : 'false',
                'DEPLOYER_PHP_EXTENSIONS' => implode(',', $selectedExtensions),
            ],
        );

        if (is_int($result)) {
            $this->nay('PHP installation failed');

            return Command::FAILURE;
        }

        return [
            'status' => Command::SUCCESS,
            'php_version' => $phpVersion,
            'php_default' => $setAsDefault,
            'php_default_prompted' => $defaultPrompted,
            'php_extensions' => implode(',', $selectedExtensions),
        ];
    }
}

<?php

declare(strict_types=1);

namespace DeployerPHP\Console\Mariadb;

use DeployerPHP\Contracts\BaseCommand;
use DeployerPHP\Traits\PathOperationsTrait;
use DeployerPHP\Traits\PlaybooksTrait;
use DeployerPHP\Traits\ServersTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'mariadb:install',
    description: 'Install MariaDB server on a server'
)]
class MariadbInstallCommand extends BaseCommand
{
    use PathOperationsTrait;
    use PlaybooksTrait;
    use ServersTrait;

    // ----
    // Configuration
    // ----

    protected function configure(): void
    {
        parent::configure();

        $this->addOption('server', null, InputOption::VALUE_REQUIRED, 'Server name');
        $this->addOption('display-credentials', null, InputOption::VALUE_NONE, 'Display credentials on screen');
        $this->addOption('save-credentials', null, InputOption::VALUE_REQUIRED, 'Save credentials to file (0600 permissions)');
    }

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->h1('Install MariaDB');

        //
        // Select server
        // ----

        $server = $this->selectServerDeets();

        if (is_int($server) || null === $server->info) {
            return Command::FAILURE;
        }

        //
        // Credential output preference (collected upfront)
        // ----

        /** @var bool $displayCredentials */
        $displayCredentials = $input->getOption('display-credentials');
        /** @var string|null $saveCredentialsPath */
        $saveCredentialsPath = $input->getOption('save-credentials');

        if ($displayCredentials && '' !== $saveCredentialsPath && null !== $saveCredentialsPath) {
            $this->nay('Cannot use both --display-credentials and --save-credentials');

            return Command::FAILURE;
        }

        if (!$displayCredentials && (null === $saveCredentialsPath || '' === $saveCredentialsPath)) {
            /** @var string $choice */
            $choice = $this->io->promptSelect(
                label: 'How would you like to receive the credentials?',
                options: [
                    'display' => 'Display on screen',
                    'save' => 'Save to file',
                ],
                default: 'display'
            );

            if ('display' === $choice) {
                $displayCredentials = true;
            } else {
                $saveCredentialsPath = $this->io->promptText(
                    label: 'Save credentials to:',
                    placeholder: './.env.mariadb',
                    required: true,
                    validate: fn ($value) => $this->validatePathInput($value)
                );
            }
        }

        //
        // Install MariaDB
        // ----

        $result = $this->executePlaybook(
            $server,
            'mariadb-install',
            'Installing MariaDB...',
        );

        if (is_int($result)) {
            return $result;
        }

        //
        // Output credentials (fresh install only)
        // ----

        if (!($result['already_installed'] ?? false)) {
            $rootPass = $result['root_pass'] ?? null;
            $deployerPass = $result['deployer_pass'] ?? null;

            if (null === $rootPass || '' === $rootPass || null === $deployerPass || '' === $deployerPass) {
                $this->nay('MariaDB installation completed but credentials were not returned');

                return Command::FAILURE;
            }

            /** @var string $rootPass */
            /** @var string $deployerPass */
            /** @var string $deployerUser */
            $deployerUser = $result['deployer_user'] ?? 'deployer';
            /** @var string $deployerDatabase */
            $deployerDatabase = $result['deployer_database'] ?? 'deployer';

            $this->yay('MariaDB installation completed successfully');

            if ($displayCredentials) {
                $this->displayCredentialsOnScreen($rootPass, $deployerUser, $deployerPass, $deployerDatabase);
            } else {
                /** @var string $saveCredentialsPath */
                try {
                    $this->saveCredentialsToFile(
                        $saveCredentialsPath,
                        $server->name,
                        $rootPass,
                        $deployerUser,
                        $deployerPass,
                        $deployerDatabase
                    );
                } catch (\RuntimeException $e) {
                    $this->nay($e->getMessage());
                    $this->info('Credentials will be displayed on screen instead:');
                    $this->displayCredentialsOnScreen($rootPass, $deployerUser, $deployerPass, $deployerDatabase);
                }
            }
        } else {
            $this->info('MariaDB is already installed on this server');
        }

        //
        // Show command replay
        // ----

        $replayOptions = ['server' => $server->name];

        if (!($result['already_installed'] ?? false)) {
            if (null !== $saveCredentialsPath) {
                $replayOptions['save-credentials'] = $saveCredentialsPath;
            } else {
                $replayOptions['display-credentials'] = true;
            }
        }

        $this->commandReplay('mariadb:install', $replayOptions);

        return Command::SUCCESS;
    }

    // ----
    // Helpers
    // ----

    /**
     * Display credentials on the console screen.
     */
    protected function displayCredentialsOnScreen(
        string $rootPass,
        string $deployerUser,
        string $deployerPass,
        string $deployerDatabase
    ): void {
        $this->out([
            '',
            'Root Credentials (admin access):',
            "  Password: {$rootPass}",
            '',
            'Application Credentials:',
            "  Database: {$deployerDatabase}",
            "  Username: {$deployerUser}",
            "  Password: {$deployerPass}",
            '',
            'Connection string:',
            "  mysql://{$deployerUser}:{$deployerPass}@localhost/{$deployerDatabase}",
            '',
        ]);

        $this->warn('Save these credentials somewhere safe. They will not be displayed again.');
    }

    /**
     * Save credentials to a secure file with 0600 permissions (appends if file exists).
     */
    protected function saveCredentialsToFile(
        string $filePath,
        string $serverName,
        string $rootPass,
        string $deployerUser,
        string $deployerPass,
        string $deployerDatabase
    ): void {
        $content = <<<CREDS
            # MariaDB Credentials for {$serverName}
            # Generated: {$this->now()}
            # WARNING: Keep this file secure!

            ## Root Credentials (admin access)
            MARIADB_ROOT_PASSWORD={$rootPass}

            ## Application Credentials
            MARIADB_DATABASE={$deployerDatabase}
            MARIADB_USER={$deployerUser}
            MARIADB_PASSWORD={$deployerPass}

            ## Connection String
            DATABASE_URL=mysql://{$deployerUser}:{$deployerPass}@localhost/{$deployerDatabase}
            CREDS;

        $fileExists = $this->fs->exists($filePath);

        $oldUmask = umask(0077);
        $this->fs->appendFile($filePath, ($fileExists ? "\n\n" : '') . $content);
        umask($oldUmask);
        $this->fs->chmod($filePath, 0600);

        $action = $fileExists ? 'appended to' : 'saved to';
        $this->yay("Credentials {$action}: {$filePath}");
        $this->info('File permissions set to 0600 (owner read/write only)');
    }

    /**
     * Get current timestamp for credential file.
     */
    protected function now(): string
    {
        return date('Y-m-d H:i:s T');
    }
}

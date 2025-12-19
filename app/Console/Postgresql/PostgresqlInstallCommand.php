<?php

declare(strict_types=1);

namespace Deployer\Console\Postgresql;

use Deployer\Contracts\BaseCommand;
use Deployer\Traits\PlaybooksTrait;
use Deployer\Traits\ServersTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'postgresql:install',
    description: 'Install PostgreSQL server on a server'
)]
class PostgresqlInstallCommand extends BaseCommand
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
        $this->addOption('display-credentials', null, InputOption::VALUE_NONE, 'Display credentials on screen');
        $this->addOption('save-credentials', null, InputOption::VALUE_REQUIRED, 'Save credentials to file (0600 permissions)');
    }

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->h1('Install PostgreSQL');

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

        if ($displayCredentials && null !== $saveCredentialsPath) {
            $this->nay('Cannot use both --display-credentials and --save-credentials');

            return Command::FAILURE;
        }

        if (!$displayCredentials && null === $saveCredentialsPath) {
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
                    placeholder: './.env.postgresql',
                    required: true
                );
            }
        }

        //
        // Install PostgreSQL
        // ----

        $result = $this->executePlaybook(
            $server,
            'postgresql-install',
            'Installing PostgreSQL...',
        );

        if (is_int($result)) {
            return $result;
        }

        //
        // Output credentials (fresh install only)
        // ----

        if (!($result['already_installed'] ?? false)) {
            $postgresPass = $result['postgres_pass'] ?? null;
            $deployerPass = $result['deployer_pass'] ?? null;

            if (null === $postgresPass || '' === $postgresPass || null === $deployerPass || '' === $deployerPass) {
                $this->nay('PostgreSQL installation completed but credentials were not returned');

                return Command::FAILURE;
            }

            /** @var string $postgresPass */
            /** @var string $deployerPass */
            /** @var string $deployerUser */
            $deployerUser = $result['deployer_user'] ?? 'deployer';
            /** @var string $deployerDatabase */
            $deployerDatabase = $result['deployer_database'] ?? 'deployer';

            $this->yay('PostgreSQL installation completed successfully');

            if ($displayCredentials) {
                $this->displayCredentialsOnScreen($postgresPass, $deployerUser, $deployerPass, $deployerDatabase);
            } else {
                try {
                    $this->saveCredentialsToFile(
                        $saveCredentialsPath,
                        $server->name,
                        $postgresPass,
                        $deployerUser,
                        $deployerPass,
                        $deployerDatabase
                    );
                } catch (\RuntimeException $e) {
                    $this->nay($e->getMessage());
                    $this->info('Credentials will be displayed on screen instead:');
                    $this->displayCredentialsOnScreen($postgresPass, $deployerUser, $deployerPass, $deployerDatabase);
                }
            }
        } else {
            $this->info('PostgreSQL is already installed on this server');
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

        $this->commandReplay('postgresql:install', $replayOptions);

        return Command::SUCCESS;
    }

    // ----
    // Helpers
    // ----

    /**
     * Display credentials on the console screen.
     */
    protected function displayCredentialsOnScreen(
        string $postgresPass,
        string $deployerUser,
        string $deployerPass,
        string $deployerDatabase
    ): void {
        $this->out([
            '',
            'Postgres Credentials (admin access):',
            "  Password: {$postgresPass}",
            '',
            'Application Credentials:',
            "  Database: {$deployerDatabase}",
            "  Username: {$deployerUser}",
            "  Password: {$deployerPass}",
            '',
            'Connection string:',
            "  postgresql://{$deployerUser}:{$deployerPass}@localhost/{$deployerDatabase}",
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
        string $postgresPass,
        string $deployerUser,
        string $deployerPass,
        string $deployerDatabase
    ): void {
        $content = <<<CREDS
            # PostgreSQL Credentials for {$serverName}
            # Generated: {$this->now()}
            # WARNING: Keep this file secure!

            ## Postgres Credentials (admin access)
            POSTGRES_PASSWORD={$postgresPass}

            ## Application Credentials
            POSTGRES_DATABASE={$deployerDatabase}
            POSTGRES_USER={$deployerUser}
            POSTGRES_USER_PASSWORD={$deployerPass}

            ## Connection String
            DATABASE_URL=postgresql://{$deployerUser}:{$deployerPass}@localhost/{$deployerDatabase}
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

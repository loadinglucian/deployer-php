<?php

declare(strict_types=1);

namespace Deployer\Console\Redis;

use Deployer\Contracts\BaseCommand;
use Deployer\Traits\PathOperationsTrait;
use Deployer\Traits\PlaybooksTrait;
use Deployer\Traits\ServersTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'redis:install',
    description: 'Install Redis server on a server'
)]
class RedisInstallCommand extends BaseCommand
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

        $this->h1('Install Redis');

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
                    placeholder: './.env.redis',
                    required: true,
                    validate: fn ($value) => $this->validatePathInput($value)
                );
            }
        }

        //
        // Install Redis
        // ----

        $result = $this->executePlaybook(
            $server,
            'redis-install',
            'Installing Redis...',
        );

        if (is_int($result)) {
            return $result;
        }

        //
        // Output credentials (fresh install only)
        // ----

        if (!($result['already_installed'] ?? false)) {
            $redisPass = $result['redis_pass'] ?? null;

            if (null === $redisPass || '' === $redisPass) {
                $this->nay('Redis installation completed but credentials were not returned');

                return Command::FAILURE;
            }

            /** @var string $redisPass */
            $this->yay('Redis installation completed successfully');

            if ($displayCredentials) {
                $this->displayCredentialsOnScreen($redisPass);
            } else {
                /** @var string $saveCredentialsPath */
                try {
                    $this->saveCredentialsToFile($saveCredentialsPath, $server->name, $redisPass);
                } catch (\RuntimeException $e) {
                    $this->nay($e->getMessage());
                    $this->info('Credentials will be displayed on screen instead:');
                    $saveCredentialsPath = null;
                    $this->displayCredentialsOnScreen($redisPass);
                }
            }
        } else {
            $this->info('Redis is already installed on this server');
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

        $this->commandReplay('redis:install', $replayOptions);

        return Command::SUCCESS;
    }

    // ----
    // Helpers
    // ----

    /**
     * Display credentials on the console screen.
     */
    protected function displayCredentialsOnScreen(string $redisPass): void
    {
        $this->out([
            '',
            'Redis Password:',
            "  {$redisPass}",
            '',
            'Connection string:',
            "  redis://:{$redisPass}@localhost:6379",
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
        string $redisPass
    ): void {
        $content = <<<CREDS
            # Redis Credentials for {$serverName}
            # Generated: {$this->now()}
            # WARNING: Keep this file secure!

            REDIS_PASSWORD={$redisPass}

            ## Connection String
            REDIS_URL=redis://:{$redisPass}@localhost:6379
            CREDS;

        $fileExists = $this->fs->exists($filePath);

        $oldUmask = umask(0077);
        try {
            $this->fs->appendFile($filePath, ($fileExists ? "\n\n" : '') . $content);
        } finally {
            umask($oldUmask);
        }
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

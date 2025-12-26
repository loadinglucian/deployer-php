<?php

declare(strict_types=1);

namespace DeployerPHP\Console\Server;

use DeployerPHP\Contracts\BaseCommand;
use DeployerPHP\Exceptions\SshTimeoutException;
use DeployerPHP\Exceptions\ValidationException;
use DeployerPHP\Traits\PlaybooksTrait;
use DeployerPHP\Traits\ServersTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'server:run',
    description: 'Run arbitrary command on a server'
)]
class ServerRunCommand extends BaseCommand
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
        $this->addOption('command', null, InputOption::VALUE_REQUIRED, 'Command to execute');
    }

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->h1('Run Command on Server');

        //
        // Select server
        // ----

        $server = $this->selectServerDeets();

        if (is_int($server)) {
            return $server;
        }

        //
        // Gather command to execute
        // ----

        try {
            /** @var string $command */
            $command = $this->io->getValidatedOptionOrPrompt(
                'command',
                fn ($validate) => $this->io->promptText(
                    label: 'Command to execute:',
                    placeholder: 'ls -la',
                    required: true,
                    validate: $validate
                ),
                fn ($value) => $this->validateCommandInput($value)
            );
        } catch (ValidationException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        //
        // Execute command with real-time output streaming
        // ----

        $this->out('$> ' . $command);

        try {
            $result = $this->ssh->executeCommand(
                $server,
                $command,
                fn (string $chunk) => $this->io->write($chunk)
            );

            $this->out('───');

            if (0 !== $result['exit_code']) {
                throw new \RuntimeException("Command failed with exit code {$result['exit_code']}");
            }
        } catch (SshTimeoutException $e) {
            $this->nay($e->getMessage());
            $this->warn('The command took longer than expected. Either:');
            $this->ul([
                'The command requires more time to complete',
                'Server has a slow network connection',
                'Server is under heavy load',
            ]);
            $this->warn('You can try:');
            $this->ul([
                'Running the command again',
                'Checking server load with <|cyan>server:info</>',
            ]);

            return Command::FAILURE;
        } catch (\RuntimeException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        //
        // Show command replay
        // ----

        $this->commandReplay('server:run', [
            'server' => $server->name,
            'command' => $command,
        ]);

        return Command::SUCCESS;
    }

    // ----
    // Validation
    // ----

    /**
     * Validate command input.
     *
     * @return string|null Error message if invalid, null if valid
     */
    private function validateCommandInput(mixed $value): ?string
    {
        if (! is_string($value)) {
            return 'Command must be a string';
        }

        if ('' === trim($value)) {
            return 'Command cannot be empty';
        }

        return null;
    }
}

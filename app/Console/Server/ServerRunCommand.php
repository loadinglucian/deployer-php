<?php

declare(strict_types=1);

namespace Bigpixelrocket\DeployerPHP\Console\Server;

use Bigpixelrocket\DeployerPHP\Contracts\BaseCommand;
use Bigpixelrocket\DeployerPHP\Traits\ServersTrait;
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

        $this->heading('Run Command on Server');

        //
        // Select server & display details
        // ----

        $server = $this->selectServer();

        if (is_int($server)) {
            return $server;
        }

        $this->displayServerDeets($server);

        //
        // Get command to execute
        // ----

        $command = $this->io->getOptionOrPrompt(
            'command',
            fn () => $this->io->promptText(
                label: 'Command to execute:',
                placeholder: 'ls -la',
                required: true
            )
        );

        if (!is_string($command) || trim($command) === '') {
            $this->nay('Command cannot be empty');

            return Command::FAILURE;
        }

        //
        // Execute command with real-time output streaming
        // ----

        $this->io->writeln([
            "<fg=cyan>Executing command...</>",
            '',
        ]);

        try {
            $result = $this->ssh->executeCommand(
                $server,
                $command,
                fn (string $chunk) => $this->io->write($chunk)
            );

            $this->io->writeln('');

            if ($result['exit_code'] !== 0) {
                $this->nay("Command failed with exit code {$result['exit_code']}");

                return Command::FAILURE;
            }

            $this->yay('Command executed successfully');
        } catch (\RuntimeException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        //
        // Show command replay
        // ----

        $this->showCommandReplay('server:run', [
            'server' => $server->name,
            'command' => $command,
        ]);

        return Command::SUCCESS;
    }
}

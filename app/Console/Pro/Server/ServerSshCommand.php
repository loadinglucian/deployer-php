<?php

declare(strict_types=1);

namespace DeployerPHP\Console\Pro\Server;

use DeployerPHP\Contracts\BaseCommand;
use DeployerPHP\Traits\PlaybooksTrait;
use DeployerPHP\Traits\ServersTrait;
use DeployerPHP\Traits\SshTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'pro:server:ssh|server:ssh',
    description: 'SSH into a server'
)]
class ServerSshCommand extends BaseCommand
{
    use PlaybooksTrait;
    use ServersTrait;
    use SshTrait;

    // ----
    // Configuration
    // ----

    protected function configure(): void
    {
        parent::configure();

        $this->addOption('server', null, InputOption::VALUE_REQUIRED, 'Server name');
    }

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->h1('SSH into Server');

        //
        // Select server
        // ----

        $server = $this->selectServerDeets();

        if (is_int($server)) {
            return $server;
        }

        //
        // Build SSH command
        // ----

        $sshBinary = $this->findSshBinary();

        if (null === $sshBinary) {
            $this->nay('Could not find ssh in PATH');

            return Command::FAILURE;
        }

        $sshArgs = [
            '-o', 'StrictHostKeyChecking=accept-new',
            '-p', (string) $server->port,
            '-t',
        ];

        if (null !== $server->privateKeyPath) {
            if (!file_exists($server->privateKeyPath) || !is_readable($server->privateKeyPath)) {
                $this->nay("SSH key not found or not readable: {$server->privateKeyPath}");

                return Command::FAILURE;
            }

            $sshArgs[] = '-i';
            $sshArgs[] = $server->privateKeyPath;
        }

        $sshArgs[] = "{$server->username}@{$server->host}";

        //
        // Replace PHP process with SSH
        // ----

        if (! function_exists('pcntl_exec')) {
            $this->nay('The pcntl extension is required for SSH. Enable it in your php.ini');

            return Command::FAILURE;
        }

        $this->out('');
        pcntl_exec($sshBinary, $sshArgs);

        // Only reached if pcntl_exec fails
        $error = pcntl_get_last_error();
        $this->nay('Failed to execute SSH: ' . pcntl_strerror($error));

        return Command::FAILURE;
    }
}

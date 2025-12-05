<?php

declare(strict_types=1);

namespace Deployer\Console\Server;

use Deployer\Contracts\BaseCommand;
use Deployer\Traits\ServersTrait;
use Deployer\Traits\SshTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'server:ssh',
    description: 'SSH into a server'
)]
class ServerSshCommand extends BaseCommand
{
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
        // Select server & validate connection
        // ----

        $server = $this->selectServer();

        if (is_int($server)) {
            return $server;
        }

        //
        // Resolve SSH binary
        // ----

        $sshBinary = $this->findSshBinary();

        if (null === $sshBinary) {
            $this->nay('SSH binary not found in PATH');

            return Command::FAILURE;
        }

        //
        // Build SSH command arguments
        // ----

        $sshArgs = [
            '-o', 'StrictHostKeyChecking=accept-new',
            '-p', (string) $server->port,
        ];

        if (null !== $server->privateKeyPath) {
            $sshArgs[] = '-i';
            $sshArgs[] = $server->privateKeyPath;
        }

        $sshArgs[] = "{$server->username}@{$server->host}";

        //
        // Replace PHP process with SSH
        // ----

        $this->io->write("\n");
        pcntl_exec($sshBinary, $sshArgs);

        // Only reached if pcntl_exec fails
        $this->nay('Failed to execute SSH');

        return Command::FAILURE;
    }
}

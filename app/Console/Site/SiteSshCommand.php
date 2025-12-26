<?php

declare(strict_types=1);

namespace DeployerPHP\Console\Site;

use DeployerPHP\Contracts\BaseCommand;
use DeployerPHP\Traits\PlaybooksTrait;
use DeployerPHP\Traits\ServersTrait;
use DeployerPHP\Traits\SitesTrait;
use DeployerPHP\Traits\SshTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'site:ssh',
    description: 'SSH into a site directory'
)]
class SiteSshCommand extends BaseCommand
{
    use PlaybooksTrait;
    use ServersTrait;
    use SitesTrait;
    use SshTrait;

    // ----
    // Configuration
    // ----

    protected function configure(): void
    {
        parent::configure();

        $this->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Site domain');
    }

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->h1('SSH into Site');

        //
        // Select site
        // ----

        $site = $this->selectSiteDeets();

        if (is_int($site)) {
            return $site;
        }

        //
        // Get server for site
        // ----

        $server = $this->getServerForSite($site);

        if (is_int($server)) {
            return $server;
        }

        //
        // Validate site is added on server
        // ----

        $validationResult = $this->ensureSiteExists($server, $site);

        if (is_int($validationResult)) {
            return $validationResult;
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

        $siteRoot = $this->getSiteRootPath($site);

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
        $sshArgs[] = 'cd ' . escapeshellarg($siteRoot) . ' && exec $SHELL -l';

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

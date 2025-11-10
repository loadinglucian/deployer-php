<?php

declare(strict_types=1);

namespace Bigpixelrocket\DeployerPHP\Console\Server;

use Bigpixelrocket\DeployerPHP\Contracts\BaseCommand;
use Bigpixelrocket\DeployerPHP\Traits\PlaybooksTrait;
use Bigpixelrocket\DeployerPHP\Traits\ServersTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'server:optimize',
    description: 'Optimize PHP-FPM and OPcache settings based on server hardware'
)]
class ServerOptimizeCommand extends BaseCommand
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
    }

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->heading('Optimize Server');

        //
        // Select server & display details
        // ----

        $server = $this->selectServer();

        if (is_int($server)) {
            return $server;
        }

        $this->displayServerDeets($server);

        //
        // Get server info (includes hardware detection)
        // ----

        $info = $this->getServerInfo($server);

        if (is_int($info)) {
            return $info;
        }

        // Extract hardware info
        if (!isset($info['hardware']) || !is_array($info['hardware'])) {
            $this->io->error('Server hardware information not available. Run server:install first.');

            return Command::FAILURE;
        }

        $hardware = $info['hardware'];
        $cpuCores = $hardware['cpu_cores'] ?? '1';
        $ramMb = $hardware['ram_mb'] ?? '512';
        $diskType = $hardware['disk_type'] ?? 'hdd';

        /** @var string $cpuCores */
        /** @var string $ramMb */
        /** @var string $diskType */

        $permissions = $info['permissions'] ?? 'none';
        /** @var string $permissions */

        //
        // Display hardware summary
        // ----

        $this->io->writeln([
            '',
            '<fg=cyan>Hardware Configuration:</>',
            "  CPU Cores: <fg=yellow>{$cpuCores}</>",
            "  RAM: <fg=yellow>{$ramMb}MB</>",
            "  Disk: <fg=yellow>{$diskType}</>",
            '',
        ]);

        //
        // Confirm optimization
        // ----

        $confirmed = $this->io->promptConfirm(
            'Apply hardware-optimized settings to PHP-FPM and OPcache?',
            default: true
        );

        if (!$confirmed) {
            $this->io->info('Optimization cancelled');

            return Command::SUCCESS;
        }

        //
        // Execute optimization playbook
        // ----

        $result = $this->executePlaybook(
            $server,
            'server-optimize',
            'Optimizing server...',
            [
                'DEPLOYER_PERMS' => $permissions,
                'DEPLOYER_CPU_CORES' => $cpuCores,
                'DEPLOYER_RAM_MB' => $ramMb,
                'DEPLOYER_DISK_TYPE' => $diskType,
            ],
            true
        );

        if (is_int($result)) {
            $this->io->error('Server optimization failed');

            return $result;
        }

        $this->yay('Server optimization completed successfully');

        //
        // Display applied settings
        // ----

        if (isset($result['php_fpm_settings']) && is_string($result['php_fpm_settings'])) {
            $this->io->writeln([
                '',
                '<fg=cyan>Applied Settings:</>',
                "  PHP-FPM: <fg=yellow>{$result['php_fpm_settings']}</>",
            ]);
        }

        if (isset($result['opcache_settings']) && is_string($result['opcache_settings'])) {
            $this->io->writeln([
                "  OPcache: <fg=yellow>{$result['opcache_settings']}</>",
                '',
            ]);
        }

        //
        // Show command replay
        // ----

        $this->showCommandReplay('server:optimize', [
            'server' => $server->name,
        ]);

        return Command::SUCCESS;
    }
}

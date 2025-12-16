<?php

declare(strict_types=1);

namespace Deployer\Console\Mysql;

use Deployer\Contracts\BaseCommand;
use Deployer\Traits\PlaybooksTrait;
use Deployer\Traits\ServersTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'mysql:install',
    description: 'Install MySQL server on a server'
)]
class MysqlInstallCommand extends BaseCommand
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

        $this->h1('Install MySQL');

        //
        // Select server
        // ----

        $server = $this->selectServer();

        if (is_int($server) || null === $server->info) {
            return Command::FAILURE;
        }

        [
            'distro' => $distro,
            'permissions' => $permissions,
        ] = $server->info;

        /** @var string $distro */
        /** @var string $permissions */

        //
        // Install MySQL
        // ----

        $result = $this->executePlaybook(
            $server,
            'mysql-install',
            'Installing MySQL...',
            [
                'DEPLOYER_DISTRO' => $distro,
                'DEPLOYER_PERMS' => $permissions,
            ],
        );

        if (is_int($result)) {
            return $result;
        }

        //
        // Handle already installed
        // ----

        if (true === ($result['already_installed'] ?? false)) {
            $this->warn('MySQL is already installed on this server');

            return Command::SUCCESS;
        }

        //
        // Validate and display credentials
        // ----

        $rootPass = $result['root_pass'] ?? null;
        $deployerPass = $result['deployer_pass'] ?? null;

        if (null === $rootPass || '' === $rootPass || null === $deployerPass || '' === $deployerPass) {
            $this->nay('MySQL installation completed but credentials were not returned');

            return Command::FAILURE;
        }

        /** @var string $rootPass */
        /** @var string $deployerPass */
        /** @var string $deployerUser */
        $deployerUser = $result['deployer_user'] ?? 'deployer';
        /** @var string $deployerDatabase */
        $deployerDatabase = $result['deployer_database'] ?? 'deployer';

        $this->yay('MySQL installation completed successfully');

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
        ]);

        //
        // Show command replay
        // ----

        $this->commandReplay('mysql:install', [
            'server' => $server->name,
        ]);

        return Command::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace DeployerPHP\Console\Php;

use DeployerPHP\Contracts\BaseCommand;
use DeployerPHP\Traits\PhpTrait;
use DeployerPHP\Traits\PlaybooksTrait;
use DeployerPHP\Traits\ServersTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'php:restart',
    description: 'Restart PHP-FPM service(s)'
)]
class PhpRestartCommand extends BaseCommand
{
    use PhpTrait;
    use PlaybooksTrait;
    use ServersTrait;

    // ----
    // Configuration
    // ----

    protected function configure(): void
    {
        parent::configure();

        $this->addOption('server', null, InputOption::VALUE_REQUIRED, 'Server name');
        $this->addOption('version', null, InputOption::VALUE_REQUIRED, 'PHP version (e.g., 8.4). If omitted, affects all installed versions');
    }

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->h1('Restart PHP-FPM Service');

        //
        // Select server
        // ----

        $server = $this->selectServerDeets();

        if (is_int($server) || null === $server->info) {
            return Command::FAILURE;
        }

        //
        // Get installed PHP versions
        // ----

        $installedVersions = $this->getInstalledPhpVersions($server->info);

        if ([] === $installedVersions) {
            $this->nay('No PHP versions installed on this server');

            return Command::FAILURE;
        }

        //
        // Determine target versions
        // ----

        /** @var string|null $version */
        $version = $input->getOption('version');

        if (null !== $version) {
            $error = $this->validatePhpVersionInput($version, $installedVersions);

            if (null !== $error) {
                $this->nay($error);

                return Command::FAILURE;
            }

            $targetVersions = [$version];
        } else {
            $targetVersions = $installedVersions;
        }

        //
        // Restart PHP-FPM service(s)
        // ----

        $versionList = implode(',', $targetVersions);
        $versionDisplay = 1 === count($targetVersions)
            ? "PHP {$targetVersions[0]}-FPM"
            : 'PHP-FPM ('.implode(', ', $targetVersions).')';

        $result = $this->executePlaybookSilently(
            $server,
            'php-service',
            "Restarting {$versionDisplay}...",
            [
                'DEPLOYER_ACTION' => 'restart',
                'DEPLOYER_PHP_VERSIONS' => $versionList,
            ],
        );

        if (is_int($result)) {
            $this->nay("Failed to restart {$versionDisplay}");

            return Command::FAILURE;
        }

        $this->yay("{$versionDisplay} restarted");

        //
        // Show command replay
        // ----

        $this->commandReplay('php:restart', [
            'server' => $server->name,
            'version' => $version,
        ]);

        return Command::SUCCESS;
    }
}

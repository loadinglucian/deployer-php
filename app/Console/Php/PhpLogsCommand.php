<?php

declare(strict_types=1);

namespace DeployerPHP\Console\Php;

use DeployerPHP\Contracts\BaseCommand;
use DeployerPHP\Exceptions\ValidationException;
use DeployerPHP\Traits\LogsTrait;
use DeployerPHP\Traits\PhpTrait;
use DeployerPHP\Traits\PlaybooksTrait;
use DeployerPHP\Traits\ServersTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'php:logs',
    description: 'View PHP-FPM service logs'
)]
class PhpLogsCommand extends BaseCommand
{
    use LogsTrait;
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
        $this->addOption('version', null, InputOption::VALUE_REQUIRED, 'PHP version (e.g., 8.4). If omitted, shows all installed versions');

        // No default value: omitting --lines displays a prompt with its own default
        $this->addOption('lines', 'n', InputOption::VALUE_REQUIRED, 'Number of lines to retrieve');
    }

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->h1('PHP-FPM Logs');

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
        // Get number of lines
        // ----

        try {
            /** @var string $lines */
            $lines = $this->io->getValidatedOptionOrPrompt(
                'lines',
                fn ($validate) => $this->io->promptText(
                    label: 'Number of lines:',
                    default: '50',
                    validate: $validate
                ),
                fn ($value) => $this->validateLineCount($value)
            );
        } catch (ValidationException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        $lineCount = (int) $lines;

        //
        // Retrieve PHP-FPM logs for each version
        // ----

        foreach ($targetVersions as $phpVersion) {
            $this->retrieveFileLogs(
                $server,
                "PHP {$phpVersion}-FPM",
                "/var/log/php{$phpVersion}-fpm.log",
                $lineCount
            );
        }

        //
        // Show command replay
        // ----

        $this->commandReplay('php:logs', [
            'server' => $server->name,
            'version' => $version,
            'lines' => $lines,
        ]);

        return Command::SUCCESS;
    }
}

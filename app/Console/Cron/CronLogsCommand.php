<?php

declare(strict_types=1);

namespace DeployerPHP\Console\Cron;

use DeployerPHP\Contracts\BaseCommand;
use DeployerPHP\Exceptions\ValidationException;
use DeployerPHP\Traits\LogsTrait;
use DeployerPHP\Traits\PlaybooksTrait;
use DeployerPHP\Traits\ServersTrait;
use DeployerPHP\Traits\SitesTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'cron:logs',
    description: 'View cron service and script logs'
)]
class CronLogsCommand extends BaseCommand
{
    use LogsTrait;
    use PlaybooksTrait;
    use ServersTrait;
    use SitesTrait;

    // ----
    // Configuration
    // ----

    protected function configure(): void
    {
        parent::configure();

        $this->addOption('server', null, InputOption::VALUE_REQUIRED, 'Server name');
        $this->addOption('lines', 'n', InputOption::VALUE_REQUIRED, 'Number of lines to retrieve', '50');
    }

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->h1('Cron Logs');

        //
        // Select server
        // ----

        $server = $this->selectServerDeets();

        if (is_int($server) || null === $server->info) {
            return Command::FAILURE;
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
        // Retrieve cron service logs
        // ----

        $this->retrieveJournalLogs($server, 'Cron', 'cron', $lineCount);

        //
        // Retrieve per-site cron script logs
        // ----

        foreach ($this->sites->findByServer($server->name) as $site) {
            foreach ($site->crons as $cron) {
                $scriptBase = pathinfo($cron->script, PATHINFO_FILENAME);
                $this->retrieveFileLogs(
                    $server,
                    "Cron: {$site->domain}/{$cron->script}",
                    "/var/log/cron/{$site->domain}-{$scriptBase}.log",
                    $lineCount
                );
            }
        }

        //
        // Show command replay
        // ----

        $this->commandReplay('cron:logs', [
            'server' => $server->name,
            'lines' => $lines,
        ]);

        return Command::SUCCESS;
    }
}

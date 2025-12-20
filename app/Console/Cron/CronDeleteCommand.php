<?php

declare(strict_types=1);

namespace Deployer\Console\Cron;

use Deployer\Contracts\BaseCommand;
use Deployer\Traits\CronsTrait;
use Deployer\Traits\PlaybooksTrait;
use Deployer\Traits\ServersTrait;
use Deployer\Traits\SitesTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'cron:delete',
    description: 'Delete a cron job from a site'
)]
class CronDeleteCommand extends BaseCommand
{
    use CronsTrait;
    use PlaybooksTrait;
    use ServersTrait;
    use SitesTrait;

    // ----
    // Configuration
    // ----

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Site domain')
            ->addOption('script', null, InputOption::VALUE_REQUIRED, 'Cron script path within .deployer/crons/')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip typing the script name to confirm')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip Yes/No confirmation prompt');
    }

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->h1('Delete Cron Job');

        //
        // Select site and cron
        // ----

        $site = $this->selectSiteDeets();

        if (is_int($site)) {
            return $site;
        }

        $this->io->write("\n");

        $cron = $this->selectCron($site);

        if (is_int($cron)) {
            return $cron;
        }

        $this->displayCronDeets($cron);

        //
        // Confirm deletion with extra safety
        // ----

        /** @var bool $forceSkip */
        $forceSkip = $input->getOption('force');

        if (!$forceSkip) {
            $this->io->write("\n");

            $typedScript = $this->io->promptText(
                label: "Type the cron script name '{$cron->script}' to confirm deletion:",
                required: true
            );

            if ($typedScript !== $cron->script) {
                $this->nay('Cron script name does not match. Deletion cancelled.');

                return Command::FAILURE;
            }
        }

        $confirmed = $this->io->getBooleanOptionOrPrompt(
            'yes',
            fn (): bool => $this->io->promptConfirm(
                label: 'Are you absolutely sure?',
                default: false
            )
        );

        if (!$confirmed) {
            $this->warn('Cancelled deleting cron job');

            return Command::SUCCESS;
        }

        //
        // Delete cron from inventory
        // ----

        try {
            $this->sites->deleteCron($site->domain, $cron->script);
        } catch (\RuntimeException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        $this->yay("Cron '{$cron->script}' removed from inventory");
        $this->info('Run <fg=cyan>cron:sync</> to apply changes to the server');

        //
        // Show command replay
        // ----

        $this->commandReplay('cron:delete', [
            'domain' => $site->domain,
            'script' => $cron->script,
            'force' => true,
            'yes' => true,
        ]);

        return Command::SUCCESS;
    }
}

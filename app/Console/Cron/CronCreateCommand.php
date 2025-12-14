<?php

declare(strict_types=1);

namespace Deployer\Console\Cron;

use Deployer\Contracts\BaseCommand;
use Deployer\DTOs\CronDTO;
use Deployer\Traits\CronsTrait;
use Deployer\Traits\SitesTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'cron:create',
    description: 'Create a cron job for a site'
)]
class CronCreateCommand extends BaseCommand
{
    use CronsTrait;
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
            ->addOption('schedule', null, InputOption::VALUE_REQUIRED, 'Cron schedule expression (e.g., "*/5 * * * *")');
    }

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->h1('Create Cron Job');

        //
        // Select site
        // ----

        $site = $this->selectSite();

        if (is_int($site)) {
            return $site;
        }

        $this->displaySiteDeets($site);

        //
        // Ensure site has repo/branch configured
        // ----

        if (null === $site->repo || null === $site->branch) {
            $this->warn('Site has not been deployed yet');
            $this->info('Run <fg=cyan>site:deploy</> to deploy the site first');

            return Command::FAILURE;
        }

        //
        // Scan available cron scripts from remote repository
        // ----

        try {
            $availableScripts = $this->listRemoteSiteDirectory($site, '.deployer/crons');
        } catch (\RuntimeException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        if ([] === $availableScripts) {
            $this->warn('No cron scripts found in repository');
            $this->info("Run <fg=cyan>scaffold:crons</> to create some");

            return Command::FAILURE;
        }

        //
        // Select cron script
        // ----

        $this->io->write("\n");

        /** @var string $script */
        $script = (string) $this->io->getOptionOrPrompt(
            'script',
            fn () => $this->io->promptSelect(
                label: 'Select cron script:',
                options: $availableScripts,
                scroll: 10
            )
        );

        // Validate CLI option is in available scripts
        if (! in_array($script, $availableScripts, true)) {
            $this->nay("Cron script not found: .deployer/crons/{$script}");

            return Command::FAILURE;
        }

        // Check for duplicate in site's crons
        $duplicateError = $this->validateCronScript($script, $site->domain);
        if (null !== $duplicateError) {
            $this->nay($duplicateError);

            return Command::FAILURE;
        }

        //
        // Prompt for schedule
        // ----

        /** @var string|null $schedule */
        $schedule = $this->io->getValidatedOptionOrPrompt(
            'schedule',
            fn ($validate) => $this->io->promptText(
                label: 'Cron schedule (minute hour day month weekday):',
                placeholder: '*/5 * * * *',
                required: true,
                validate: $validate
            ),
            fn ($value) => $this->validateScheduleInput($value)
        );

        if (null === $schedule) {
            return Command::FAILURE;
        }

        //
        // Display cron details
        // ----

        $cron = new CronDTO(
            script: $script,
            schedule: $schedule,
        );

        $this->displayCronDeets($cron);

        //
        // Add cron to inventory
        // ----

        try {
            $this->sites->addCron($site->domain, $cron);
        } catch (\RuntimeException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        $this->yay("Cron '{$script}' added to inventory");
        $this->info('Run <fg=cyan>cron:sync</> to apply changes to the server');

        //
        // Show command replay
        // ----

        $this->commandReplay('cron:create', [
            'domain' => $site->domain,
            'script' => $script,
            'schedule' => $schedule,
        ]);

        return Command::SUCCESS;
    }

    // ----
    // Validation
    // ----

    /**
     * Validate cron script is not a duplicate for this site.
     *
     * @return string|null Error message if invalid, null if valid
     */
    private function validateCronScript(string $script, string $domain): ?string
    {
        // Check for duplicate in site's crons
        $site = $this->sites->findByDomain($domain);
        if (null !== $site) {
            foreach ($site->crons as $existingCron) {
                if ($existingCron->script === $script) {
                    return "Cron '{$script}' is already configured for '{$domain}'";
                }
            }
        }

        return null;
    }
}

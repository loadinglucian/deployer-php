<?php

declare(strict_types=1);

namespace DeployerPHP\Console\Cron;

use DeployerPHP\Contracts\BaseCommand;
use DeployerPHP\DTOs\CronDTO;
use DeployerPHP\Exceptions\ValidationException;
use DeployerPHP\Traits\CronsTrait;
use DeployerPHP\Traits\PlaybooksTrait;
use DeployerPHP\Traits\ServersTrait;
use DeployerPHP\Traits\SitesTrait;
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

        $site = $this->selectSiteDeets();

        if (is_int($site)) {
            return $site;
        }

        $deployedResult = $this->ensureSiteDeployed($site);

        if (is_int($deployedResult)) {
            return $deployedResult;
        }

        $availableScripts = $this->getAvailableScripts(
            $site,
            '.deployer/crons',
            'cron',
            'scaffold:crons'
        );

        if (is_int($availableScripts)) {
            return $availableScripts;
        }

        //
        // Gather cron details
        // ----

        $this->io->write("\n");

        $cronDeets = $this->gatherCronDeets($site->domain, $availableScripts);

        if (is_int($cronDeets)) {
            return $cronDeets;
        }

        $cron = new CronDTO(
            script: $cronDeets['script'],
            schedule: $cronDeets['schedule'],
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

        $this->yay("Cron '{$cronDeets['script']}' added to inventory");
        $this->info('Run <fg=cyan>cron:sync</> to apply changes to the server');

        //
        // Show command replay
        // ----

        $this->commandReplay('cron:create', [
            'domain' => $site->domain,
            'script' => $cronDeets['script'],
            'schedule' => $cronDeets['schedule'],
        ]);

        return Command::SUCCESS;
    }

    // ----
    // Helpers
    // ----

    /**
     * Gather cron details from user input or CLI options.
     *
     * @param array<int, string> $availableScripts
     *
     * @return array{script: string, schedule: string}|int
     */
    protected function gatherCronDeets(string $domain, array $availableScripts): array|int
    {
        try {
            /** @var string $script */
            $script = $this->io->getValidatedOptionOrPrompt(
                'script',
                fn ($validate) => $this->io->promptSelect(
                    label: 'Select cron script:',
                    options: $availableScripts,
                    scroll: 10,
                    validate: $validate
                ),
                fn ($value) => $this->validateCronScriptInput($value, $availableScripts)
            );

            // Check for duplicate in site's crons
            $duplicateError = $this->validateCronScript($script, $domain);
            if (null !== $duplicateError) {
                $this->nay($duplicateError);

                return Command::FAILURE;
            }

            /** @var string $schedule */
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
        } catch (ValidationException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        return [
            'script' => $script,
            'schedule' => $schedule,
        ];
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

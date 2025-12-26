<?php

declare(strict_types=1);

namespace DeployerPHP\Console\Site;

use DeployerPHP\Contracts\BaseCommand;
use DeployerPHP\DTOs\ServerDTO;
use DeployerPHP\DTOs\SiteDTO;
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
    name: 'site:logs',
    description: 'View site logs (access, crons, and supervisors)'
)]
class SiteLogsCommand extends BaseCommand
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

        $this->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Domain name');
        $this->addOption('lines', 'n', InputOption::VALUE_REQUIRED, 'Number of lines to retrieve');
        $this->addOption('service', 's', InputOption::VALUE_REQUIRED, 'Service(s) to view (comma-separated: access, crons, supervisors)');
    }

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->h1('Site Logs');

        //
        // Select site and server
        // ----

        $siteServer = $this->selectSiteDeetsWithServer();

        if (is_int($siteServer)) {
            return $siteServer;
        }

        $site = $siteServer->site;
        $server = $siteServer->server;

        //
        // Build available options
        // ----

        $options = $this->buildServiceOptions($site);

        //
        // Get user input
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

        //
        // Determine services to display
        // ----

        /** @var string|null $serviceOption */
        $serviceOption = $input->getOption('service');

        if (null !== $serviceOption) {
            // CLI option provided - validate it
            $error = $this->validateServiceFilter($serviceOption, $options);

            if (null !== $error) {
                $this->nay($error);

                return Command::FAILURE;
            }

            /** @var list<string> $serviceKeys */
            $serviceKeys = array_values(array_filter(array_map(trim(...), explode(',', $serviceOption))));
        } else {
            // No option - show all available services
            /** @var list<string> $serviceKeys */
            $serviceKeys = array_keys($options);
        }

        //
        // Display logs
        // ----

        $this->displayLogs($server, $site, $serviceKeys, (int) $lines);

        //
        // Command replay
        // ----

        $this->commandReplay('site:logs', [
            'domain' => $site->domain,
            'lines' => $lines,
            'service' => implode(',', $serviceKeys),
        ]);

        return Command::SUCCESS;
    }

    // ----
    // Helpers
    // ----

    /**
     * Build available service options based on site configuration.
     *
     * @return array<string, string>
     */
    protected function buildServiceOptions(SiteDTO $site): array
    {
        $options = ['access' => 'Access Logs'];

        if ([] !== $site->crons) {
            $options['crons'] = 'Cron Logs';
        }

        if ([] !== $site->supervisors) {
            $options['supervisors'] = 'Supervisor Logs';
        }

        return $options;
    }

    /**
     * Display logs for selected services.
     *
     * @param list<string> $services
     */
    protected function displayLogs(ServerDTO $server, SiteDTO $site, array $services, int $lines): void
    {
        $domain = $site->domain;

        foreach ($services as $key) {
            match ($key) {
                'access' => $this->retrieveFileLogs(
                    $server,
                    "Access: {$domain}",
                    "/var/log/caddy/{$domain}-access.log",
                    $lines
                ),
                'crons' => $this->displayCronLogs($server, $site, $lines),
                'supervisors' => $this->displaySupervisorLogs($server, $site, $lines),
                default => $this->warn("Unknown service: {$key}"),
            };
        }
    }

    /**
     * Display logs for all cron scripts.
     */
    protected function displayCronLogs(ServerDTO $server, SiteDTO $site, int $lines): void
    {
        foreach ($site->crons as $cron) {
            $scriptBase = pathinfo($cron->script, PATHINFO_FILENAME);
            $this->retrieveFileLogs(
                $server,
                "Cron: {$cron->script}",
                "/var/log/cron/{$site->domain}-{$scriptBase}.log",
                $lines
            );
        }
    }

    /**
     * Display logs for all supervisor programs.
     */
    protected function displaySupervisorLogs(ServerDTO $server, SiteDTO $site, int $lines): void
    {
        foreach ($site->supervisors as $supervisor) {
            $this->retrieveFileLogs(
                $server,
                "Supervisor: {$supervisor->program}",
                "/var/log/supervisor/{$site->domain}-{$supervisor->program}.log",
                $lines
            );
        }
    }

    // ----
    // Validation
    // ----

    /**
     * Validate service filter input.
     *
     * @param array<string, string> $allowedOptions
     */
    protected function validateServiceFilter(mixed $value, array $allowedOptions): ?string
    {
        if (!is_string($value)) {
            return 'Service filter must be a string';
        }

        $services = array_filter(array_map(trim(...), explode(',', $value)));

        if ([] === $services) {
            return 'At least one service must be specified';
        }

        $invalid = array_diff($services, array_keys($allowedOptions));

        if ([] !== $invalid) {
            return sprintf('Invalid service(s): %s. Available: %s', implode(', ', $invalid), implode(', ', array_keys($allowedOptions)));
        }

        return null;
    }
}

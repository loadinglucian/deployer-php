<?php

declare(strict_types=1);

namespace Deployer\Console\Supervisor;

use Deployer\Contracts\BaseCommand;
use Deployer\DTOs\SupervisorDTO;
use Deployer\Traits\PlaybooksTrait;
use Deployer\Traits\ServersTrait;
use Deployer\Traits\SitesTrait;
use Deployer\Traits\SupervisorsTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'supervisor:restart',
    description: 'Restart supervisor programs for a site'
)]
class SupervisorRestartCommand extends BaseCommand
{
    use PlaybooksTrait;
    use ServersTrait;
    use SitesTrait;
    use SupervisorsTrait;

    // ----
    // Configuration
    // ----

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Site domain')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Immediate restart (stop then start, no graceful shutdown)');
    }

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->h1('Restart Supervisor Programs');

        //
        // Select site
        // ----

        $site = $this->selectSite();

        if (is_int($site)) {
            return $site;
        }

        $this->displaySiteDeets($site);

        //
        // Ensure supervisors exist
        // ----

        $supervisors = $this->ensureSupervisorsAvailable($site);

        if (is_int($supervisors)) {
            return $supervisors;
        }

        //
        // Get server for site
        // ----

        $server = $this->getServerForSite($site);

        if (is_int($server)) {
            return $server;
        }

        //
        // Get server info (verifies SSH and validates distro & permissions)
        // ----

        $server = $this->serverInfo($server);

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
        // Determine restart mode
        // ----

        /** @var bool $force */
        $force = (bool) $input->getOption('force');

        if ($force) {
            $this->warn('Using force restart (immediate stop, no graceful shutdown)');
        }

        //
        // Restart supervisors
        // ----

        $result = $this->executePlaybookSilently(
            $server,
            'supervisor-restart',
            'Restarting supervisor programs...',
            [
                'DEPLOYER_DISTRO' => $distro,
                'DEPLOYER_PERMS' => $permissions,
                'DEPLOYER_SITE_DOMAIN' => $site->domain,
                'DEPLOYER_SUPERVISORS' => array_map(
                    fn (SupervisorDTO $supervisor) => [
                        'program' => $supervisor->program,
                    ],
                    $site->supervisors
                ),
                'DEPLOYER_FORCE' => $force ? 'true' : 'false',
            ]
        );

        if (is_int($result)) {
            $this->nay('Failed to restart supervisor programs');

            return Command::FAILURE;
        }

        //
        // Display results
        // ----

        /** @var int $restarted */
        $restarted = $result['programs_restarted'] ?? 0;

        /** @var int $failed */
        $failed = $result['programs_failed'] ?? 0;

        if ($failed > 0) {
            $this->warn("{$restarted} program(s) restarted, {$failed} failed");
        } else {
            $this->yay("{$restarted} supervisor program(s) restarted");
        }

        //
        // Show command replay
        // ----

        $replayOptions = ['domain' => $site->domain];

        if ($force) {
            $replayOptions['force'] = true;
        }

        $this->commandReplay('supervisor:restart', $replayOptions);

        return Command::SUCCESS;
    }
}

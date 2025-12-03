<?php

declare(strict_types=1);

namespace Deployer\Console\Site;

use Deployer\Contracts\BaseCommand;
use Deployer\Traits\SitesTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'site:list',
    description: 'List sites in the inventory'
)]
class SiteListCommand extends BaseCommand
{
    use SitesTrait;

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->h1('List Sites');

        //
        // Get all sites
        // ----

        $allSites = $this->ensureSitesAvailable();

        if (is_int($allSites)) {
            return $allSites;
        }

        //
        // Display sites
        // ----

        foreach ($allSites as $site) {
            $this->displaySiteDeets($site);
        }

        return Command::SUCCESS;
    }

}

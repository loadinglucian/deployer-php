<?php

declare(strict_types=1);

namespace Bigpixelrocket\DeployerPHP\Console\Site;

use Bigpixelrocket\DeployerPHP\Contracts\BaseCommand;
use Bigpixelrocket\DeployerPHP\Traits\SiteHelpersTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * List all sites in the inventory.
 */
#[AsCommand(name: 'site:list', description: 'List sites in the inventory')]
class SiteListCommand extends BaseCommand
{
    use SiteHelpersTrait;

    //
    // Execution
    // -------------------------------------------------------------------------------

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->io->hr();
        $this->io->h1('List Sites');

        //
        // Get all sites

        $allSites = $this->ensureSitesAvailable();

        if (is_int($allSites)) {
            return $allSites;
        }

        //
        // Display sites

        foreach ($allSites as $count => $site) {
            $this->displaySiteDeets($site);

            if ($count < count($allSites) - 1) {
                $this->io->writeln([
                        '  ───',
                        '',
                    ]);
            }
        }

        return Command::SUCCESS;
    }

}

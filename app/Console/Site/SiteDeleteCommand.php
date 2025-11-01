<?php

declare(strict_types=1);

namespace Bigpixelrocket\DeployerPHP\Console\Site;

use Bigpixelrocket\DeployerPHP\Contracts\BaseCommand;
use Bigpixelrocket\DeployerPHP\Traits\SiteHelpersTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Delete a site from the inventory.
 */
#[AsCommand(name: 'site:delete', description: 'Delete a site from the inventory')]
class SiteDeleteCommand extends BaseCommand
{
    use SiteHelpersTrait;

    //
    // Configuration
    // -------------------------------------------------------------------------------

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addOption('site', null, InputOption::VALUE_REQUIRED, 'Site domain')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Skip typing site domain (use with caution)')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }

    //
    // Execution
    // -------------------------------------------------------------------------------

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->io->hr();
        $this->io->h1('Delete Site');

        //
        // Select site

        $site = $this->selectSite();

        if (!$site instanceof \Bigpixelrocket\DeployerPHP\DTOs\SiteDTO) {
            return $site;
        }

        $this->io->hr();

        $this->displaySiteDeets($site);
        $this->io->writeln('');

        //
        // Confirm deletion with extra safety

        /** @var bool $forceSkip */
        $forceSkip = $input->getOption('force') ?? false;

        if (!$forceSkip) {
            $typedDomain = $this->io->promptText(
                label: "Type the site domain '{$site->domain}' to confirm deletion:",
                required: true
            );

            if ($typedDomain !== $site->domain) {
                $this->io->error('Site domain does not match. Deletion cancelled.');
                $this->io->writeln('');

                return Command::FAILURE;
            }
        }

        /** @var bool $confirmed */
        $confirmed = $this->io->getOptionOrPrompt(
            'yes',
            fn (): bool => $this->io->promptConfirm(
                label: 'Are you absolutely sure?',
                default: false
            )
        );

        if (!$confirmed) {
            $this->io->warning('Cancelled deleting site');
            $this->io->writeln('');

            return Command::SUCCESS;
        }

        //
        // Delete site

        $this->sites->delete($site->domain);

        $this->io->success("Site '{$site->domain}' deleted successfully");
        $this->io->writeln('');

        //
        // Show command hint

        $this->io->showCommandHint('site:delete', [
            'site' => $site->domain,
            'yes' => $confirmed,
            'force' => true,
        ]);

        return Command::SUCCESS;
    }
}

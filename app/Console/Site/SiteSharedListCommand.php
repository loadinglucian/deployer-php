<?php

declare(strict_types=1);

namespace DeployerPHP\Console\Site;

use DeployerPHP\Contracts\BaseCommand;
use DeployerPHP\Traits\PlaybooksTrait;
use DeployerPHP\Traits\ServersTrait;
use DeployerPHP\Traits\SitesTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'site:shared:list',
    description: 'List shared files and folders for a site'
)]
class SiteSharedListCommand extends BaseCommand
{
    use PlaybooksTrait;
    use ServersTrait;
    use SitesTrait;

    // ----
    // Configuration
    // ----

    protected function configure(): void
    {
        parent::configure();

        $this->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Site domain');
    }

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->h1('Shared Files');

        //
        // Select site and server
        // ----

        $result = $this->selectSiteDeetsWithServer();

        if (is_int($result)) {
            return $result;
        }

        $site = $result->site;
        $server = $result->server;

        $validationResult = $this->ensureSiteExists($server, $site);

        if (is_int($validationResult)) {
            return $validationResult;
        }

        //
        // List shared directory
        // ----

        $sharedPath = $this->buildSharedPath($site);

        try {
            /** @var string $listing */
            $listing = $this->io->promptSpin(
                function () use ($server, $sharedPath): string {
                    $dirCheck = $this->ssh->executeCommand(
                        $server,
                        sprintf('test -d %s', escapeshellarg($sharedPath))
                    );

                    if (0 !== $dirCheck['exit_code']) {
                        throw new \RuntimeException("Shared directory not found or inaccessible: {$sharedPath}");
                    }

                    $result = $this->ssh->executeCommand(
                        $server,
                        sprintf(
                            'cd %1$s && (tree -a --noreport 2>/dev/null || find . -not -name "." | sort)',
                            escapeshellarg($sharedPath)
                        )
                    );

                    if (0 !== $result['exit_code']) {
                        throw new \RuntimeException(
                            trim((string) $result['output']) ?: "Failed to list shared directory: {$sharedPath}"
                        );
                    }

                    $output = trim((string) $result['output']);

                    return '.' === $output ? '' : $output;
                },
                'Listing shared files...'
            );
        } catch (\RuntimeException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        //
        // Display results
        // ----

        if ('' === $listing) {
            $this->info('Shared directory is empty');
        } else {
            $this->out($listing);
        }

        //
        // Show command replay
        // ----

        $this->commandReplay([
            'domain' => $site->domain,
        ]);

        return Command::SUCCESS;
    }
}

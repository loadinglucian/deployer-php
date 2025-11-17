<?php

declare(strict_types=1);

namespace Bigpixelrocket\DeployerPHP\Console\Site;

use Bigpixelrocket\DeployerPHP\Contracts\BaseCommand;
use Bigpixelrocket\DeployerPHP\DTOs\ServerDTO;
use Bigpixelrocket\DeployerPHP\Traits\PlaybooksTrait;
use Bigpixelrocket\DeployerPHP\Traits\ServersTrait;
use Bigpixelrocket\DeployerPHP\Traits\SiteSharedPathsTrait;
use Bigpixelrocket\DeployerPHP\Traits\SitesTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'site:shared:pull',
    description: 'Download a file from a site\'s shared directory'
)]
class SiteSharedPullCommand extends BaseCommand
{
    use PlaybooksTrait;
    use ServersTrait;
    use SiteSharedPathsTrait;
    use SitesTrait;

    // ----
    // Configuration
    // ----

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Site domain')
            ->addOption('remote', null, InputOption::VALUE_REQUIRED, 'Remote filename (relative to shared/)')
            ->addOption('local', null, InputOption::VALUE_REQUIRED, 'Local destination file path');
    }

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->heading('Download Shared File');

        //
        // Select site
        // ----

        $site = $this->selectSite();

        if (is_int($site)) {
            return $site;
        }

        $this->displaySiteDeets($site);

        //
        // Get server for site
        // ----

        $server = $this->getServerForSite($site);

        if (is_int($server)) {
            return $server;
        }

        $this->displayServerDeets($server);

        //
        // Get server info (verifies SSH connection and validates distribution & permissions)
        // ----

        $info = $this->serverInfo($server);

        if (is_int($info)) {
            return $info;
        }

        //
        // Resolve paths
        // ----

        $remoteRelative = $this->resolveRemotePath();

        if ($remoteRelative === null) {
            return Command::FAILURE;
        }

        $remotePath = $this->buildSharedPath($site, $remoteRelative);

        //
        // Verify remote file exists
        // ----

        try {
            if (! $this->remoteFileExists($server, $remotePath)) {
                $this->nay("Remote file not found: {$remoteRelative}");

                return Command::FAILURE;
            }
        } catch (\RuntimeException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        $localPath = $this->resolveLocalPath($remoteRelative);

        if ($localPath === null) {
            return Command::FAILURE;
        }

        //
        // Check local file overwrite
        // ----

        if ($this->fs->exists($localPath)) {
            /** @var bool $overwrite */
            $overwrite = $this->io->promptConfirm(
                label: "Local file {$localPath} exists. Overwrite?",
                default: false
            );

            if (! $overwrite) {
                $this->io->warning('Download cancelled.');

                return Command::SUCCESS;
            }
        }

        //
        // Download file
        // ----

        $this->io->info("Downloading <fg=cyan>{$remotePath}</> to <fg=cyan>{$localPath}</>");
        $this->io->writeln('');

        try {
            $this->ssh->downloadFile($server, $remotePath, $localPath);
        } catch (\RuntimeException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        $this->yay('Shared file downloaded');

        //
        // Show command replay
        // ----

        $this->showCommandReplay('site:shared:pull', [
            'domain' => $site->domain,
            'remote' => $remoteRelative,
            'local' => $localPath,
        ]);

        return Command::SUCCESS;
    }

    // ----
    // Helpers
    // ----

    private function resolveRemotePath(): ?string
    {
        /** @var string|null $remoteInput */
        $remoteInput = $this->io->getOptionOrPrompt(
            'remote',
            fn (): string => $this->io->promptText(
                label: 'Remote filename (relative to shared/):',
                placeholder: '.env',
                required: true
            )
        );

        $normalized = $this->normalizeRelativePath($remoteInput ?? '');

        if ($normalized === null) {
            return null;
        }

        return $normalized;
    }

    private function resolveLocalPath(string $remoteRelative): ?string
    {
        $default = basename($remoteRelative) ?: $remoteRelative;

        /** @var string $localInput */
        $localInput = $this->io->getOptionOrPrompt(
            'local',
            fn (): string => $this->io->promptText(
                label: 'Local destination path:',
                default: $default,
                required: true
            )
        );

        try {
            return $this->fs->expandPath($localInput);
        } catch (\RuntimeException $e) {
            $this->nay($e->getMessage());

            return null;
        }
    }

    private function remoteFileExists(ServerDTO $server, string $remotePath): bool
    {
        $result = $this->ssh->executeCommand(
            $server,
            sprintf('test -f %s', escapeshellarg($remotePath))
        );

        if ($result['exit_code'] === 0) {
            return true;
        }

        if ($result['exit_code'] === 1) {
            return false;
        }

        $output = trim((string) $result['output']);
        $message = $output === '' ? "Failed checking remote file: {$remotePath}" : $output;

        throw new \RuntimeException($message);
    }
}

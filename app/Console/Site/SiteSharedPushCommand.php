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
    name: 'site:shared:push',
    description: 'Upload a file into a site\'s shared directory'
)]
class SiteSharedPushCommand extends BaseCommand
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
            ->addOption('local', null, InputOption::VALUE_REQUIRED, 'Local file path to upload')
            ->addOption('remote', null, InputOption::VALUE_REQUIRED, 'Remote filename (relative to shared/)');
    }

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->heading('Upload Shared File');

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

        $localPath = $this->resolveLocalPath();

        if ($localPath === null) {
            return Command::FAILURE;
        }

        $remoteRelative = $this->resolveRemotePath($localPath);
        if ($remoteRelative === null) {
            return Command::FAILURE;
        }

        $remotePath = $this->buildSharedPath($site, $remoteRelative);
        $remoteDir = dirname($remotePath);

        //
        // Upload file
        // ----

        $this->io->info("Uploading <fg=cyan>{$localPath}</> to <fg=cyan>{$remotePath}</>");
        $this->io->writeln('');

        try {
            $this->runRemoteCommand($server, sprintf('mkdir -p %s', escapeshellarg($remoteDir)));
            $this->ssh->uploadFile($server, $localPath, $remotePath);
            $this->runRemoteCommand($server, sprintf('chmod 640 %s', escapeshellarg($remotePath)));

            if ($server->username !== 'deployer') {
                $this->runRemoteCommand(
                    $server,
                    sprintf('chown deployer:deployer %s', escapeshellarg($remotePath))
                );
            }
        } catch (\RuntimeException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        $this->yay('Shared file uploaded');

        //
        // Show command replay
        // ----

        $this->showCommandReplay('site:shared:push', [
            'domain' => $site->domain,
            'local' => $localPath,
            'remote' => $remoteRelative,
        ]);

        return Command::SUCCESS;
    }

    // ----
    // Helpers
    // ----

    private function resolveLocalPath(): ?string
    {
        /** @var string $localInput */
        $localInput = $this->io->getOptionOrPrompt(
            'local',
            fn (): string => $this->io->promptText(
                label: 'Local file path:',
                placeholder: '.env.production',
                required: true
            )
        );

        try {
            $expanded = $this->fs->expandPath($localInput);
        } catch (\RuntimeException $e) {
            $this->nay($e->getMessage());

            return null;
        }

        if (! $this->fs->exists($expanded) || ! is_file($expanded)) {
            $this->nay("Local file not found: {$expanded}");

            return null;
        }

        return $expanded;
    }

    private function resolveRemotePath(string $localPath): ?string
    {
        $defaultName = basename($localPath);

        /** @var string|null $remoteInput */
        $remoteInput = $this->io->getOptionOrPrompt(
            'remote',
            fn (): string => $this->io->promptText(
                label: 'Remote filename (relative to shared/):',
                placeholder: $defaultName === '' ? '.env' : $defaultName,
                default: $defaultName === '' ? '.env' : $defaultName,
                required: true
            )
        );

        $normalized = $this->normalizeRelativePath($remoteInput ?? '');

        if ($normalized === null) {
            return null;
        }

        return $normalized;
    }

    private function runRemoteCommand(ServerDTO $server, string $command): void
    {
        $result = $this->ssh->executeCommand($server, $command);
        if ($result['exit_code'] !== 0) {
            $output = trim((string) $result['output']);
            $message = $output === '' ? "Remote command failed: {$command}" : $output;

            throw new \RuntimeException($message);
        }
    }
}

<?php

declare(strict_types=1);

namespace DeployerPHP\Console\Site;

use DeployerPHP\Contracts\BaseCommand;
use DeployerPHP\DTOs\ServerDTO;
use DeployerPHP\Exceptions\ValidationException;
use DeployerPHP\Traits\PathOperationsTrait;
use DeployerPHP\Traits\PlaybooksTrait;
use DeployerPHP\Traits\ServersTrait;
use DeployerPHP\Traits\SitesTrait;
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
    use PathOperationsTrait;
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
            ->addOption('remote', null, InputOption::VALUE_REQUIRED, 'Remote filename (relative to shared/)')
            ->addOption('local', null, InputOption::VALUE_REQUIRED, 'Local destination file path')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip overwrite confirmation');
    }

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->h1('Download Shared File');

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
        // Resolve paths
        // ----

        try {
            $remoteRelative = $this->resolveRemotePath();
            $remotePath = $this->buildSharedPath($site, $remoteRelative);

            //
            // Verify remote file exists
            // ----

            if (! $this->remoteFileExists($server, $remotePath)) {
                $this->nay("Remote file not found: {$remoteRelative}");

                return Command::FAILURE;
            }

            $localPath = $this->resolveLocalPath($remoteRelative);
        } catch (ValidationException|\RuntimeException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        //
        // Check local file overwrite
        // ----

        if ($this->fs->exists($localPath)) {
            $overwrite = $this->io->getBooleanOptionOrPrompt(
                'yes',
                fn (): bool => $this->io->promptConfirm(
                    label: "Local file {$localPath} exists. Overwrite?",
                    default: false
                )
            );

            if (! $overwrite) {
                $this->warn('Download cancelled.');

                return Command::SUCCESS;
            }
        }

        //
        // Download file
        // ----

        try {
            $this->io->promptSpin(
                function () use ($server, $remotePath, $localPath): void {
                    $this->ssh->downloadFile($server, $remotePath, $localPath);
                },
                'Downloading file...'
            );
        } catch (\RuntimeException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        $this->yay('Shared file downloaded');

        //
        // Show command replay
        // ----

        $this->commandReplay('site:shared:pull', [
            'domain' => $site->domain,
            'remote' => $remoteRelative,
            'local' => $localPath,
            'yes' => true,
        ]);

        return Command::SUCCESS;
    }

    // ----
    // Helpers
    // ----

    /**
     * @throws ValidationException When CLI option validation fails
     */
    private function resolveRemotePath(): string
    {
        /** @var string $remoteInput */
        $remoteInput = $this->io->getValidatedOptionOrPrompt(
            'remote',
            fn ($validate): string => $this->io->promptText(
                label: 'Remote filename (relative to shared/):',
                placeholder: '.env',
                required: true,
                validate: $validate
            ),
            fn ($value) => $this->validatePathInput($value)
        );

        return $this->normalizeRelativePath($remoteInput);
    }

    /**
     * @throws ValidationException When CLI option validation fails
     * @throws \RuntimeException When path expansion fails
     */
    private function resolveLocalPath(string $remoteRelative): string
    {
        $default = basename($remoteRelative) ?: $remoteRelative;

        /** @var string $localInput */
        $localInput = $this->io->getValidatedOptionOrPrompt(
            'local',
            fn ($validate): string => $this->io->promptText(
                label: 'Local destination path:',
                default: $default,
                required: true,
                validate: $validate
            ),
            fn ($value) => $this->validatePathInput($value)
        );

        return $this->fs->expandPath($localInput);
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

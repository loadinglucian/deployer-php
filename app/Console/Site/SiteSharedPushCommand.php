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
    name: 'site:shared:push',
    description: 'Upload a file into a site\'s shared directory'
)]
class SiteSharedPushCommand extends BaseCommand
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
            ->addOption('local', null, InputOption::VALUE_REQUIRED, 'Local file path to upload')
            ->addOption('remote', null, InputOption::VALUE_REQUIRED, 'Remote filename (relative to shared/)')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip typing the filename to confirm')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip Yes/No confirmation prompt');
    }

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->h1('Upload Shared File');

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
            $localPath = $this->resolveLocalPath();
            $remoteRelative = $this->resolveRemotePath($localPath);
        } catch (ValidationException|\RuntimeException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        $remotePath = $this->buildSharedPath($site, $remoteRelative);
        $remoteDir = dirname($remotePath);

        //
        // Check for existing file
        // ----

        if ($this->remoteFileExists($server, $remotePath)) {
            $this->warn("Shared file '{$remoteRelative}' already exists");

            /** @var bool $forceSkip */
            $forceSkip = $input->getOption('force');

            if (!$forceSkip) {
                $typedName = $this->io->promptText(
                    label: "Type the remote filename '{$remoteRelative}' to confirm overwrite:",
                    required: true
                );

                if ($typedName !== $remoteRelative) {
                    $this->nay('Filename does not match. Upload cancelled.');

                    return Command::FAILURE;
                }
            }

            $confirmed = $this->io->getBooleanOptionOrPrompt(
                'yes',
                fn (): bool => $this->io->promptConfirm(
                    label: 'Are you sure?',
                    default: false
                )
            );

            if (!$confirmed) {
                $this->warn('Upload cancelled.');

                return Command::SUCCESS;
            }
        }

        //
        // Upload file
        // ----

        $tempPath = '/tmp/deployer-upload-' . bin2hex(random_bytes(8));

        try {
            $this->io->promptSpin(
                function () use ($server, $localPath, $remotePath, $remoteDir, $tempPath): void {
                    $this->runRemoteCommand($server, sprintf('mkdir -p %s', escapeshellarg($remoteDir)));
                    $this->ssh->uploadFile($server, $localPath, $tempPath);
                    $this->runRemoteCommand($server, sprintf('mv %s %s', escapeshellarg($tempPath), escapeshellarg($remotePath)));
                    $this->runRemoteCommand($server, sprintf('chown deployer:deployer %s', escapeshellarg($remotePath)));
                    $this->runRemoteCommand($server, sprintf('chmod 640 %s', escapeshellarg($remotePath)));
                },
                'Uploading file...'
            );
        } catch (\RuntimeException $e) {
            // Clean up temp file on error
            $this->ssh->executeCommand($server, sprintf('rm -f %s', escapeshellarg($tempPath)));
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        $this->yay('Shared file uploaded (redeploy to link)');

        //
        // Show command replay
        // ----

        $this->commandReplay([
            'domain' => $site->domain,
            'local' => $localPath,
            'remote' => $remoteRelative,
            'force' => true,
            'yes' => true,
        ]);

        return Command::SUCCESS;
    }

    // ----
    // Helpers
    // ----

    /**
     * @throws ValidationException When CLI option validation fails or file not found
     * @throws \RuntimeException When path expansion fails
     */
    private function resolveLocalPath(): string
    {
        /** @var string $localInput */
        $localInput = $this->io->getValidatedOptionOrPrompt(
            'local',
            fn ($validate): string => $this->io->promptText(
                label: 'Local file path:',
                placeholder: '.env.production',
                required: true,
                validate: $validate
            ),
            fn ($value) => $this->validatePathInput($value)
        );

        $expanded = $this->fs->expandPath($localInput);

        if (! $this->fs->exists($expanded) || ! is_file($expanded)) {
            throw new ValidationException("Local file not found: {$expanded}");
        }

        return $expanded;
    }

    /**
     * @throws ValidationException When CLI option validation fails
     */
    private function resolveRemotePath(string $localPath): string
    {
        $defaultName = basename($localPath);

        /** @var string $remoteInput */
        $remoteInput = $this->io->getValidatedOptionOrPrompt(
            'remote',
            fn ($validate): string => $this->io->promptText(
                label: 'Remote filename (relative to shared/):',
                placeholder: '' === $defaultName ? '.env' : $defaultName,
                default: '' === $defaultName ? '.env' : $defaultName,
                required: true,
                validate: $validate
            ),
            fn ($value) => $this->validatePathInput($value)
        );

        return $this->normalizeRelativePath($remoteInput);
    }

    private function runRemoteCommand(ServerDTO $server, string $command): void
    {
        $result = $this->ssh->executeCommand($server, $command);
        if (0 !== $result['exit_code']) {
            $output = trim((string) $result['output']);
            $message = '' === $output ? "Remote command failed: {$command}" : $output;

            throw new \RuntimeException($message);
        }
    }

    private function remoteFileExists(ServerDTO $server, string $remotePath): bool
    {
        $result = $this->ssh->executeCommand(
            $server,
            sprintf('test -f %s', escapeshellarg($remotePath))
        );

        if (0 === $result['exit_code']) {
            return true;
        }

        if (1 === $result['exit_code']) {
            return false;
        }

        $output = trim((string) $result['output']);
        $message = '' === $output ? "Failed checking remote file: {$remotePath}" : $output;

        throw new \RuntimeException($message);
    }
}

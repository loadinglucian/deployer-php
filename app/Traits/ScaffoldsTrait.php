<?php

declare(strict_types=1);

namespace DeployerPHP\Traits;

use DeployerPHP\Exceptions\ValidationException;
use DeployerPHP\Services\FilesystemService;
use DeployerPHP\Services\IoService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;

/**
 * Reusable scaffold file copying helpers.
 *
 * @property FilesystemService $fs
 * @property IoService $io
 *
 * @method void displayDeets(array<string, string> $details)
 * @method void nay(string $message)
 * @method void yay(string $message)
 * @method void commandReplay(string $command, array<string, mixed> $options)
 */
trait ScaffoldsTrait
{
    use PathOperationsTrait;

    // ----
    // Helpers
    // ----

    /**
     * Configure scaffold command options.
     */
    protected function configureScaffoldOptions(): void
    {
        $this->addOption('destination', null, InputOption::VALUE_REQUIRED, 'Project root directory');
    }

    /**
     * Scaffold files from templates to destination.
     *
     * @param string $type Scaffold type (e.g., 'crons', 'hooks')
     */
    protected function scaffoldFiles(string $type): int
    {
        // Get destination directory
        try {
            /** @var string $destinationDir */
            $destinationDir = $this->io->getValidatedOptionOrPrompt(
                'destination',
                fn ($validate) => $this->io->promptText(
                    label: 'Destination directory:',
                    placeholder: $this->fs->getCwd(),
                    default: $this->fs->getCwd(),
                    required: true,
                    validate: $validate
                ),
                fn ($value) => $this->validatePathInput($value)
            );
        } catch (ValidationException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        // Convert relative path to absolute if needed
        if (! str_starts_with($destinationDir, '/')) {
            $destinationDir = $this->fs->joinPaths($this->fs->getCwd(), $destinationDir);
        }

        $targetDir = $this->fs->joinPaths($destinationDir, '.deployer', $type);

        // Copy templates
        try {
            $this->copyScaffoldTemplates($type, $targetDir);
        } catch (\RuntimeException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        $this->yay('Finished scaffolding '.$type);

        $this->commandReplay('scaffold:'.$type, [
            'destination' => $destinationDir,
        ]);

        return Command::SUCCESS;
    }

    /**
     * Copy scaffold templates to destination directory.
     *
     * @throws \RuntimeException If templates not found or file operations fail
     */
    private function copyScaffoldTemplates(string $type, string $destination): void
    {
        if (! $this->fs->isDirectory($destination)) {
            $this->fs->mkdir($destination);
        }

        $scaffoldsPath = $this->fs->joinPaths(dirname(__DIR__, 2), 'scaffolds', $type);
        if (! $this->fs->isDirectory($scaffoldsPath)) {
            throw new \RuntimeException("Templates directory not found: {$scaffoldsPath}");
        }

        $entries = $this->fs->scanDirectory($scaffoldsPath);
        $status = [];

        foreach ($entries as $entry) {
            $source = $this->fs->joinPaths($scaffoldsPath, $entry);
            $target = $this->fs->joinPaths($destination, $entry);

            if ($this->fs->isDirectory($source)) {
                continue;
            }

            $skipped = true;
            if (! $this->fs->exists($target) && ! $this->fs->isLink($target)) {
                $contents = $this->fs->readFile($source);
                $this->fs->dumpFile($target, $contents);
                $skipped = false;
            }

            $status[$entry] = $skipped ? 'skipped' : 'created';
        }

        $this->displayDeets($status);
    }
}

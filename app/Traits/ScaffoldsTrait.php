<?php

declare(strict_types=1);

namespace Deployer\Traits;

use Deployer\Exceptions\ValidationException;
use Deployer\Services\FilesystemService;
use Deployer\Services\IOService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;

/**
 * Reusable scaffold file copying helpers.
 *
 * @property FilesystemService $fs
 * @property IOService $io
 *
 * @method void displayDeets(array<string, string> $details)
 * @method void nay(string $message)
 * @method void yay(string $message)
 * @method void commandReplay(string $command, array<string, mixed> $options)
 */
trait ScaffoldsTrait
{
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
     *
     * @throws ValidationException When CLI option validation fails
     */
    protected function scaffoldFiles(string $type): int
    {
        // Get destination directory
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
            fn ($value) => $this->validateDestinationInput($value)
        );

        // Convert relative path to absolute if needed
        if (! str_starts_with($destinationDir, '/')) {
            $destinationDir = $this->fs->getCwd() . '/' . $destinationDir;
        }

        $targetDir = $destinationDir . '/.deployer/' . $type;

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
        if (! is_dir($destination) && ! mkdir($destination, 0755, true)) {
            throw new \RuntimeException("Destination directory does not exist: {$destination}");
        }

        $scaffoldsPath = dirname(__DIR__, 2).'/scaffolds/'.$type;
        if (! is_dir($scaffoldsPath)) {
            throw new \RuntimeException("Templates directory not found: {$scaffoldsPath}");
        }

        $entries = scandir($scaffoldsPath) ?: [];
        $status = [];

        foreach ($entries as $entry) {
            $source = $scaffoldsPath.'/'.$entry;
            $target = $destination.'/'.$entry;

            if (in_array($entry, ['.', '..']) || is_dir($source)) {
                continue;
            }

            $skipped = true;
            if (! file_exists($target) && ! is_link($target)) {
                $contents = $this->fs->readFile($source);
                $this->fs->dumpFile($target, $contents);
                $skipped = false;
            }

            $status[$entry] = $skipped ? 'skipped' : 'created';
        }

        $this->displayDeets($status);
    }

    // ----
    // Validation
    // ----

    /**
     * Validate destination path is not empty.
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateDestinationInput(mixed $path): ?string
    {
        if (! is_string($path)) {
            return 'Path must be a string';
        }

        if ('' === trim($path)) {
            return 'Path cannot be empty';
        }

        return null;
    }
}

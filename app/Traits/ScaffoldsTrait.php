<?php

declare(strict_types=1);

namespace DeployerPHP\Traits;

use DeployerPHP\Exceptions\ValidationException;
use DeployerPHP\Services\FilesystemService;
use DeployerPHP\Services\IoService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;

/**
 * Reusable scaffold file copying helpers using template method pattern.
 *
 * Commands can override hooks to customize behavior:
 * - resolveScaffoldContext(): Add extra prompts (e.g., agent selection)
 * - buildTargetPath(): Custom destination structure
 * - buildTemplatePath(): Custom source directory
 * - buildReplayOptions(): Extra command replay options
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
    // Configuration
    // ----

    /**
     * Configure scaffold command options.
     */
    protected function configureScaffoldOptions(): void
    {
        $this->addOption('destination', null, InputOption::VALUE_REQUIRED, 'Project root directory');
    }

    // ----
    // Template Method - Main Workflow
    // ----

    /**
     * Execute the scaffold workflow.
     *
     * @param string $type Scaffold type identifier
     */
    protected function scaffoldFiles(string $type): int
    {
        // Step 1: Get validated destination directory
        try {
            $destinationDir = $this->promptDestinationDirectory();
        } catch (ValidationException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        // Step 2: Hook for commands needing extra prompts (e.g., agent selection)
        $context = $this->resolveScaffoldContext($destinationDir, $type);
        if (null === $context) {
            return Command::FAILURE;
        }

        // Step 3: Build target path using context
        $targetDir = $this->buildTargetPath($destinationDir, $type, $context);

        // Step 4: Copy templates
        try {
            $status = $this->copyTemplates($type, $targetDir, $context);
        } catch (\RuntimeException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        // Step 5: Display results and replay
        $this->displayDeets($status);
        $this->yay('Finished scaffolding ' . $type);
        $this->commandReplay(
            'scaffold:' . $type,
            $this->buildReplayOptions($destinationDir, $context)
        );

        return Command::SUCCESS;
    }

    // ----
    // Overridable Hooks
    // ----

    /**
     * Resolve additional context needed for scaffolding.
     * Override this to add extra prompts (e.g., agent selection).
     *
     * @return array<string, mixed>|null Context data, or null to abort
     */
    protected function resolveScaffoldContext(string $destinationDir, string $type): ?array
    {
        return [];
    }

    /**
     * Build the target directory path.
     * Override this for custom destination structures.
     *
     * @param array<string, mixed> $context
     */
    protected function buildTargetPath(string $destinationDir, string $type, array $context): string
    {
        return $this->fs->joinPaths($destinationDir, '.deployer', $type);
    }

    /**
     * Build path to template source directory.
     * Override for custom template locations.
     *
     * @param array<string, mixed> $context
     */
    protected function buildTemplatePath(string $type, array $context): string
    {
        return $this->fs->joinPaths(dirname(__DIR__, 2), 'scaffolds', $type);
    }

    /**
     * Build options for command replay.
     * Override this to add extra options.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    protected function buildReplayOptions(string $destinationDir, array $context): array
    {
        return ['destination' => $destinationDir];
    }

    // ----
    // Helpers
    // ----

    /**
     * Prompt for and validate destination directory.
     *
     * @throws ValidationException
     */
    protected function promptDestinationDirectory(): string
    {
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

        // Expand tilde and convert relative path to absolute if needed
        $destinationDir = $this->fs->expandPath($destinationDir);
        if (! str_starts_with((string) $destinationDir, '/')) {
            $destinationDir = $this->fs->joinPaths($this->fs->getCwd(), $destinationDir);
        }

        return $destinationDir;
    }

    /**
     * Copy template files to destination.
     *
     * @param array<string, mixed> $context
     * @return array<string, string> Status map (filename => 'created'|'skipped')
     * @throws \RuntimeException If templates not found or file operations fail
     */
    protected function copyTemplates(string $type, string $targetDir, array $context): array
    {
        if (! $this->fs->isDirectory($targetDir)) {
            $this->fs->mkdir($targetDir);
        }

        $scaffoldsPath = $this->buildTemplatePath($type, $context);
        if (! $this->fs->isDirectory($scaffoldsPath)) {
            throw new \RuntimeException("Templates directory not found: {$scaffoldsPath}");
        }

        $entries = $this->fs->scanDirectory($scaffoldsPath);
        $status = [];

        foreach ($entries as $entry) {
            $source = $this->fs->joinPaths($scaffoldsPath, $entry);
            $target = $this->fs->joinPaths($targetDir, $entry);

            if ($this->fs->isDirectory($source)) {
                continue;
            }

            if ($this->fs->exists($target) || $this->fs->isLink($target)) {
                $status[$entry] = 'skipped';
            } else {
                $this->fs->dumpFile($target, $this->fs->readFile($source));
                $status[$entry] = 'created';
            }
        }

        return $status;
    }
}

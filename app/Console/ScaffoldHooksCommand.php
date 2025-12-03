<?php

declare(strict_types=1);

namespace Deployer\Console;

use Deployer\Contracts\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'scaffold:hooks',
    description: 'Scaffold deployment hooks from templates'
)]
class ScaffoldHooksCommand extends BaseCommand
{
    // ----
    // Configuration
    // ----

    protected function configure(): void
    {
        parent::configure();

        $this->addOption('destination', null, InputOption::VALUE_REQUIRED, 'Project root directory');
    }

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->h1('Scaffold Deployment Hooks');

        //
        // Get destination directory
        // ----

        /** @var string $destinationDir */
        $destinationDir = $this->io->getOptionOrPrompt(
            'destination',
            fn () => $this->io->promptText(
                label: 'Destination directory:',
                placeholder: $this->fs->getCwd(),
                default: $this->fs->getCwd(),
                required: true
            )
        );

        // Convert relative path to absolute if needed
        if (! str_starts_with($destinationDir, '/')) {
            $destinationDir = $this->fs->getCwd().'/'.$destinationDir;
        }

        $hooksDir = $destinationDir.'/.deployer/hooks';

        //
        // Create hook templates
        // ----

        try {
            $this->createHookTemplates($hooksDir);
        } catch (\RuntimeException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        $this->yay('Created deployment hooks in the destination directory');

        $this->commandReplay('scaffold:hooks', [
            'destination' => $destinationDir,
        ]);

        return Command::SUCCESS;
    }

    // ----
    // Helpers
    // ----

    /**
     * Create hook templates in the destination directory.
     *
     * @throws \RuntimeException If templates are not found or file operations fail
     */
    private function createHookTemplates(string $destination): void
    {
        if (! is_dir($destination) && ! mkdir($destination, 0755, true)) {
            throw new \RuntimeException("Destination directory does not exist: {$destination}");
        }

        $scaffoldsPath = dirname(__DIR__, 2).'/scaffolds/hooks';
        if (! is_dir($scaffoldsPath)) {
            throw new \RuntimeException("Hook templates directory not found: {$scaffoldsPath}");
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
            if (!file_exists($target) && !is_link($target)) {
                $contents = $this->fs->readFile($source);
                $this->fs->dumpFile($target, $contents);
                $skipped = false;
            }

            $status[$entry] = $skipped ? 'skipped' : 'created';
        }

        $this->displayDeets($status);
        $this->out('');
    }
}

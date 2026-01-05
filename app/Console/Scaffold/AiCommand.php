<?php

declare(strict_types=1);

namespace DeployerPHP\Console\Scaffold;

use DeployerPHP\Contracts\BaseCommand;
use DeployerPHP\Exceptions\ValidationException;
use DeployerPHP\Traits\PathOperationsTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'scaffold:ai',
    description: 'Scaffold AI agent rules for DeployerPHP'
)]
class AiCommand extends BaseCommand
{
    use PathOperationsTrait;

    /** @var array<string, string> */
    private const AGENT_DIRS = [
        'claude' => '.claude',
        'cursor' => '.cursor',
        'codex' => '.codex',
    ];

    // ----
    // Configuration
    // ----

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('agent', null, InputOption::VALUE_REQUIRED, 'AI agent (claude, cursor, codex)');
        $this->addOption('destination', null, InputOption::VALUE_REQUIRED, 'Project root directory');
    }

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->h1('Scaffold AI Rules');

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

        // Determine target agent
        $agent = $this->determineAgent($destinationDir, $input);
        if (null === $agent) {
            return Command::FAILURE;
        }

        // Build target path
        $agentDir = self::AGENT_DIRS[$agent];
        $rulesDir = $this->fs->joinPaths($destinationDir, $agentDir, 'rules');
        $targetFile = $this->fs->joinPaths($rulesDir, 'deployer-php.md');

        // Check if file exists (skip like other scaffold commands)
        $status = [];
        if ($this->fs->exists($targetFile)) {
            $status['deployer-php.md'] = 'skipped';
        } else {
            // Create directory structure if needed
            if (! $this->fs->isDirectory($rulesDir)) {
                $this->fs->mkdir($rulesDir);
            }

            // Write rules file
            $this->fs->dumpFile($targetFile, $this->getRulesContent());
            $status['deployer-php.md'] = 'created';
        }

        $this->displayDeets($status);
        $this->yay('Finished scaffolding AI rules');

        $this->commandReplay('scaffold:ai', [
            'agent' => $agent,
            'destination' => $destinationDir,
        ]);

        return Command::SUCCESS;
    }

    // ----
    // Helpers
    // ----

    /**
     * Determine which AI agent to target.
     */
    private function determineAgent(string $destinationDir, InputInterface $input): ?string
    {
        // Check for --agent option first
        /** @var string|null $agentOption */
        $agentOption = $input->getOption('agent');
        if (null !== $agentOption) {
            $error = $this->validateAgentInput($agentOption);
            if (null !== $error) {
                $this->nay($error);

                return null;
            }

            return $agentOption;
        }

        // Detect existing AI directories
        $existing = $this->detectExistingAgentDirs($destinationDir);

        if (1 === count($existing)) {
            // One found - use it
            return $existing[0];
        }

        if (count($existing) > 1) {
            // Multiple found - ask which to use
            $options = [];
            foreach ($existing as $agent) {
                $options[$agent] = self::AGENT_DIRS[$agent].' (exists)';
            }

            /** @var string */
            return $this->io->promptSelect(
                label: 'Multiple AI agent directories found. Which one should we use?',
                options: $options
            );
        }

        // None found - ask which to create
        $options = [];
        foreach (self::AGENT_DIRS as $agent => $dir) {
            $options[$agent] = $dir;
        }

        /** @var string */
        return $this->io->promptSelect(
            label: 'No AI agent directory found. Which one should we create?',
            options: $options
        );
    }

    /**
     * Detect existing AI agent directories.
     *
     * @return list<string>
     */
    private function detectExistingAgentDirs(string $destinationDir): array
    {
        $existing = [];
        foreach (self::AGENT_DIRS as $agent => $dir) {
            $path = $this->fs->joinPaths($destinationDir, $dir);
            if ($this->fs->isDirectory($path)) {
                $existing[] = $agent;
            }
        }

        return $existing;
    }

    /**
     * Get the rules file content from template.
     *
     * @throws \RuntimeException If template file not found
     */
    private function getRulesContent(): string
    {
        $templatePath = $this->fs->joinPaths(dirname(__DIR__, 3), 'scaffolds', 'ai', 'deployer-php.md');

        if (! $this->fs->exists($templatePath)) {
            throw new \RuntimeException("Template file not found: {$templatePath}");
        }

        return $this->fs->readFile($templatePath);
    }

    // ----
    // Validation
    // ----

    /**
     * Validate agent input.
     *
     * @return string|null Error message if invalid, null if valid
     */
    private function validateAgentInput(mixed $value): ?string
    {
        if (! is_string($value)) {
            return 'Agent must be a string';
        }

        if (! array_key_exists($value, self::AGENT_DIRS)) {
            $valid = implode(', ', array_keys(self::AGENT_DIRS));

            return "Invalid agent '{$value}'. Valid options: {$valid}";
        }

        return null;
    }
}

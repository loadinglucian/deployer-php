<?php

declare(strict_types=1);

namespace DeployerPHP\Console\Scaffold;

use DeployerPHP\Contracts\BaseCommand;
use DeployerPHP\Traits\ScaffoldsTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'scaffold:ai',
    description: 'Scaffold AI agent rules for DeployerPHP'
)]
class AiCommand extends BaseCommand
{
    use ScaffoldsTrait;

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
        $this->configureScaffoldOptions();
        $this->addOption('agent', null, InputOption::VALUE_REQUIRED, 'AI agent (claude, cursor, codex)');
    }

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->h1('Scaffold AI Rules');

        return $this->scaffoldFiles('ai');
    }

    // ----
    // Hook Overrides
    // ----

    /**
     * Resolve agent selection context.
     *
     * @return array{agent: string}|null
     */
    protected function resolveScaffoldContext(string $destinationDir, string $type): ?array
    {
        $agent = $this->determineAgent($destinationDir);
        if (null === $agent) {
            return null;
        }

        return ['agent' => $agent];
    }

    /**
     * Build target path for AI agent rules directory.
     *
     * @param array{agent: string} $context
     */
    protected function buildTargetPath(string $destinationDir, string $type, array $context): string
    {
        $agentDir = self::AGENT_DIRS[$context['agent']];

        return $this->fs->joinPaths($destinationDir, $agentDir, 'rules');
    }

    /**
     * Include agent in replay options.
     *
     * @param array{agent: string} $context
     * @return array<string, mixed>
     */
    protected function buildReplayOptions(string $destinationDir, array $context): array
    {
        return [
            'agent' => $context['agent'],
            'destination' => $destinationDir,
        ];
    }

    // ----
    // Helpers
    // ----

    /**
     * Determine which AI agent to target.
     */
    private function determineAgent(string $destinationDir): ?string
    {
        // Check for --agent option first
        /** @var string|null $agentOption */
        $agentOption = $this->io->getOptionValue('agent');
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
                $options[$agent] = self::AGENT_DIRS[$agent] . ' (exists)';
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

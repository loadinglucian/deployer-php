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
    description: 'Scaffold AI agent skill for DeployerPHP (observer, debugger, or admin tier)'
)]
class AiCommand extends BaseCommand
{
    use ScaffoldsTrait;

    /** @var array<string, string> */
    private const AGENT_DIRS = [
        'claude' => '.claude',
        'codex' => '.codex',
        'cursor' => '.cursor',
        'opencode' => '.opencode',
    ];

    /** @var array<string, string> */
    private const AGENT_LABELS = [
        'claude' => 'Claude',
        'codex' => 'Codex',
        'cursor' => 'Cursor',
        'opencode' => 'OpenCode',
    ];

    /** @var list<string> */
    private const AGENT_ORDER = [
        'claude',
        'codex',
        'cursor',
        'opencode',
    ];

    /** @var array<string, string> */
    private const TIERS = [
        'observer' => 'Observer - Read-only (view logs, server info)',
        'debugger' => 'Debugger - Inspect + safe shell (default)',
        'admin' => 'Admin - Full access (manage infrastructure)',
    ];

    private const DEFAULT_TIER = 'debugger';

    // ----
    // Configuration
    // ----

    protected function configure(): void
    {
        parent::configure();
        $this->configureScaffoldOptions();
        $this->addOption('agent', null, InputOption::VALUE_REQUIRED, 'AI agent (Claude, Codex, Cursor, OpenCode)');
        $this->addOption('tier', null, InputOption::VALUE_REQUIRED, 'Skill tier (observer, debugger, admin)');
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
     * Resolve agent and tier selection context.
     *
     * @return array{agent: string, tier: string}|null
     */
    protected function resolveScaffoldContext(string $destinationDir, string $type): ?array
    {
        $agent = $this->determineAgent($destinationDir);
        if (null === $agent) {
            return null;
        }

        $tier = $this->determineTier();
        if (null === $tier) {
            return null;
        }

        return ['agent' => $agent, 'tier' => $tier];
    }

    /**
     * Build target path for AI agent skills directory.
     *
     * @param array{agent: string, tier: string} $context
     */
    protected function buildTargetPath(string $destinationDir, string $type, array $context): string
    {
        $agentDir = self::AGENT_DIRS[$context['agent']];

        if ('opencode' === $context['agent']) {
            return $this->fs->joinPaths($destinationDir, $agentDir, 'skill', 'deployer-php');
        }

        return $this->fs->joinPaths($destinationDir, $agentDir, 'skills', 'deployer-php');
    }

    /**
     * Build path to tier-specific template directory.
     *
     * @param array<string, mixed> $context
     */
    protected function buildTemplatePath(string $type, array $context): string
    {
        /** @var string $tier */
        $tier = $context['tier'];

        return $this->fs->joinPaths(dirname(__DIR__, 3), 'scaffolds', $type, $tier);
    }

    /**
     * Include agent and tier in replay options.
     *
     * @param array{agent: string, tier: string} $context
     * @return array<string, mixed>
     */
    protected function buildReplayOptions(string $destinationDir, array $context): array
    {
        return [
            'agent' => $context['agent'],
            'tier' => $context['tier'],
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
            foreach (self::AGENT_ORDER as $agent) {
                if (! in_array($agent, $existing, true)) {
                    continue;
                }

                $options[$agent] = sprintf(
                    '%s (%s exists)',
                    self::AGENT_LABELS[$agent],
                    self::AGENT_DIRS[$agent]
                );
            }

            /** @var string */
            return $this->io->promptSelect(
                label: 'Multiple AI agent directories found. Which one should we use?',
                options: $options
            );
        }

        // None found - ask which to create
        $options = [];
        foreach (self::AGENT_ORDER as $agent) {
            $options[$agent] = sprintf(
                '%s (%s)',
                self::AGENT_LABELS[$agent],
                self::AGENT_DIRS[$agent]
            );
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
     * Determine which tier to use.
     */
    private function determineTier(): ?string
    {
        // Check for --tier option first
        /** @var string|null $tierOption */
        $tierOption = $this->io->getOptionValue('tier');
        if (null !== $tierOption) {
            $error = $this->validateTierInput($tierOption);
            if (null !== $error) {
                $this->nay($error);

                return null;
            }

            return $tierOption;
        }

        // Prompt for tier selection with default
        /** @var string */
        return $this->io->promptSelect(
            label: 'Select permission tier:',
            options: self::TIERS,
            default: self::DEFAULT_TIER
        );
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

    /**
     * Validate tier input.
     *
     * @return string|null Error message if invalid, null if valid
     */
    private function validateTierInput(mixed $value): ?string
    {
        if (! is_string($value)) {
            return 'Tier must be a string';
        }

        if (! array_key_exists($value, self::TIERS)) {
            $valid = implode(', ', array_keys(self::TIERS));

            return "Invalid tier '{$value}'. Valid options: {$valid}";
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace DeployerPHP\Console\Scaffold;

use DeployerPHP\Contracts\BaseCommand;
use DeployerPHP\Exceptions\ValidationException;
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
        '.agents' => '.agents',
        '.claude' => '.claude',
    ];

    /** @var array<string, string> */
    private const AGENT_LABELS = [
        '.agents' => "'.agents/' directory (Codex, Cursor, OpenCode)",
        '.claude' => "'.claude/' directory (Claude Code)",
    ];

    /** @var list<string> */
    private const AGENT_ORDER = [
        '.agents',
        '.claude',
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
        $this->addOption('agent', null, InputOption::VALUE_REQUIRED, 'AI agent directory/directories (.agents,.claude)');
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
     * @return array{agents: list<string>, tier: string}|null
     */
    protected function resolveScaffoldContext(string $destinationDir, string $type): ?array
    {
        $agents = $this->determineAgents($destinationDir);
        if (null === $agents) {
            return null;
        }

        $tier = $this->determineTier();
        if (null === $tier) {
            return null;
        }

        return ['agents' => $agents, 'tier' => $tier];
    }

    /**
     * Build target path for AI agent skills directory.
     *
     * @param array{agents: list<string>, tier: string} $context
     */
    protected function buildTargetPath(string $destinationDir, string $type, array $context): string
    {
        /** @var string $agent */
        $agent = $context['agents'][0];

        return $this->buildAgentTargetPath($destinationDir, $agent, $context['tier']);
    }

    /**
     * Build target paths for AI agent skills directories.
     *
     * @param array{agents: list<string>, tier: string} $context
     * @return list<string>
     */
    protected function buildTargetPaths(string $destinationDir, string $type, array $context): array
    {
        $paths = [];

        foreach ($context['agents'] as $agent) {
            $paths[] = $this->buildAgentTargetPath($destinationDir, $agent, $context['tier']);
        }

        return $paths;
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
        $templateDir = sprintf('deployerphp-%s', $tier);

        return $this->fs->joinPaths(dirname(__DIR__, 3), 'scaffolds', $type, $templateDir);
    }

    /**
     * Include agent(s) and tier in replay options.
     *
     * @param array{agents: list<string>, tier: string} $context
     * @return array<string, mixed>
     */
    protected function buildReplayOptions(string $destinationDir, array $context): array
    {
        return [
            'agent' => implode(',', $context['agents']),
            'tier' => $context['tier'],
            'destination' => $destinationDir,
        ];
    }

    // ----
    // Helpers
    // ----

    /**
     * Determine which AI agent directories to target.
     *
     * @return list<string>|null
     */
    private function determineAgents(string $destinationDir): ?array
    {
        // Check for --agent option first
        /** @var string|null $agentOption */
        $agentOption = $this->io->getOptionValue('agent');
        if (null !== $agentOption) {
            $error = $this->validateAgentsInput($agentOption);
            if (null !== $error) {
                $this->nay($error);

                return null;
            }

            return $this->normalizeAgentsInput($agentOption);
        }

        // Auto-detect existing AI directories and scaffold all detected dirs
        $existing = $this->detectExistingAgentDirs($destinationDir);
        if ([] !== $existing) {
            return $existing;
        }

        // None found - prompt with multiselect
        $options = [];
        foreach (self::AGENT_ORDER as $agent) {
            $options[$agent] = sprintf(
                '%s (%s)',
                self::AGENT_LABELS[$agent],
                self::AGENT_DIRS[$agent]
            );
        }

        try {
            /** @var array<int, string>|string $selected */
            $selected = $this->io->getValidatedOptionOrPrompt(
                'agent',
                fn ($validate) => $this->io->promptMultiselect(
                    label: 'No AI agent directory found. Select directories to scaffold: (.agents supports Codex, Cursor, OpenCode)',
                    options: $options,
                    default: ['.agents'],
                    required: true,
                    hint: 'Use space to toggle, enter to confirm',
                    validate: $validate
                ),
                fn ($value) => $this->validateAgentsInput($value)
            );
        } catch (ValidationException $e) {
            $this->nay($e->getMessage());

            return null;
        }

        $error = $this->validateAgentsInput($selected);
        if (null !== $error) {
            $this->nay($error);

            return null;
        }

        return $this->normalizeAgentsInput($selected);
    }

    /**
     * Build destination path for a single AI agent directory.
     */
    private function buildAgentTargetPath(string $destinationDir, string $agent, string $tier): string
    {
        $agentDir = self::AGENT_DIRS[$agent];
        $skillDir = sprintf('deployerphp-%s', $tier);

        return $this->fs->joinPaths($destinationDir, $agentDir, 'skills', $skillDir);
    }

    /**
     * Detect existing AI agent directories.
     *
     * @return list<string>
     */
    private function detectExistingAgentDirs(string $destinationDir): array
    {
        $existing = [];
        foreach (self::AGENT_ORDER as $agent) {
            $dir = self::AGENT_DIRS[$agent];
            $path = $this->fs->joinPaths($destinationDir, $dir);
            if ($this->fs->isDirectory($path)) {
                $existing[] = $agent;
            }
        }

        return $existing;
    }

    /**
     * Normalize agent selection from CLI string or prompt array.
     *
     * @return list<string>
     */
    private function normalizeAgentsInput(mixed $value): array
    {
        if (is_string($value)) {
            $agents = array_values(array_filter(
                array_map(trim(...), explode(',', $value)),
                static fn (string $agent): bool => '' !== $agent
            ));

            return $this->sortAgentsByOrder(array_values(array_unique($agents)));
        }

        if (! is_array($value)) {
            return [];
        }

        $agents = [];
        foreach ($value as $agent) {
            if (! is_string($agent)) {
                continue;
            }

            $agent = trim($agent);
            if ('' === $agent) {
                continue;
            }

            $agents[] = $agent;
        }

        return $this->sortAgentsByOrder(array_values(array_unique($agents)));
    }

    /**
     * Keep agent selection in canonical order.
     *
     * @param list<string> $agents
     * @return list<string>
     */
    private function sortAgentsByOrder(array $agents): array
    {
        $sorted = [];
        foreach (self::AGENT_ORDER as $agent) {
            if (in_array($agent, $agents, true)) {
                $sorted[] = $agent;
            }
        }

        // Preserve unknown values at the end so validation can report them.
        foreach ($agents as $agent) {
            if (! in_array($agent, $sorted, true)) {
                $sorted[] = $agent;
            }
        }

        return $sorted;
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
    private function validateAgentsInput(mixed $value): ?string
    {
        if (null === $value) {
            return 'At least one agent directory must be selected';
        }

        if (! is_string($value) && ! is_array($value)) {
            return 'Agent must be a comma-separated string or array';
        }

        $agents = $this->normalizeAgentsInput($value);
        if ([] === $agents) {
            return 'At least one agent directory must be selected';
        }

        $invalidAgents = array_values(array_diff($agents, array_keys(self::AGENT_DIRS)));
        if ([] !== $invalidAgents) {
            $valid = implode(', ', array_keys(self::AGENT_DIRS));

            return "Invalid agent(s): " . implode(', ', $invalidAgents) . ". Valid options: {$valid}";
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

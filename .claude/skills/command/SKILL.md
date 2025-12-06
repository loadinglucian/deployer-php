---
name: command
description: Use this skill when creating, modifying, or updating Symfony Console commands. Activates for tasks involving CLI commands, user prompts, input validation, or interactive/non-interactive command patterns.
---

# Symfony Console Command Development

Commands are Symfony Console classes that handle user I/O. They use Laravel Prompts for interactive input and BaseCommand methods for output.

All rules MANDATORY.

## Core Principles

- Every command MUST be fully runnable non-interactively via CLI options
- Every prompt MUST have a corresponding CLI option
- Never invoke other commands (NO proxy commands)

## Required Structure

Every command MUST follow this structure:

```php
<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\{text, password, confirm, select, multiselect, spin};

#[AsCommand(name: 'namespace:action', description: 'Brief description')]
final class ActionCommand extends BaseCommand
{
    public function __construct(
        private readonly SomeService $service,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('name', null, InputOption::VALUE_REQUIRED, 'Resource name');
        $this->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Get input (IOService is auto-initialized via BaseCommand::initialize())
        $name = $this->getOptionOrPrompt(
            'name',
            fn () => $this->promptText(label: 'Name:', required: true)
        );

        // Business logic via service
        $this->service->doSomething($name);

        // Output and replay
        $this->yay("Created {$name}");
        $this->commandReplay('namespace:action', [
            'name' => $name,
            'yes' => true,
        ]);

        return Command::SUCCESS;
    }
}
```

## Output Methods

NEVER use Symfony IO methods directly. Use BaseCommand methods:

```php
// Text output
$this->out(['Multiple', 'lines']);      // Plain text (array or string)
$this->hr();                             // Horizontal rule

// Headings
$this->h1('Section Heading');            // Large heading

// Status messages
$this->yay('Success');                   // Green checkmark
$this->nay('Failed');                    // Red X
$this->warn('Warning');                  // Yellow warning
$this->info('Info');                     // Blue info

// Structured output
$this->displayDeets(['Key' => 'value']); // Key-value pairs
$this->ul(['Item 1', 'Item 2']);         // Bullet list
$this->ol(['Step 1', 'Step 2']);         // Numbered list
```

**Trait Organization:**

- `ConsoleOutputTrait`: Output/formatting using `$this->io`
- `ConsoleInputTrait`: Input using `$this->input`
- `BaseCommand`: Shared initialization, configuration

## User Input with Laravel Prompts

```php
use function Laravel\Prompts\{text, password, confirm, select, multiselect, spin};

// Text input
$name = text('Name?', required: true);

// Password (hidden input)
$secret = password('API Key?');

// Confirmation
$confirmed = confirm('Proceed?');

// Single selection
$env = select('Environment:', ['dev', 'staging', 'prod']);

// Multiple selection
$features = multiselect('Features:', ['api', 'auth', 'cache']);

// Long-running operation with spinner
$result = spin(fn() => $this->service->process(), 'Processing...');
```

## Interactive + Options Pattern

The pattern for combining interactive prompts with CLI options:

```php
protected function configure(): void
{
    parent::configure();
    $this->addOption('name', null, InputOption::VALUE_REQUIRED, 'Server name');
}

protected function execute(InputInterface $input, OutputInterface $output): int
{
    $this->setupIo($input, $output);

    // If --name provided, use it; otherwise prompt
    $name = $this->getOptionOrPrompt(
        'name',
        fn () => $this->promptText(label: 'Server name:', required: true)
    );

    return Command::SUCCESS;
}
```

## Input Validation

### Validator Signature

Returns `?string` (error message or null for valid):

```php
protected function validateNameInput(mixed $value): ?string
{
    if (!is_string($value)) {
        return 'Name must be a string';
    }
    if ('' === trim($value)) {
        return 'Name cannot be empty';
    }
    if (null !== $this->repo->findByName($value)) {
        return "'{$value}' already exists";
    }

    return null;
}
```

### Usage with getValidatedOptionOrPrompt

```php
$name = $this->getValidatedOptionOrPrompt(
    'name',
    fn ($validate) => $this->promptText(label: 'Name:', validate: $validate),
    fn ($value) => $this->validateNameInput($value)
);

if (null === $name) {
    return Command::FAILURE;  // Validation failed (CLI mode)
}
```

### Naming Convention

| Pattern | Returns | Use Case |
|---------|---------|----------|
| `validate*Input()` | `?string` | Prompts and CLI options |
| `validate*()` | throws | Heavy I/O validation |

## Boolean Flags

### Simple Flag (VALUE_NONE)

```php
// Definition
$this->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip confirmation');

// Usage: --yes or -y
$skipConfirm = $this->input->getOption('yes');
```

### Tri-State Flag (VALUE_NEGATABLE)

```php
// Definition
$this->addOption('php-default', null, InputOption::VALUE_NEGATABLE, 'Set as default PHP');

// Usage: --php-default (true), --no-php-default (false), omitted (null/prompt)
$phpDefault = $this->input->getOption('php-default');
if (null === $phpDefault) {
    $phpDefault = $this->promptConfirm('Set as default PHP?');
}
```

## Multiselect with CLI

Handle both array (prompt) and comma-separated string (CLI):

```php
$selected = $this->getOptionOrPrompt(
    'databases',
    fn () => $this->promptMultiselect(label: 'Databases:', options: $options)
);

// Normalize: CLI gives string, prompt gives array
if (is_string($selected)) {
    $selected = array_filter(array_map(trim(...), explode(',', $selected)));
}
```

## Multi-Path Prompts

When a prompt offers multiple paths (e.g., "generate new" vs "use existing"), create separate options:

```php
// Separate options for each path
$this->addOption('generate-deploy-key', null, InputOption::VALUE_NONE, 'Generate new deploy key');
$this->addOption('custom-deploy-key', null, InputOption::VALUE_REQUIRED, 'Path to existing key');

// Detect conflicts
$generateKey = $this->input->getOption('generate-deploy-key');
$customKeyPath = $this->input->getOption('custom-deploy-key');

if ($generateKey && null !== $customKeyPath) {
    $this->nay('Cannot use both --generate-deploy-key and --custom-deploy-key');
    return Command::FAILURE;
}
```

## Confirmation Patterns

### Simple Confirmation (`--yes`)

For standard confirmations:

```php
// Definition
$this->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip confirmation');

// Usage
$confirmed = $this->getOptionOrPrompt('yes', fn () => $this->promptConfirm('Proceed?'));
if (!$confirmed) {
    return Command::SUCCESS;
}
```

### Type-to-Confirm (`--force`)

For destructive operations requiring explicit confirmation:

```php
// Definition
$this->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip type-to-confirm');

// Usage
$forceSkip = $this->input->getOption('force');
if (!$forceSkip) {
    $typedName = $this->promptText(label: "Type '{$server->name}' to confirm deletion:");
    if ($typedName !== $server->name) {
        $this->nay('Name does not match. Aborting.');
        return Command::FAILURE;
    }
}
```

## Command Options Naming

| Option | Usage | Type |
|--------|-------|------|
| `--server` | Select existing server | VALUE_REQUIRED |
| `--domain` | Select existing site | VALUE_REQUIRED |
| `--name` | Define new resource name | VALUE_REQUIRED |
| `--yes` / `-y` | Skip confirmation | VALUE_NONE |
| `--force` / `-f` | Skip type-to-confirm | VALUE_NONE |

**Golden Rule:** `--server`/`--domain` for SELECTING existing resources, `--name` for DEFINING new ones.

## Command Completion

Always call `commandReplay()` before returning SUCCESS:

```php
$this->commandReplay('server:delete', [
    'server' => $server->name,
    'yes' => true,
]);

return Command::SUCCESS;
```

This outputs the equivalent non-interactive command for documentation/automation.

## Common Mistakes

```php
// WRONG - Missing null check after validated prompt
$value = $this->getValidatedOptionOrPrompt(...);
$this->doSomething($value);  // $value could be null!

// CORRECT - Check for validation failure
$value = $this->getValidatedOptionOrPrompt(...);
if (null === $value) {
    return Command::FAILURE;
}

// WRONG - Silent fallback on explicit input
$path = $this->getOptionOrPrompt('key-path', ...);
$resolved = $this->resolveKey($path);  // Falls back even if user's path invalid!

// WRONG - CLI option bypasses validation
$env = $this->getOptionOrPrompt('env', fn() => $this->promptSelect(..., options: $valid));
// --env=invalid passes through unvalidated! Use getValidatedOptionOrPrompt instead.
```

## Checklist

Before completing a command:

- [ ] Every prompt has corresponding `addOption()`
- [ ] Multi-path prompts have separate options
- [ ] Conflicting options detected and rejected
- [ ] CLI values validated against allowed options
- [ ] `commandReplay()` called before SUCCESS
- [ ] Type annotations on option retrievals
- [ ] Uses BaseCommand output methods (never SymfonyStyle directly)
- [ ] Command is fully runnable non-interactively

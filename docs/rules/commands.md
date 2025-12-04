# Symfony Console Rules

All rules MANDATORY.

## Core Principle

Every command MUST be fully runnable non-interactively via CLI options. Every prompt MUST have a corresponding CLI option.

## Output Methods

NEVER use Symfony IO methods directly - use BaseCommand methods:

```php
$this->out(['Multiple', 'lines']);
$this->hr();
$this->h1('Section Heading');
$this->displayDeets(['Key' => 'value']);
$this->yay('Success');              // checkmark
$this->nay('Failed');               // red X
$this->warn('Warning');             // warning
$this->info('Info');                // info
$this->ul(['Item 1', 'Item 2']);    // bullet list
$this->ol(['Step 1', 'Step 2']);    // numbered list
```

**Trait Organization:**
- ConsoleOutputTrait: Output/formatting using `$this->io`
- ConsoleInputTrait: Input using `$this->input`
- BaseCommand: Shared initialization, configuration

## User Input with Laravel Prompts

```php
use function Laravel\Prompts\{text, password, confirm, select, multiselect, spin};

$name = text('Name?', required: true);
$env = select('Environment:', ['dev', 'staging', 'prod']);
$result = spin(fn() => $this->service->process(), 'Processing...');
```

## Interactive + Options Pattern

```php
protected function configure(): void {
    parent::configure();
    $this->addOption('name', null, InputOption::VALUE_REQUIRED, 'Server name');
}

protected function execute(InputInterface $input, OutputInterface $output): int {
    $name = $this->getOptionOrPrompt(
        'name',
        fn () => $this->promptText(label: 'Server name:', required: true)
    );
    return Command::SUCCESS;
}
```

## Input Validation

**Validator Signature** - Returns `?string` (error message or null):

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

**Usage with getValidatedOptionOrPrompt:**

```php
$name = $this->getValidatedOptionOrPrompt(
    'name',
    fn ($validate) => $this->promptText(label: 'Name:', validate: $validate),
    fn ($value) => $this->validateNameInput($value)
);

if (null === $name) {
    return Command::FAILURE;  // Validation failed
}
```

**Naming Convention:**
- `validate*Input()` - Returns `?string` (for prompts/options)
- `validate*()` - Throws exceptions (for heavy I/O)

## Boolean Flags

```php
// VALUE_NONE - Simple flag
$this->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip confirmation');

// VALUE_NEGATABLE - Tri-state (--flag, --no-flag, or prompt)
$this->addOption('php-default', null, InputOption::VALUE_NEGATABLE, 'Set as default PHP');
```

## Multiselect with CLI

```php
$selected = $this->getOptionOrPrompt(
    'databases',
    fn () => $this->promptMultiselect(label: 'Databases:', options: $options)
);

// Handle both array (prompt) and string (CLI)
if (is_string($selected)) {
    $selected = array_filter(array_map(trim(...), explode(',', $selected)));
}
```

## Multi-Path Prompts

Create separate options for each path:

```php
// Separate options for each path
$this->addOption('generate-deploy-key', null, InputOption::VALUE_NONE, 'Generate key');
$this->addOption('custom-deploy-key', null, InputOption::VALUE_REQUIRED, 'Custom key path');

// Check for conflicts
if ($generateKey && null !== $customKeyPath) {
    $this->nay('Cannot use both options');
    return Command::FAILURE;
}
```

## Confirmation Patterns

**Simple (`--yes`):**

```php
$this->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip confirmation');
$confirmed = $this->getOptionOrPrompt('yes', fn () => $this->promptConfirm('Sure?'));
```

**Type-to-Confirm (`--force`) - Destructive ops:**

```php
if (!$forceSkip) {
    $typedName = $this->promptText(label: "Type '{$server->name}' to confirm:");
    if ($typedName !== $server->name) {
        $this->nay('Name does not match');
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

**Golden Rule:** `--server`/`--domain` for SELECTING existing, `--name` for DEFINING new.

## Command Completion

Always call `commandReplay()` before SUCCESS:

```php
$this->commandReplay('server:delete', [
    'server' => $server->name,
    'yes' => true,
]);
return Command::SUCCESS;
```

## Checklist

- [ ] Every prompt has corresponding `addOption()`
- [ ] Multi-path prompts have separate options
- [ ] Conflicting options detected and rejected
- [ ] CLI values validated against allowed options
- [ ] `commandReplay()` called before SUCCESS
- [ ] Type annotations on option retrievals

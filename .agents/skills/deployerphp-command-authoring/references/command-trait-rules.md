# Command & Trait Rules

Commands are Symfony Console classes that handle user I/O. Traits provide shared command behavior. Both use Laravel Prompts via `$this->io` wrapper methods and BaseCommand methods for output.

> **IMPORTANT**
>
> - Every prompt MUST have a corresponding CLI option
> - Never invoke other commands (NO proxy commands)
> - Validate conflicting options immediately after `parent::execute()` (early validation)
> - NEVER use `getOptionOrPrompt()` directly - it's private
> - New commands MUST be registered in `SymfonyApp.php` (import + add to `$commands` array)
> - **After command changes:** Use the `deployerphp-docs-policy` skill when documentation edits are needed

## Context

### Non-Interactive Design

Commands support both interactive and non-interactive execution through a simple pattern: **options replace prompts**.

**How it works:**

1. If CLI option provided → skip the prompt, use the option value
2. If CLI option omitted → show the interactive prompt
3. `commandReplay()` outputs the full non-interactive command with all selected values

**Benefits:**

- Users can run partially with options and fill in the rest interactively
- Command replay teaches users the full CLI syntax for automation
- No complex "is this required in non-interactive mode?" validation logic
- Easy to add new prompts/options without updating validation rules

Falling through to interactive prompts when CLI options are omitted is the intended pattern, not a bug.

### Output Methods

Use BaseCommand methods. Never use SymfonyStyle directly.

- `yay`, `nay`, `warn`, `info`, `out`
- `h1`, `hr`
- `displayDeets`, `ul`, `ol`

### Input Method Selection

| Input Type             | Method                         | Validation  |
| ---------------------- | ------------------------------ | ----------- |
| Boolean (confirm/flag) | `getBooleanOptionOrPrompt()`   | None needed |
| String/array           | `getValidatedOptionOrPrompt()` | Required    |

### Validator Naming Convention

| Pattern            | Returns   | Use Case                |
| ------------------ | --------- | ----------------------- |
| `validate*Input()` | `?string` | Prompts and CLI options |
| `validate*()`      | throws    | Heavy I/O validation    |

### Command Options Naming

| Option           | Usage                    | Type           |
| ---------------- | ------------------------ | -------------- |
| `--server`       | Select existing server   | VALUE_REQUIRED |
| `--domain`       | Select existing site     | VALUE_REQUIRED |
| `--name`         | Define new resource name | VALUE_REQUIRED |
| `--yes` / `-y`   | Skip confirmation        | VALUE_NONE     |
| `--force` / `-f` | Skip type-to-confirm     | VALUE_NONE     |

`--server`/`--domain` for SELECTING existing resources, `--name` for DEFINING new ones.

## Examples

### Example: Command Structure

```php
#[AsCommand(name: 'namespace:action', description: 'Brief description')]
final class ActionCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->addOption('name', null, InputOption::VALUE_REQUIRED, 'Resource name');
        $this->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $name = $this->io->getValidatedOptionOrPrompt(
                'name',
                fn ($validate) => $this->io->promptText(label: 'Name:', validate: $validate),
                fn ($value) => $this->validateNameInput($value)
            );
        } catch (ValidationException $e) {
            $this->nay($e->getMessage());
            return Command::FAILURE;
        }

        $this->service->doSomething($name);
        $this->yay("Created {$name}");
        $this->commandReplay('namespace:action', ['name' => $name, 'yes' => true]);
        return Command::SUCCESS;
    }

}
```

### Example: Trait Structure

```php
<?php

declare(strict_types=1);

namespace DeployerPHP\Traits;

use DeployerPHP\DTOs\ServerDTO;
use DeployerPHP\Exceptions\ValidationException;
use Symfony\Component\Console\Command\Command;

trait ServersTrait
{
    /**
     * Select a server from inventory.
     *
     * @throws ValidationException When CLI validation fails
     */
    protected function selectServer(): ServerDTO
    {
        $name = $this->io->getValidatedOptionOrPrompt(
            'server',
            fn ($validate) => $this->io->promptSelect(
                label: 'Select server:',
                options: $this->getServerOptions(),
                validate: $validate
            ),
            fn ($value) => $this->validateServerSelection($value)
        );

        /** @var ServerDTO */
        return $this->servers->findByName($name);
    }

    protected function validateServerSelection(mixed $name): ?string
    {
        if (!is_string($name)) {
            return 'Server name must be a string';
        }
        if (null === $this->servers->findByName($name)) {
            return "Server '{$name}' not found in inventory";
        }
        return null;
    }
}
```

### Example: Trait Exception Handling

```php
// In trait - throws, doesn't catch
protected function selectServer(): ServerDTO
{
    $name = $this->io->getValidatedOptionOrPrompt(...);  // May throw
    return $this->servers->findByName($name);
}

// In command - catches at execute() level
protected function execute(...): int
{
    try {
        $server = $this->selectServer();  // Trait method
        $site = $this->selectSite();      // Trait method
    } catch (ValidationException $e) {
        $this->nay($e->getMessage());
        return Command::FAILURE;
    }
    // ...
}
```

### Example: Non-Interactive Fallback

```php
// Option provided → uses CLI value, skips prompt
// Option omitted → falls through to interactive prompt
if ($generateKey) {
    $deployKeyPath = null;
} elseif ($customKeyPath !== null) {
    $deployKeyPath = $this->promptDeployKeyPairPath();
} else {
    // Interactive fallback - this is VALID, not an error
    $choice = $this->io->promptSelect(
        label: 'Deploy key:',
        options: ['generate' => '...', 'custom' => '...'],
    );
    $deployKeyPath = ($choice === 'generate') ? null : $this->promptDeployKeyPairPath();
}
```

### Example: Validation Exception

```php
use DeployerPHP\Exceptions\ValidationException;

// IoService throws on validation failure (CLI mode)
// Interactive prompts re-prompt until valid, so no exception there
try {
    $name = $this->io->getValidatedOptionOrPrompt(...);
    $host = $this->io->getValidatedOptionOrPrompt(...);
    $port = $this->io->getValidatedOptionOrPrompt(...);
} catch (ValidationException $e) {
    $this->nay($e->getMessage()); // Display the validation error
    return Command::FAILURE;
}
// All values guaranteed valid after try-catch
```

### Example: Validator Signature

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

### Example: Boolean Flag Simple

```php
// Definition
$this->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip confirmation');

// Usage: --yes or -y
$skipConfirm = $this->input->getOption('yes');
```

### Example: Boolean Flag Tristate

```php
// Definition
$this->addOption('php-default', null, InputOption::VALUE_NEGATABLE, 'Set as default PHP');

// Usage: --php-default (true), --no-php-default (false), omitted (null/prompt)
$phpDefault = $this->input->getOption('php-default');
if (null === $phpDefault) {
    $phpDefault = $this->promptConfirm('Set as default PHP?');
}
```

### Example: Boolean Prompt

```php
$confirmed = $this->io->getBooleanOptionOrPrompt(
    'yes',
    fn () => $this->io->promptConfirm('Proceed?')
);
```

### Example: Validated String

```php
// Throws ValidationException on CLI validation failure
// Wrap in try-catch to handle gracefully
try {
    $name = $this->io->getValidatedOptionOrPrompt(
        'name',
        fn ($validate) => $this->io->promptText(label: 'Name:', validate: $validate),
        fn ($value) => $this->validateNameInput($value)
    );
} catch (ValidationException $e) {
    $this->nay($e->getMessage());
    return Command::FAILURE;
}
```

### Example: Multiselect CLI

```php
try {
    $selected = $this->io->getValidatedOptionOrPrompt(
        'databases',
        fn ($validate) => $this->io->promptMultiselect(label: 'Databases:', options: $options),
        fn ($value) => $this->validateDatabasesInput($value, $options)
    );
} catch (ValidationException $e) {
    $this->nay($e->getMessage());
    return Command::FAILURE;
}

// Normalize: CLI gives string, prompt gives array
if (is_string($selected)) {
    $selected = array_filter(array_map(trim(...), explode(',', $selected)));
}
```

### Example: Multi-Path Options

```php
// In configure()
$this->addOption('generate-deploy-key', null, InputOption::VALUE_NONE, 'Generate new deploy key');
$this->addOption('custom-deploy-key', null, InputOption::VALUE_REQUIRED, 'Path to existing key');

// In execute() - early validation block (right after parent::execute())
if ($generateKey && null !== $customKeyPath) {
    $this->nay('Cannot use both --generate-deploy-key and --custom-deploy-key');
    return Command::FAILURE;
}

// Later in execute() - after header and server selection
if (!$generateKey && null === $customKeyPath) {
    $choice = $this->io->promptSelect(...);
    // ...
}
```

### Example: Confirmation Simple

```php
// Definition
$this->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip confirmation');

// Usage
$confirmed = $this->io->getBooleanOptionOrPrompt('yes', fn () => $this->io->promptConfirm('Proceed?'));
if (! $confirmed) {
    return Command::SUCCESS;
}
```

### Example: Confirmation Destructive

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

### Example: Command Replay

```php
$this->commandReplay('server:delete', [
    'server' => $server->name,
    'yes' => true,
]);

return Command::SUCCESS;
```

### Example: Wrong No Try-Catch

```php
// WRONG - No try-catch around validated input
$value = $this->io->getValidatedOptionOrPrompt(...);
$this->doSomething($value);  // Throws ValidationException if CLI validation fails!

// CORRECT - Wrap in try-catch
try {
    $value = $this->io->getValidatedOptionOrPrompt(...);
} catch (ValidationException $e) {
    $this->nay($e->getMessage());
    return Command::FAILURE;
}
```

### Example: Wrong No Validator

```php
// WRONG - No validator for string input
$env = $this->io->promptSelect('Environment:', $options);
// User CLI option --env=invalid passes through! Always use getValidatedOptionOrPrompt.

// CORRECT - Always validate string/array inputs
try {
    $env = $this->io->getValidatedOptionOrPrompt(
        'env',
        fn ($validate) => $this->io->promptSelect('Environment:', $options),
        fn ($value) => $this->validateEnvInput($value, $options)
    );
} catch (ValidationException $e) {
    $this->nay($e->getMessage());
    return Command::FAILURE;
}
```

### Example: Wrong Separate Try-Catch

```php
// WRONG - Separate try-catch for each input (verbose)
try {
    $name = $this->io->getValidatedOptionOrPrompt(...);
} catch (ValidationException $e) { ... }
try {
    $host = $this->io->getValidatedOptionOrPrompt(...);
} catch (ValidationException $e) { ... }

// CORRECT - Group related inputs in single try-catch
try {
    $name = $this->io->getValidatedOptionOrPrompt(...);
    $host = $this->io->getValidatedOptionOrPrompt(...);
} catch (ValidationException $e) {
    $this->nay($e->getMessage());
    return Command::FAILURE;
}
```

### Example: Wrong Late Validation

```php
// WRONG - Late validation (after SSH/playbook calls)
protected function execute(...): int
{
    $this->h1('Install Service');
    $server = $this->selectServerDeets();  // SSH connection here
    $this->executePlaybook($server, 'some-playbook', '...');  // Network I/O here

    // TOO LATE - validation happens after expensive operations
    if ($optionA && $optionB) {
        $this->nay('Cannot use both...');
        return Command::FAILURE;
    }

}

// CORRECT - Early validation (before any I/O)
protected function execute(...): int
{
    parent::execute($input, $output);

    // Validate FIRST, before header
    if ($optionA && $optionB) {
        $this->nay('Cannot use both...');
        return Command::FAILURE;
    }

    $this->h1('Install Service');
    $server = $this->selectServerDeets();
    // ...

}
```

## Rules

- Traits let `ValidationException` propagate to commands - never catch in traits
- Group related validated inputs in single try-catch block
- `validate*Input()` returns `?string`; `validate*()` throws exceptions
- Always call `commandReplay()` before returning `Command::SUCCESS`
- Validate conflicting options BEFORE any I/O (early validation)
- Booleans use `getBooleanOptionOrPrompt()`; strings/arrays use `getValidatedOptionOrPrompt()`
- Multi-path prompts need separate options, conflict detection in early validation

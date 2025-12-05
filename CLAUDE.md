# Deployer PHP

Server and site deployment tool for PHP. Composer package and CLI built on Symfony Console.

## Quality Gates

**IMPORTANT:** After making changes to PHP files or playbooks, ALWAYS run the `quality-gatekeeper` agent before responding to the user. This is mandatory, not optional.

## Code Philosophy

- **Minimalism:** Write minimum code necessary. Eliminate single-use methods. Cache computed values.
- **Organization:** Group related functions into comment-separated sections. Order alphabetically after grouping.
- **Consistency:** Same style throughout. Code should appear written by single person.

## PHP Standards

- PSR-12, strict types, PHP 8.x features (unions, match, attributes, readonly)
- Explicit return types with generics: `Collection<int, User>`
- Dependency injection via Symfony patterns
- Use Symfony classes over native PHP functions (Filesystem, Process) for testability
- **Yoda conditions:** Constants/literals on LEFT side of comparisons
- **Always use braces:** ALL control structures must use `{ }`

```php
// Yoda conditions
if (null === $value) { ... }
if ('' === trim($name)) { ... }

// Two variables - Yoda doesn't apply
if ($typedName !== $server->name) { ... }
```

**PHPStan:** Use `@var` annotations, not `assert()` in production:

```php
/** @var string $apiToken */
$apiToken = $this->env->get(['API_TOKEN']);
```

**Imports:** Always add `use` statements for vendor packages. Root namespace FQDNs acceptable (`\RuntimeException`).

## Dependency Injection

Use `$container->build(ClassName::class)` for all object creation except DTOs/value objects.

```php
// Production
$service = $this->container->build(MyService::class);

// Tests - container supports bind() for mocks
$container = new Container();
$container->bind(SSHService::class, $mockSSH);
$command = $container->build(ServerAddCommand::class);
```

**Integration:** Entry point: `bin/deployer`. Command registration: `app/SymfonyApp.php`. Services: Auto-injected via constructor.

## Layer Separation

**Command Layer:**

- Handle user interaction (input/output), orchestrate Services
- NO business logic (delegate to Services)
- Responsible for console styling, error formatting, prompts
- Never invoke other commands
- Use BaseCommand methods, never SymfonyStyle directly

**Service Layer:**

- Atomic, reusable functionality with NO console I/O
- Accept/return plain PHP data types
- Handle business logic, external APIs, file operations

**Service State:**

- Stateless: Pure operations (validators, calculators, API clients)
- Stateful: Manage configuration/cached data, use lazy loading and explicit `load()`/`initialize()` methods

**Dependencies:**

- Commands depend on Services
- Services depend on Services/utilities
- All dependencies in constructor signatures
- NO circular dependencies

## Exception Handling

Services throw complete, user-facing exceptions. Commands display directly without adding prefixes.

```php
// Service - complete message with context
throw new \RuntimeException("SSH key does not exist: {$privateKeyPath}");
throw new \RuntimeException("Server '{$name}' already exists");

// Preserve exception chain when wrapping
} catch (\Throwable $e) {
    throw new \RuntimeException(
        "SSH authentication failed for {$username}@{$host}. Check username and key permissions",
        previous: $e
    );
}

// Command - display directly, no prefix
try {
    $this->servers->create($server);
} catch (\RuntimeException $e) {
    $this->nay($e->getMessage());  // Already complete
    return Command::FAILURE;
}

// WRONG - redundant prefix
$this->nay('Failed to add server: ' . $e->getMessage());
```

**Validation Patterns:**

```php
// Pattern A: Input validation - returns ?string
protected function validateNameInput(mixed $name): ?string
{
    if ('' === trim($name)) {
        return 'Server name cannot be empty';
    }
    return null;
}

// Pattern B: Heavy I/O validation - throws
protected function validateGitRepo(string $repo): void
{
    if (!$process->isSuccessful()) {
        throw new \RuntimeException("Cannot access git repository '{$repo}'");
    }
}
```

**Silent Failures:** Return `null`/`false` only for optional operations (detection, optional lookups).

| Layer                         | Display Errors? | Pattern                        |
| ----------------------------- | --------------- | ------------------------------ |
| Services/Repositories         | No              | Throw complete exceptions      |
| Validation Traits             | No              | Return `?string` or throw      |
| Commands/Orchestration Traits | Yes             | Catch & display without prefix |

## Comments

**DocBlock:** Minimalist descriptions, parameters, return types.

**Comment structure:**

```
// ----
// {h1}
// ----

//
// {h2}
// ----

//
// {h3}

// {p}
```

Separate sections visually. No obvious comments. Remove comments when removing code.

## File Operations

Use terminal commands for file management:

```bash
mv old.php new.php      # Rename/move
cp source.php dest.php  # Copy
mkdir -p path/to/dir    # Create directories
```

## Execution Protocol

1. ULTRATHINK - analyze problem deeply
2. STEP BY STEP - break into logical steps
3. ACT - implement systematically

## Test Policy

Don't run, create, or update tests UNLESS explicitly instructed.

## References

- Check `composer.json` and `package.json` for installed packages
- Plan with features from installed major versions

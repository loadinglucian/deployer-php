# Architecture Rules

All rules MANDATORY.

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
if (0 !== $exitCode) { ... }

// Two variables - Yoda doesn't apply
if ($typedName !== $server->name) { ... }
```

## PHPStan Type Hints

Use `@var` annotations, not `assert()` in production:

```php
/** @var string $apiToken */
$apiToken = $this->env->get(['API_TOKEN']);
```

## Imports

Always add `use` statements for vendor packages. Root namespace FQDNs acceptable (`\RuntimeException`).

```php
use Symfony\Component\Filesystem\Filesystem;

$fs = new Filesystem();
throw new \InvalidArgumentException('Error');
```

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

**Integration:** Entry point: `bin/deployer`. Command registration: `SymfonyApp.php`. Services: Auto-injected via constructor.

## Layer Separation

**Command Layer:**
- Handle user interaction (input/output), orchestrate Services
- NO business logic (delegate to Services)
- Responsible for console styling, error formatting, prompts
- Never invoke other commands

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

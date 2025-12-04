# Exception Handling & Error Display

All rules MANDATORY.

## Core Principle

Services throw complete, user-facing exceptions. Command layer displays them directly without adding prefixes.

## Services & Repositories

Throw `\RuntimeException` with complete, actionable messages:

```php
// Complete message with context
throw new \RuntimeException("SSH key does not exist: {$privateKeyPath}");
throw new \RuntimeException("Server '{$name}' already exists");

// Preserve exception chain when wrapping
} catch (\Throwable $e) {
    throw new \RuntimeException(
        "SSH authentication failed for {$username}@{$host}. Check username and key permissions",
        previous: $e
    );
}
```

**Rules:**
- Messages must be user-facing and complete (not fragments)
- Include relevant context (paths, names, IDs, hosts)
- Use `previous: $e` to preserve exception chains
- Never catch and re-throw with generic prefixes like "Failed to..."

## Validation Traits

**Pattern A: Input Validation** - Returns `?string`:

```php
protected function validateNameInput(mixed $name): ?string
{
    if (!is_string($name)) {
        return 'Server name must be a string';
    }
    if ('' === trim($name)) {
        return 'Server name cannot be empty';
    }
    return null;
}
```

**Pattern B: Heavy I/O Validation** - Throws:

```php
protected function validateGitRepo(string $repo): void
{
    if (!$process->isSuccessful()) {
        throw new \RuntimeException("Cannot access git repository '{$repo}'");
    }
}
```

## Commands & Orchestration Traits

Display exceptions directly without redundant prefixes:

```php
// CORRECT
try {
    $this->servers->create($server);
} catch (\RuntimeException $e) {
    $this->nay($e->getMessage());  // Already complete
    return Command::FAILURE;
}

// WRONG - redundant prefix
$this->nay('Failed to add server: ' . $e->getMessage());
```

**When to add context:**
- Displaying raw output for debugging
- Adding actionable troubleshooting steps
- Exception message is too technical

## Silent Failures

Return `null`/`false` only for optional operations:

```php
// CORRECT - Optional detection
public function detectRemoteUrl(): ?string
{
    try {
        return $process->isSuccessful() ? trim($process->getOutput()) : null;
    } catch (\Exception) {
        return null;  // Not in git repo, that's okay
    }
}

// WRONG - Required operation returning null
public function executeCommand(): ?array
{
    } catch (\Throwable) {
        return null;  // Caller doesn't know WHY
    }
}
```

## Exception Message Quality

Every message must be:
- Complete: "SSH key does not exist: /path/to/key"
- User-facing: "Cannot connect to database. Check host and port."
- Actionable with context
- Free of redundant prefixes

## Layer Responsibility

| Layer | Display Errors? | Pattern |
|-------|-----------------|---------|
| Services | No | Throw complete exceptions |
| Repositories | No | Throw complete exceptions |
| Validation Traits | No | Return `?string` or throw |
| Orchestration Traits | Yes | Catch & display without prefix |
| Commands | Yes | Catch & display without prefix |

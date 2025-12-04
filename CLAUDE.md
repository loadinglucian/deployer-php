# Deployer PHP

Server and site deployment tool for PHP. Composer package and CLI built on Symfony Console.

## Commands

```bash
# Quality gates (run before completing tasks)
vendor/bin/rector $CHANGED_PHP_FILES
vendor/bin/pint $CHANGED_PHP_FILES
vendor/bin/phpstan analyse $CHANGED_PHP_FILES  # NEVER run on tests/

# All quality checks
composer pall

# Testing
composer pest                 # Full suite with coverage
vendor/bin/pest $TEST_FILE    # Specific file

# Bash formatting
composer bash                 # Format playbooks/*.sh
composer bash:check           # Check only
```

## Code Philosophy

- **Minimalism:** Write minimum code necessary. Eliminate single-use methods. Cache computed values.
- **Organization:** Group related functions into comment-separated sections. Order alphabetically after grouping.
- **Consistency:** Same style throughout. Code should appear written by single person.

## Architecture Summary

| Concept | Rule |
|---------|------|
| DI | `$container->build(Class::class)` for all objects except DTOs |
| Layers | Commands (I/O) → Services (logic) → Repositories (data) |
| Exceptions | Services throw complete messages, Commands display directly |
| PHP | PSR-12, strict types, Yoda conditions (`null === $value`), always braces |
| Console | Use BaseCommand methods, never SymfonyStyle directly |
| Playbooks | Idempotent bash scripts, YAML output to `$DEPLOYER_OUTPUT_FILE` |

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

## Detailed Rules by Domain

| Working On | Reference |
|------------|-----------|
| PHP architecture, DI, layers | @docs/rules/architecture.md |
| Symfony Console commands | @docs/rules/commands.md |
| Exception handling | @docs/rules/exceptions.md |
| Pest testing | @docs/rules/testing.md |
| Bash playbooks | @docs/rules/playbooks.md |
| Shell script style | @docs/rules/bash-style.md |
| Writing documentation | @docs/rules/writing-docs.md |

## References

- Check `composer.json` and `package.json` for installed packages
- Plan with features from installed major versions

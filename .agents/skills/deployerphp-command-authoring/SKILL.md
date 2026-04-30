---
name: deployerphp-command-authoring
description: Author and modify DeployerPHP Symfony Console commands and Traits, including prompt-to-option parity, early conflict validation, validator usage, command replay behavior, and registration in SymfonyApp. Use when editing app/Console/*, app/Traits/*, command wiring, or command-focused tests.
---

# Command Authoring

Use this skill when a task changes command input/output flow, options, shared
trait behavior, or command registration.

## Protocol

1. Read `references/command-trait-rules.md` before editing code.
2. Apply the command/trait rules exactly (prompt/option parity, no proxy
   commands, early option conflict checks, and replay requirements).
3. Keep validator and exception-handling patterns consistent with the
   reference examples.
4. After command changes, ensure command documentation expectations remain in
   sync (route to docs policy skill when docs edits are needed).

## References

- `references/command-trait-rules.md` - full rules and examples migrated from
  `AGENTS.md`.

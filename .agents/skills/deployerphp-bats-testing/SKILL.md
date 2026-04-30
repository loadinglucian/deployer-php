---
name: deployerphp-bats-testing
description: Design and update DeployerPHP BATS integration tests across VM and cloud suites, including non-interactive command execution, inventory isolation, run-suffix resource isolation, cleanup contracts, and helper usage. Use when editing tests/bats/*, bats.sh, or cloud janitor flows.
---

# BATS Testing

Use this skill for BATS architecture, test updates, and runner/cleanup flows.

## Protocol

1. Read `references/bats-rules.md` before changing BATS files.
2. Keep tests on success-path non-interactive flows and pass all required CLI
   options.
3. Preserve suite isolation and cloud resource naming/cleanup contracts.
4. Use documented helpers and setup/teardown guard patterns.

## References

- `references/bats-rules.md` - full BATS rules and examples migrated from
  `AGENTS.md`.

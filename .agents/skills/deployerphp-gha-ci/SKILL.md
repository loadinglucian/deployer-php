---
name: deployerphp-gha-ci
description: Author and maintain DeployerPHP GitHub Actions workflows and CI contracts, including setup-php-composer usage, timeout/concurrency patterns, matrix configuration, cloud-test environment wiring, cleanup backstops, and permissions/trigger constraints. Use when editing .github/workflows/* or related CI automation.
---

# GitHub Actions CI

Use this skill for workflow design and CI pipeline changes.

## Protocol

1. Read `references/github-actions-rules.md` before editing workflows.
2. Apply workflow-category constraints (quality-gate vs integration patterns)
   and timeout/concurrency standards.
3. Keep secrets/vars boundaries, permissions, and triggers aligned with policy.
4. Preserve cloud test env wiring and backstop cleanup behavior.

## References

- `references/github-actions-rules.md` - full workflow rules and examples
  migrated from `AGENTS.md`.

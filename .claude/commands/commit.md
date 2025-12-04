---
description: Create a branch based on working tree changes
allowed-tools: Bash(git:*)
model: sonnet
---

Based on the changes made to this repository:

A. If we're on the main branch, create a new branch with a suitable name 

Create the branch only (no commits yet). Use Conventional Commit types as branch prefixes:
feat/, fix/, docs/, style/, refactor/, perf/, test/, build/, ci/, chore/, revert/.

Keep the branch name short (≤ 50 chars) yet informative. Do not push, pull, or rebase.

Examples:

- feat/parser-add-php-84-attributes
- fix/ci-matrix-php-versions
- chore/deps-bump-composer-installers-2-3

B. Create one or more commits with suitable titles

Use Conventional Commits to group related changes into cohesive commits (commits should be independently meaningful).

Keep titles short (≤ 72 chars), imperative, no trailing period. Do not push, pull, or rebase.

Examples:

- feat(parser): add support for PHP 8.4 attributes
- fix(ci): correct matrix PHP versions in build workflow
- chore(deps): bump composer/installers to ^2.3

Body (optional): explain motivation, context, and breaking changes (use BREAKING CHANGE:).

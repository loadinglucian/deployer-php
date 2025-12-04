---
description: Create conventional commits from working tree changes
allowed-tools: Bash(git:*)
model: haiku
---

Based on the changes made to this repository create one or more commits with suitable titles.

Use Conventional Commits to group related changes into cohesive commits (commits should be independently meaningful).

Keep titles short (≤ 72 chars), imperative, no trailing period. Do not push, pull, or rebase.

Examples:

- feat(parser): add support for PHP 8.4 attributes
- fix(ci): correct matrix PHP versions in build workflow
- chore(deps): bump composer/installers to ^2.3

Body (optional): explain motivation, context, and breaking changes (use BREAKING CHANGE:).

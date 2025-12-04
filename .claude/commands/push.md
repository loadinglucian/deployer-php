---
description: Push branch and open a draft PR on GitHub
allowed-tools: Bash(git:*), Bash(gh:*)
model: haiku
---

Based on the current branch and its commits:

A. Push the branch to GitHub

Push the current branch to origin with tracking (-u flag). Do not force push.

B. Open a draft pull request

Create a draft PR using `gh pr create --draft` with:

**Title:** Use Conventional Commits format matching the branch prefix:
- feat/, fix/, docs/, style/, refactor/, perf/, test/, build/, ci/, chore/, revert/

Keep titles short (≤ 72 chars), imperative, no trailing period.

Examples:
- feat(parser): add support for PHP 8.4 attributes
- fix(ci): correct matrix PHP versions in build workflow
- chore(deps): bump composer/installers to ^2.3

**Body:** Generate a concise summary of changes from the commits on this branch. Include:
- Brief description of what changed
- Key implementation details (if relevant)

Do NOT include any AI attribution, "Generated with", or "Co-Authored-By" lines.

**Base branch:** Target `main` unless the branch name or commits suggest otherwise.

After creating the PR, output the PR URL.

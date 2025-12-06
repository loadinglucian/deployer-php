---
description: Create a branch and commits based on working tree changes
allowed-tools: Bash(git:*)
model: haiku
---

## Workflow

### Step 1: Identify ALL changes

Run `git status` to see:

- Modified files (staged and unstaged)
- Untracked files
- Deleted files

Read relevant files to understand what changed and group them logically.

### Step 2: If on main branch, create a feature branch

Create the branch only (no commits yet). Use Conventional Commit types as branch prefixes:
feat/, fix/, docs/, style/, refactor/, perf/, test/, build/, ci/, chore/, revert/.

Keep the branch name short (≤ 50 chars) yet informative. Do not push, pull, or rebase.

Examples:

- feat/parser-add-php-84-attributes
- fix/ci-matrix-php-versions
- chore/deps-bump-composer-installers-2-3

### Step 3: Create commits for ALL changes

**IMPORTANT:** Create commits for ALL modified, untracked, and deleted files. Nothing should be left uncommitted.

Group related changes into cohesive commits (commits should be independently meaningful).

Use Conventional Commits format:

- Keep titles short (≤ 72 chars), imperative, no trailing period
- Body (optional): explain motivation, context, and breaking changes (use BREAKING CHANGE:)
- Do NOT include any AI attribution, "Generated with", or "Co-Authored-By" lines

Examples:

- feat(parser): add support for PHP 8.4 attributes
- fix(ci): correct matrix PHP versions in build workflow
- chore(deps): bump composer/installers to ^2.3

### Step 4: Verify everything is committed

Run `git status` again to confirm:

- Working tree is clean
- No untracked files remain
- No modified files remain

If anything is left uncommitted, create additional commits until working tree is clean.

Do not push, pull, or rebase.

---
description: Commit, push, merge, sync, and cleanup in one workflow
allowed-tools: Bash(git:*), Bash(gh:*)
model: haiku
---

Complete end-to-end workflow: commit all changes, push branch, merge PR, sync, and cleanup.

## Phase 1: Commit Changes

### Step 1.1: Identify ALL changes

Run `git status` to see modified, untracked, and deleted files. Read relevant files to understand what changed.

### Step 1.2: If on main branch, create a feature branch

Use Conventional Commit types as branch prefixes: feat/, fix/, docs/, style/, refactor/, perf/, test/, build/, ci/, chore/, revert/. Keep branch name short (≤ 50 chars).

### Step 1.3: Create commits for ALL changes

Group related changes into cohesive commits. Use Conventional Commits format (≤ 72 chars, imperative, no trailing period). Do NOT include AI attribution lines.

### Step 1.4: Verify everything is committed

Run `git status` to confirm working tree is clean. If anything remains, create additional commits.

## Phase 2: Push and Create PR

### Step 2.1: Push the branch

Push to origin with tracking: `git push -u origin <branch>`

### Step 2.2: Check for existing PR

Run `gh pr list --head <branch> --json number,url` to check if PR exists.

### Step 2.3: Create or report PR

- If PR exists: Report the URL
- If no PR: Create draft with `gh pr create --draft` using Conventional Commits title, concise body from commits. No AI attribution.

## Phase 3: Merge PR

### Step 3.1: Get PR state

Run `gh pr view <number> --json state,isDraft`

### Step 3.2: Mark ready if draft

If draft: `gh pr ready <number>`

### Step 3.3: Merge with admin privileges

Run `gh pr merge <number> --squash --admin`

## Phase 4: Sync and Cleanup

### Step 4.1: Fetch with prune

Run `git fetch --prune` to fetch latest and prune dead remote-tracking references.

### Step 4.2: Switch to main

Run `git checkout main`

### Step 4.3: Update main branch

Run `git pull --rebase` to update main.

### Step 4.4: Delete merged local branches

Find and delete local branches whose remote tracking branch is gone:

```bash
git for-each-ref --format '%(refname:short) %(upstream:track)' refs/heads | while read branch track; do
  if [ "$track" = "[gone]" ]; then
    git branch -D "$branch"
  fi
done
```

### Step 4.5: Confirm success

Run `git status` and `git branch -vv` to show final state. Report what was done.

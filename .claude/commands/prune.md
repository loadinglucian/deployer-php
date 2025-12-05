---
description: Sync with remote and delete local branches with deleted upstreams
allowed-tools: Bash(git:*)
model: haiku
---

Sync current branch with the remote, switch to main branch, and delete all local branches whose upstream tracking branches have been deleted on the remote.

## Phase 1: Sync Current Branch

Follow the same workflow as the sync command to sync the current branch first.

### Step 1.1: Check current branch and status

Run `git status` to see:
- Current branch name
- Whether the branch has an upstream tracking branch
- Modified files (staged and unstaged)
- Untracked files

If the current branch has no upstream tracking branch, skip to Phase 2 (no sync needed).

### Step 1.2: Stash uncommitted changes if needed

If there are any modified files (staged or unstaged) or untracked files:
- Run `git stash push --include-untracked -m "Auto-stash for prune"`
- Remember that we stashed (for Phase 1.6)

If the working tree is clean, skip stashing and remember that we did NOT stash.

### Step 1.3: Fetch with prune

Run `git fetch --prune` to:
- Fetch latest changes from the remote
- Prune remote-tracking branches that no longer exist on the remote

### Step 1.4: Update all tracked local branches

Run `git branch -vv` to find all local branches with upstream tracking branches.

For each local branch that has an upstream and is not the current branch:
- If the branch can be fast-forwarded, update it with `git fetch origin remote_branch:local_branch`
- Skip branches that would require a merge (not fast-forward)

### Step 1.5: Rebase current branch on upstream

Run `git rebase` to rebase the current branch on its upstream tracking branch.

If the rebase fails with conflicts:
- Run `git rebase --abort` to abort the rebase
- If we stashed in Step 1.2, run `git stash pop` to restore changes
- Inform the user about the conflict and suggest they resolve manually
- Exit with failure

### Step 1.6: Restore stashed changes

If we stashed in Step 1.2:
- Run `git stash pop` to restore the stashed changes

If stash pop fails with conflicts:
- Inform the user that they need to resolve the stash conflicts manually
- The stash is still available via `git stash list`
- Exit with failure

## Phase 2: Switch to Main

### Step 2.1: Check if already on main

If already on main branch, skip to Phase 3.

### Step 2.2: Switch to main branch

Run `git checkout main`

If main doesn't exist, try `git checkout master`.

## Phase 3: Delete Stale Branches

### Step 3.1: Delete branches with gone upstreams

Find and delete all local branches whose upstream tracking branch is marked as `[gone]`:

```bash
git for-each-ref --format '%(refname:short) %(upstream:track)' refs/heads | while read branch track; do
  if [ "$track" = "[gone]" ]; then
    git branch -D "$branch"
  fi
done
```

Track which branches were deleted for the summary.

## Phase 4: Confirm Success

### Step 4.1: Show final state

Run `git status` and `git branch -vv` to show:
- Current branch (should be main)
- All remaining local branches
- Working tree status

### Step 4.2: Report summary

Report to the user:
- How many branches were deleted (list their names)
- Current branch status
- Any issues encountered during sync

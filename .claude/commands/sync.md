---
description: Sync with remote using rebase and prune dead references
allowed-tools: Bash(git:*)
model: haiku
---

## Workflow

### Step 1: Check current branch and status

Run `git status` to see:

- Current branch name
- Whether the branch has an upstream tracking branch
- Modified files (staged and unstaged)
- Untracked files

If the current branch has no upstream tracking branch, inform the user and exit.

### Step 2: Stash uncommitted changes if needed

If there are any modified files (staged or unstaged) or untracked files:

- Run `git stash push --include-untracked -m "Auto-stash for sync"`
- Remember that we stashed (for Step 5)

If the working tree is clean, skip stashing and remember that we did NOT stash.

### Step 3: Fetch with prune

Run `git fetch --prune` to:

- Fetch latest changes from the remote
- Prune remote-tracking branches that no longer exist on the remote

**IMPORTANT:** This does NOT delete local branches, only remote-tracking references.

### Step 4: Update all tracked local branches

Run `git branch -vv` to find all local branches with upstream tracking branches.

For each local branch that has an upstream and is not the current branch:

- If the branch can be fast-forwarded, update it with `git fetch origin remote_branch:local_branch`
- Skip branches that would require a merge (not fast-forward)

This ensures your local `main` and other branches stay up to date without switching branches.

### Step 5: Rebase current branch on upstream

Run `git rebase` to rebase the current branch on its upstream tracking branch.

If the rebase fails with conflicts:

- Run `git rebase --abort` to abort the rebase
- If we stashed in Step 2, run `git stash pop` to restore changes
- Inform the user about the conflict and suggest they resolve manually
- Exit with failure

### Step 6: Restore stashed changes

If we stashed in Step 2:

- Run `git stash pop` to restore the stashed changes

If stash pop fails with conflicts:

- Inform the user that they need to resolve the stash conflicts manually
- The stash is still available via `git stash list`

### Step 7: Confirm success

Run `git status` to show the final state and confirm:

- Working tree status
- Whether the branch is ahead/behind/up-to-date with upstream

Report success to the user with a summary of what was done.

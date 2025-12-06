---
description: Force merge a PR using admin privileges
allowed-tools: Bash(gh:*)
model: haiku
---

Merge a pull request using admin privileges with squash merge.

## Input

Optional argument: `$ARGUMENTS` (PR number)

- If a PR number is provided, use that PR
- If no PR number provided, find the PR for the current branch using `gh pr list --head <current-branch> --json number`

## Steps

1. **Get PR number**

   If `$ARGUMENTS` is empty, get the current branch name and find its PR:

   ```
   gh pr list --head <branch> --json number --jq '.[0].number'
   ```

   If no PR exists for the current branch, output an error and stop.

2. **Check PR status**

   Get the PR state using `gh pr view <number> --json state,isDraft`

3. **Mark ready if draft**

   If the PR is a draft, mark it ready: `gh pr ready <number>`

4. **Merge with admin privileges**

   Use squash merge with admin flag to bypass branch protection:

   ```
   gh pr merge <number> --squash --admin
   ```

5. **Confirm merge**

   Output the merged PR URL and confirm success.

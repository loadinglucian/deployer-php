---
description: Remove AI-generated code slop from the current branch
argument-hint: [base-branch]
---

# Remove AI Code Slop

Check the diff against $1 (or main if not specified), and remove all AI-generated slop introduced in this branch.

This includes:

- Extra comments that a human wouldn't add or are inconsistent with the rest of the file
- Extra defensive checks or try/catch blocks that are abnormal for that area of the codebase (especially if called by trusted / validated codepaths)
- Casts to any to get around type issues
- Any other violation inconsistent with the rest of the file or our rules

Report at the end with only a 1-3 sentence summary of what you changed

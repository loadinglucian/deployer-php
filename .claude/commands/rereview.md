---
description: Fetch PR comments and assess concerns
allowed-tools: Bash(gh:*), Bash(jq:*), Task
model: haiku
---

Fetch PR comments and delegate each to a peer-rereviewer agent for assessment.

## Steps

1. Get PR info using `gh pr view --json number,headRepository`
2. Get repository owner/name using `gh repo view --json nameWithOwner`
3. Fetch PR-level comments: `gh api /repos/{owner}/{repo}/issues/{number}/comments`
4. Fetch review comments: `gh api /repos/{owner}/{repo}/pulls/{number}/comments`
5. Filter out bot comments and auto-generated content (look for bot usernames, "[bot]" suffix, auto-generated summaries)
6. For each substantive comment, spawn a `peer-rereviewer` agent using the Task tool

## Agent Delegation

For each substantive comment, use the Task tool with `subagent_type: "peer-rereviewer"` and include:

- The comment text
- The file path and line number (if available)
- The diff hunk (if available)
- The author's username

**IMPORTANT:** Spawn ALL agents in parallel using a single message with multiple Task tool calls.

Example prompt for each agent:

```
Assess this PR comment:

**Author:** @username
**File:** path/to/file.php:123

```diff
[diff_hunk]
```

**Comment:**
> [comment text]

Read the relevant code file and CLAUDE.md, then provide your assessment.

```

## Output Format

After all agents complete, summarize the results:

### Summary

- **Total comments:** X
- **Valid (implement):** X
- **Valid (consider):** X
- **Invalid:** X

### Details

For each agent result, include:
- File and line reference
- Verdict
- Brief recommendation

## Guidelines

- Skip bot comments (usernames ending in `[bot]`, containing "bot", or common CI bots)
- Skip auto-generated content (dependency updates, changelog entries)
- If no substantive comments found, report "No actionable comments found."
- Each agent runs independently - they will read code and CLAUDE.md themselves

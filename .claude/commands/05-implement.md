---
description: Implement code from implementation plan
model: opus
allowedTools: ['Read', 'Write', 'Edit', 'Task', 'Glob', 'Grep', 'AskUserQuestion']
---

# Plan Implementation - Conductor

Orchestrate milestone implementation by delegating to implementer agents.

## Process

1. Read `docs/{feature}/04-PLAN.md` and `docs/{feature}/03-SPEC.md`
2. Create `05-IMPLEMENTATION.md` tracking document
3. For each milestone: delegate to milestone-implementer agent
4. Collect results and update tracking document
5. Run quality-gatekeeper agent once after all milestones complete
6. Finalize tracking document with summary

## Step 1: Read Plan and Spec

Read both files to extract:

- **From 04-PLAN.md:** Context sections, Prerequisites, all Milestones
- **From 03-SPEC.md:** Full spec content (passed to each agent)

## Step 2: Create Tracking Document

Initialize `docs/{feature}/05-IMPLEMENTATION.md`:

```markdown
# Implementation - {Product Name}

**Source:** [PRD](./01-PRD.md) | [FEATURES](./02-FEATURES.md) | [SPEC](./03-SPEC.md) | [PLAN](./04-PLAN.md)

**Status:** In Progress

## Progress

| Milestone | Status | Files Changed |
| --------- | ------ | ------------- |
| 1: {Name} | -      | -             |
| 2: {Name} | -      | -             |
...

## Milestone Log

{To be populated as milestones complete}

## Summary

{To be populated when all milestones complete}
```

## Step 3: Delegate Milestones

For each milestone, build a context block and invoke the implementer agent.

### Context Block Template

Build this complete context for each agent:

````markdown
# Milestone Implementation Context

## From PRD

{Copy verbatim from 04-PLAN.md Context section}

## From Features

{Copy verbatim from 04-PLAN.md Context section}

## From Spec

{Paste FULL 03-SPEC.md content here}

## Reference Patterns

{Copy from 04-PLAN.md Prerequisites section}

## Milestone Details

### Milestone {N}: {Name}

| Features | {feature list from milestone} |
| -------- | ----------------------------- |

**Deliverables:**

{Copy from milestone}

**Steps:**

{Copy from milestone}

**Integration:**

{Copy from milestone}

**Verification:**

{Copy from milestone}
````

### Agent Invocation

**Sequential milestones:**

```
Use Task tool with:
- subagent_type: "implementer"
- prompt: {context block above}
```

Wait for completion before proceeding to next milestone.

**Parallel milestones (e.g., 3a, 3b):**

When milestones have the `| Parallel | {other} |` row, invoke multiple Task tools in a SINGLE message:

```
Task 1: subagent_type: "implementer", prompt: {context for 3a}
Task 2: subagent_type: "implementer", prompt: {context for 3b}
```

Both agents run simultaneously. Wait for all to complete before proceeding.

## Step 4: Process Results

After each agent completes, parse the YAML result block from its response:

```yaml
milestone: {number}
name: {name}
status: complete | failed
files_changed: [...]
verification: [...]
issues: [...]
```

### Update Tracking Document

Add milestone log entry:

```markdown
### Milestone {N}: {Name}

**Status:** Complete | Failed | Skipped

**Files:**

| Type | File     | Changes              |
| ---- | -------- | -------------------- |
| New  | `{path}` | {from agent result}  |
| Mod  | `{path}` | {from agent result}  |

**Verification:**

- [x] {criterion} {if passed: true}
- [ ] {criterion} {if passed: false}

**Issues:** {if any from agent result}
```

Update Progress table with new status and file count.

### Handle Failures

If `status: failed`:

1. Update tracking document with partial results
2. Display issues from agent result
3. Ask user how to proceed:

```
Milestone {N} failed: {issues}

How to proceed?
- retry: Re-run the milestone agent
- skip: Skip this milestone (may break dependents)
- abort: Stop implementation
```

If user chooses `skip` and milestone has dependents (check `Enables:` field), warn:

```
Warning: Milestone {N} enables Milestones {X, Y}. Skipping may cause them to fail.
Continue with skip? [yes/no]
```

## Step 5: Run Quality Gates

After ALL milestones complete successfully:

```
Use Task tool with:
- subagent_type: "quality-gatekeeper"
- prompt: "Run quality gates on these files: {list all files from all milestone results}"
```

### Handle Quality Failures

If quality-gatekeeper reports failures:

1. Display the issues to user
2. Ask: "Fix issues manually and retry quality gates, or proceed anyway?"
3. Update tracking document with quality gate status

## Step 6: Finalize

When all milestones complete and quality gates pass:

1. Update `05-IMPLEMENTATION.md` status to "Complete"
2. Fill in Summary section:

```markdown
## Summary

**Files Created:**

- `{path}` - {purpose} {aggregated from all milestone results}

**Files Modified:**

- `{path}` - {changes} {aggregated from all milestone results}

**Quality Gates:** Passed | Failed (with details)

**Manual Testing Required:**

- [ ] {from 04-PLAN.md Completion Criteria}

**Notes:**

- {any deviations noted during implementation}
- {any issues that were skipped}
```

## Dependency Parsing

Parse milestones from 04-PLAN.md to build execution order:

1. Find all `### Milestone {N}:` sections
2. Check for `| Parallel | {other} |` rows to identify parallel groups
3. Check `**Enables:** Milestone {N}` to build dependency graph
4. Execute in dependency order: no milestone runs before its dependencies complete

## Error Recovery

**Agent timeout or crash:**

1. Note partial completion in tracking document
2. Ask user: retry or abort

**Conflicting parallel results:**

If parallel milestones both modify the same file (shouldn't happen with good planning):

1. Report conflict to user
2. Ask which result to keep or abort

## Important Notes

- Skills are NOT loaded in this conductor - they load in each implementer agent
- Quality gates run ONCE at the end, not per milestone
- Full spec is passed to each agent - ensures complete context
- Proceed autonomously - only ask user on failures
- Tracking document is the source of truth for implementation progress

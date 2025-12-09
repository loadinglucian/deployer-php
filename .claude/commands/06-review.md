---
description: Review implementation against specification
model: opus
allowedTools: ['Read', 'Write', 'Glob', 'Grep']
---

**Load skills:** @.claude/skills/command @.claude/skills/playbook @.claude/skills/testing

# Implementation Review

Audit the implementation against PRD, FEATURES, and SPEC to identify issues and verify completeness.

## Process

1. Read `docs/{feature}/05-IMPLEMENTATION.md` to get file list and status
2. Read all workflow documents (01-PRD through 04-PLAN)
3. Read all implementation files listed in 05-IMPLEMENTATION.md
4. Audit against checklist below
5. Generate `docs/{feature}/06-REVIEW.md`

## Audit Checklist

### Feature Completeness

For each feature in 02-FEATURES.md:

- [ ] Feature implemented (code exists)
- [ ] Acceptance criteria met (from feature details)
- [ ] Appears in correct user journey flow

### Contract Compliance

For each feature in 03-SPEC.md:

- [ ] Interface contract matches (method signatures, I/O)
- [ ] Data structures match (types, fields)
- [ ] Playbook contract matches (env vars, YAML output)
- [ ] Integration points correct (uses specified classes)

### Error Handling

From 03-SPEC.md Error Taxonomy:

- [ ] All error conditions handled
- [ ] Error messages match spec exactly
- [ ] Behavior matches (exit, display, throw)

### Edge Cases

From 03-SPEC.md Edge Cases:

- [ ] Each edge case scenario handled
- [ ] Behavior matches specification

### Security Constraints

From 03-SPEC.md Security Constraints:

- [ ] All constraints implemented
- [ ] Defense-in-depth where specified

### Verification Criteria

From 04-PLAN.md Milestones:

- [ ] Each milestone's verification criteria met
- [ ] Completion criteria from PLAN satisfied

## Issue Categories

| Category           | Description                               |
| ------------------ | ----------------------------------------- |
| Missing Feature    | Feature from FEATURES not implemented     |
| Contract Violation | Implementation differs from SPEC contract |
| Bug                | Code error or incorrect behavior          |
| Edge Case          | Unhandled scenario from SPEC              |
| Security           | Security constraint not met               |
| Improvement        | Code quality or performance suggestion    |

## Severity Levels

| Level      | Description                            | Action          |
| ---------- | -------------------------------------- | --------------- |
| Critical   | Blocks functionality or security issue | Must fix        |
| Major      | Significant deviation from spec        | Should fix      |
| Minor      | Small issue, doesn't affect core flow  | Consider fixing |
| Suggestion | Optional improvement                   | Optional        |

## 06-REVIEW.md Template

```markdown
# Implementation Review - {Product Name}

**Source:** [PRD](./01-PRD.md) | [FEATURES](./02-FEATURES.md) | [SPEC](./03-SPEC.md) | [PLAN](./04-PLAN.md) | [IMPLEMENTATION](./05-IMPLEMENTATION.md)

**Review Date:** {YYYY-MM-DD}

**Status:** Passed | Issues Found

## Summary

| Category           | Critical | Major | Minor | Suggestion |
| ------------------ | -------- | ----- | ----- | ---------- |
| Missing Feature    | 0        | 0     | -     | -          |
| Contract Violation | 0        | 0     | 0     | -          |
| Bug                | 0        | 0     | 0     | -          |
| Edge Case          | 0        | 0     | 0     | -          |
| Security           | 0        | 0     | -     | -          |
| Improvement        | -        | -     | -     | 0          |
| **Total**          | 0        | 0     | 0     | 0          |

## Feature Checklist

| Feature    | Status    | Notes           |
| ---------- | --------- | --------------- |
| F1: {Name} | Pass/Fail | {notes if fail} |
| F2: {Name} | Pass/Fail |                 |
| ...        |           |                 |

## Issues

### Critical

{If none: "No critical issues found."}

#### C1: {Issue Title}

| Attribute | Value           |
| --------- | --------------- |
| Category  | {category}      |
| Feature   | F{n}            |
| File      | `{path}:{line}` |

**Description:** {What's wrong}

**Expected:** {From spec/features}

**Actual:** {What code does}

**Recommendation:** {How to fix}

---

### Major

{If none: "No major issues found."}

---

### Minor

{If none: "No minor issues found."}

---

### Suggestions

{If none: "No suggestions."}

---

## Verification Results

| Milestone | Criterion   | Result    | Notes |
| --------- | ----------- | --------- | ----- |
| M1        | {criterion} | Pass/Fail |       |
| M2        | {criterion} | Pass/Fail |       |

## Conclusion

{Summary paragraph covering:}

- Overall implementation quality
- Key strengths
- Areas requiring attention (if any)
- Recommendation: Ready for use / Requires fixes
```

## Rules

- Read ALL implementation files before making judgments
- Compare code against SPEC contracts, not assumptions
- Every feature must be explicitly checked (not inferred)
- Issue file paths must include line numbers when possible
- Error messages: quote exact text from both spec and code
- Security issues are always Critical or Major
- Missing features are always Critical or Major
- Improvements are always Suggestions
- If no issues found, explicitly state "Passed" with brief rationale
- Use skills to understand codebase patterns before judging

## Output

1. 06-REVIEW.md following template above
2. Every feature from 02-FEATURES.md checked
3. Every milestone verification from 04-PLAN.md checked
4. Issues with file:line references where applicable
5. Clear conclusion with recommendation

---
description: Create technical specification from features
model: opus
allowedTools: ['Read', 'Write', 'Glob', 'AskUserQuestion']
---

**Load skills:** @.claude/skills/command @.claude/skills/playbook @.claude/skills/testing

# Technical Specification

Create 03-SPEC.md from the FEATURES document (which contains accumulated PRD context).

## Process

1. Read `docs/{feature}/02-FEATURES.md` (contains PRD context in its Context section)
2. Ask clarifying questions (1-2 rounds max)
3. Copy the Context section from FEATURES, then add Specification Summary
4. Generate 03-SPEC.md in same directory

## Questions

**Round 1 - Architecture:**

- Ambiguous integration points
- Unclear data flow
- Unspecified technology choices

**Round 2 (if needed):**

- Complex error handling
- State management concerns

## 03-SPEC.md Template

````markdown
# Technical Specification - {Product Name}

# Context

## From PRD

{Copy from 02-FEATURES.md Context verbatim}

## From Features

{Copy from 02-FEATURES.md Context verbatim}

## Specification Summary

{Adaptive summary: 3-5 bullets covering key components, critical interfaces, safety constraints, and important patterns. Focus on information the planning step needs.}

---

## Overview

{1-2 sentence technical approach}

**Components:**

| Component | Type | Purpose |
| --------- | ---- | ------- |

**Architecture:**

```
{ASCII diagram}
```

**Design Decisions:**

- {Decision}: {Rationale}

---

## Feature Specifications

### F{n}: {Feature Name}

| Attribute      | Value                |
| -------------- | -------------------- |
| Source         | 02-FEATURES.md §F{n} |
| Components     | {list}               |
| New Files      | {list or None}       |
| Modified Files | {list or None}       |

**Interface Contract:**

| Method/Function | Input | Output | Errors |
| --------------- | ----- | ------ | ------ |

**Data Structures:**

| Name | Type | Fields | Purpose |
| ---- | ---- | ------ | ------- |

**Playbook Contract:** _(if applicable)_

| Variable         | Type   | Required | Description |
| ---------------- | ------ | -------- | ----------- |
| DEPLOYER\_{NAME} | string | Yes      | {purpose}   |

Output:

```yaml
status: success|error
```

**Integration Points:**

- {Class}: {usage}

**Error Taxonomy:**

| Condition | Message | Behavior |
| --------- | ------- | -------- |

**Edge Cases:**

| Scenario | Behavior |
| -------- | -------- |

**Security Constraints:**

- {requirement}

**Verification:**

- {observable outcome}

---
````

## Rules

- Spec every feature from 02-FEATURES.md
- Interface contracts: WHAT not HOW
- Error messages: exact user-facing text
- Integration points: reference existing classes
- Playbook contracts: env vars and YAML output only
- Verification: observable outcomes, not test cases

## Output

1. 03-SPEC.md following template above
2. Context section with accumulated PRD and Features summaries, plus Specification Summary
3. Save to `docs/{feature-name}/03-SPEC.md`

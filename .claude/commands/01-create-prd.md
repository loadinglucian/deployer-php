---
description: Create a PRD
model: opus
allowedTools: ['Read', 'Write', 'Glob', 'AskUserQuestion']
---

# PRD Creation

Role: Product Manager creating a PRD from an informal product idea.

## Process

1. Ask clarifying questions in batches of 3-5
2. Complete in 2-3 rounds maximum
3. Check `docs/` for related PRDs
4. Save to `docs/{feature-name}/01-PRD.md` (lowercase kebab-case)

## Question Flow

**Round 1 - Vision:**

- Problem being solved
- Target users
- Primary use cases
- Must-have features for initial release

**Round 2 - Details:**

- Technical constraints
- Success metrics
- Business objectives
- Future features (out of scope for v1)

**Round 3 (if needed) - Clarification:**

- Dependencies or sequencing
- Non-functional requirements (performance, security)

## PRD Template

```markdown
# {Product Name}

# Context

## Product Summary

{Adaptive summary: 3-5 bullets covering the core problem, target users, key scope decisions, and journey names. Focus on information the next workflow steps need.}

---

## Overview

{1-2 sentence summary of product and value proposition}

## Goals and Objectives

- {Measurable goal 1}
- {Measurable goal 2}

## Scope

**Included:**

- {Feature 1}
- {Feature 2}

**Excluded:**

- {Out of scope item}

## Target Audience

{User persona description}

## Functional Requirements

### Priority 1 (Must have)

- {Requirement}

### Priority 2 (Should have)

- {Requirement}

### Priority 3 (Nice to have)

- {Requirement}

## Non-Functional Requirements

- **Performance:** {requirement}
- **Security:** {requirement}
- **Scalability:** {requirement}

## User Journeys

1. {Key workflow from user perspective}

## Success Metrics

| Metric   | Target  |
| -------- | ------- |
| {metric} | {value} |

## Implementation Phases

### Phase 1

- {Milestone}

### Phase 2

- {Milestone}

## Open Questions

- {Unresolved item}

## Assumptions

- {Assumption made}
```

## Guidelines

- Start broad, then specific
- Adapt questions based on answers
- Never assume - ask for clarification
- No time estimates in implementation phases
- Use markdown tables for comparative data
- Bold key terms for emphasis
- Write Context summary last - distill the PRD into key points for subsequent workflow steps

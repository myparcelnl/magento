---
name: requirements-driven-design
description: Use for medium-to-large features. Guides the full journey from idea exploration through structured requirements specification (BR, FR, TR, ADR, US), ticketing integration, implementation, and verification. Ensures nothing is built without clear, traceable requirements.
---

# Requirements-Driven Design

## Overview

A structured approach for building features where requirements are the source of truth. Uses the documentation framework (BR → FR → TR/ADR → US) to specify features thoroughly before implementation. Proven to enable 80%+ oneshot implementation success.

**Flow:** Brainstorm → Context Brief → Specify → Approve → Push to Ticketing → Execute → Verify

```
┌─────────────┐    ┌───────────────┐    ┌─────────────┐
│ Brainstorm  │───►│ Context Brief │───►│   Specify   │
│  (explore)  │    │  (handoff)    │    │ (framework) │
└─────────────┘    └───────────────┘    └──────┬──────┘
                                               │
┌─────────────┐    ┌───────────────┐    ┌──────▼──────┐
│   Execute   │◄───│Push to Tickets│◄───│   Approve   │
│   (build)   │    │  (ticketing)  │    │  (review)   │
└──────┬──────┘    └───────────────┘    └─────────────┘
       │
┌──────▼──────┐
│   Verify    │
│  (tests)    │
└─────────────┘
```

## When to Use This Skill

- Features that touch multiple components (backend + frontend)
- Features with user-facing configuration or settings
- Features where "what to build" isn't immediately obvious
- Features you want to get right the first time
- Any medium-to-large initiative

## Phase 1: Brainstorm (Explore the Idea)

**Goal:** Understand what we're building through collaborative dialogue, research, and iterative refinement.

**This phase is a cycle, not a one-pass interview:**

```
┌──────────────┐     ┌──────────────┐
│  Brainstorm  │◄───►│   Research   │
│  (questions) │     │  (explore)   │
└──────┬───────┘     └──────────────┘
       │ (when ready)
┌──────▼───────┐
│Context Brief │
└──────────────┘
```

**Process:**
1. **Load personal calibration** — read `~/.claude/personal-calibration.md` and apply its interaction rules throughout the session. If the file doesn't exist, ask: "Want to set up a quick personal calibration? (yes/no)". If yes, ask these multiple choice questions one at a time, then save to `~/.claude/personal-calibration.md`. If no, skip.
   - "How do you prefer to work?" (Big picture first, details later | Step by step, one thing at a time | Depends on the problem)
   - "What should I help you most with?" (Staying focused and on scope | Breaking things down into specifics | Catching edge cases and gaps | Pushing back on assumptions)
   - "Technical strengths?" (Backend/infrastructure | Frontend/UX | Full-stack | Architecture/systems design)
2. **Create the brainstorm document** — immediately create `docs/plans/YYYY-MM-DD-<feature>-brainstorm.md` and update it throughout this phase (see Brainstorm Document below)
3. Ask ONE question at a time to refine the idea
4. Prefer multiple choice questions (easier to answer)
5. Focus on: purpose, constraints, success criteria, edge cases
6. Propose 2-3 approaches when alternatives exist
7. Lead with your recommendation and explain why
8. **Research only with user approval** — do NOT explore the codebase automatically. If you need to check something, ask the user first: "I'd like to check [specific thing] in the codebase. Should I?" The user knows their project and may already have the answer. Codebase exploration burns tokens and should be intentional.
9. **Update the brainstorm document** after every significant decision or finding

**Brainstorm Document:**

Create and maintain `docs/plans/YYYY-MM-DD-<feature>-brainstorm.md` throughout this phase. This document is critical because brainstorming sessions can be long and context compression may lose earlier decisions.

```markdown
# Brainstorm: [Feature Name]

**Date:** YYYY-MM-DD
**Status:** In Progress | Ready for Context Brief

## Problem Statement
[Updated as understanding grows]

## Decisions Made
- [Decision 1]: [What was decided and why]
- [Decision 2]: [What was decided and why]

## Key Findings (Research)
- [Finding 1]: [What was discovered and its implications]
- [Finding 2]: [What was discovered and its implications]

## Open Questions
- [Question still to resolve]

## Approach
[Current preferred approach, updated as brainstorming progresses]

## Scope
### In Scope
- [Item]

### Out of Scope
- [Item]

## Constraints
- [Constraint identified during brainstorming]
```

**Update this document:**
- After every decision is made (add to "Decisions Made")
- After every research finding (add to "Key Findings")
- When new questions emerge (add to "Open Questions")
- When scope changes (update "In Scope" / "Out of Scope")
- Remove items from "Open Questions" as they get answered

**Out-of-Scope Triage:**

Before exiting brainstorming, review every item in the "Out of Scope" section **one by one** with the user. For each item, ask:

> "[Item X] was marked out of scope. What should we do with it?"
> 1. **Create a ticket** — capture it as a future issue in the project's issue tracker
> 2. **Add a reminder** — note it in the brainstorm document for later consideration
> 3. **Drop it** — not worth tracking

Update the brainstorm document with the disposition of each item:

```markdown
## Out of Scope (Triaged)
- [Item 1]: Ticket created
- [Item 2]: Reminder — revisit after v1
- [Item 3]: Dropped — not needed
```

**Exit Criteria:**
- Clear understanding of what the feature does
- Major decisions made (approach, scope, constraints)
- Brainstorm document has no remaining open questions
- All out-of-scope items triaged (ticket, reminder, or dropped)
- Ready to produce a context brief

**Transition:** "I think I understand what we're building. Let me produce the context brief for the requirements framework."

---

## Phase 2: Context Brief (Handoff to Orchestrator)

**Goal:** Produce a structured context brief that the orchestrator uses to route to specialists.

**Context Brief Format:**
```markdown
## Context Brief

**Problem Statement:** [What we're solving]
**Stakeholder:** [Who needs this - business leadership, end user, engineering]
**Entry Point:** BR | US | TR | ADR
**Success Criteria:** [How we know it's done - measurable outcomes]
**Constraints:** [Technical, business, timeline limitations]
**Related Docs:** [Existing BRs, FRs, TRs, ADRs to reference]
**Recommended Documents:**
- BR: [yes/no - reason]
- FR: [list of expected FRs to create]
- TR: [yes/no - what criteria need specifying]
- ADR: [yes/no - what decisions need documenting]
- US: [list of key user interactions to capture]
```

**Save to:** `docs/plans/YYYY-MM-DD-<feature>-context-brief.md`

**Transition:** "Context brief ready. Routing to the requirements orchestrator for specification."

---

## Phase 3: Specify (Framework Document Creation)

**Goal:** Create the full requirements document set using specialist agents.

**Process:**
1. Hand the context brief to the `requirements-orchestrator` agent
2. The orchestrator routes to specialist agents based on the entry point:
   - `business-requirements` agent → Creates BR documents
   - `functional-requirements` agent → Creates FR documents (must trace to BR/US)
   - `technical-requirements` agent → Creates TR documents (only if measurable criteria needed)
   - `architectural-decisions` agent → Creates ADR documents (only if significant decisions)
   - `user-stories` agent → Creates US documents (Given/When/Then acceptance criteria)
3. Each specialist enforces its own rules (orphan prevention, duplication detection, gatekeeping)
4. All documents saved to `docs/<type>/` directories

**Document Hierarchy:**
```
BR (Business Requirement) → Epic in ticketing system
├── FR (Functional Requirement) → Issue (sub-issue of Epic)
│   ├── References: TR (Technical Requirement) → Document in ticketing system
│   ├── References: ADR (Architectural Decision) → Document in ticketing system
│   └── Contains: US acceptance criteria (Given/When/Then) → Checklist in FR issue
```

> **Note:** The ticketing system (e.g., Linear, Jira, GitHub Issues) is configured per-project in CLAUDE.md. This skill is agnostic to the specific tool used.

**Key Framework Rules:**
- Every FR must trace to a parent BR or US (no orphans)
- Link to TRs/ADRs, never duplicate their content
- TRs are only created for measurable criteria beyond standard practices
- ADRs are only created for significant decisions with multiple viable alternatives
- ADRs are immutable once accepted (supersession pattern for changes)
- Check for existing documents before creating new ones (reuse)

**Templates:** `docs/templates/00-reference-guide.md` (master guide), `01-05` (individual templates)

**Exit Criteria:**
- All necessary documents created and cross-referenced
- Traceability matrix shows no gaps
- Orchestrator health check passes

**Transition:** "All requirements documents are complete and cross-referenced. Ready for your review."

---

## Phase 4: Approve (Review Documents)

**Goal:** User validates all requirement documents before pushing to the ticketing system.

**Process:**
1. Present summary of all created documents with key details
2. Show the traceability matrix (BR → FR → TR/ADR → US)
3. Walk through each document category:
   - Are BRs capturing the right business need?
   - Do FRs cover all needed capabilities?
   - Are TR thresholds realistic and measurable?
   - Are ADR alternatives fairly evaluated?
   - Do US acceptance criteria cover happy path and edge cases?
4. Address any feedback, update documents as needed
5. Get explicit approval to proceed

**Validation Checklist:**
- [ ] All BRs have downstream FRs
- [ ] All FRs trace to a parent BR or US
- [ ] All referenced TRs and ADRs exist
- [ ] No duplication across documents
- [ ] Acceptance criteria are testable
- [ ] User explicitly approves

**Exit Criteria:**
- User confirms documents are complete and correct
- Ready to push to ticketing system

**Transition:** "Documents approved. Ready to push to the ticketing system?"

---

## Phase 5: Push to Ticketing System (Create Tickets)

**Goal:** Create the ticket hierarchy from approved documents in the project's ticketing system.

> **Configuration:** The ticketing system and its workflow skill are defined in the project's CLAUDE.md. This skill does not assume any specific tool.

**Recommended Mapping:**

| Document | Ticket Type | Details |
|----------|-------------|---------|
| BR | Epic / Initiative | Title = BR title, Description = business context + success criteria |
| FR | Issue (child of Epic) | Title = FR title, Description = FR desc + US acceptance criteria |
| TR | Document / Wiki page | Full TR content, linked from Epic |
| ADR | Document / Wiki page | Full ADR content, linked from Epic |
| US | Checklist in FR issue | Given/When/Then scenarios as checklist items |

**Process:**
1. Create Epic/Initiative from BR
2. Create Issues from FRs (as children of Epic)
   - Embed US acceptance criteria as Given/When/Then checklist in issue description
   - Priority: Must=High, Should=Normal, Could=Low
3. Create Documents from TRs and ADRs
4. Link Documents from Epic description
5. Report all created entities with links

**Use the project's ticketing workflow skill** (e.g., `linear-workflow`) for the actual API calls.

**Transition:** "All tickets created. Ready to start implementation?"

---

## Phase 6: Execute (Build It)

**Goal:** Implement features from ticketed issues.

**Process:**
1. Pick up FR issues in priority order
2. Set issue status to **"In Progress"**
3. Create implementation plan using `superpowers:writing-plans`
   - Each task references which FR/requirements it addresses
   - Group tasks by design component
4. Implement using executor agents or `superpowers:subagent-driven-development`
5. Set issue status to **"In Review"** when implementation complete

**Implementation Rules:**
- Build what's specified in the requirements, nothing more
- If implementation reveals new requirements, add them to the docs first
- Reference requirement IDs in commit messages when relevant
- Previous phase tests must stay green (no regressions)

---

## Phase 7: Verify (Tests from Acceptance Criteria)

**Goal:** Derive tests from acceptance criteria and verify implementation.

**Process:**
1. Extract all Given/When/Then scenarios from US acceptance criteria
2. Write tests that verify each scenario
3. Run tests — they must all pass
4. Run previous phase tests — they must still pass (regression check)
5. If a previous test breaks, explicit justification is required
6. Set FR issue status to **"Done"** when tests pass (in the ticketing system)

**Test Derivation:**
```
Given [precondition from US]     → Test setup / arrange
When [action from US]            → Test action / act
Then [expected result from US]   → Test assertion / assert
```

**Exit Criteria:**
- All acceptance criteria have corresponding tests
- All tests pass
- No regressions in previous tests
- FR issues marked "Done" in the ticketing system

---

## Artifacts Summary

| Phase | Output | Location |
|-------|--------|----------|
| Brainstorm | Brainstorm document (decisions, findings, scope) | `docs/plans/YYYY-MM-DD-<feature>-brainstorm.md` |
| Context Brief | Structured handoff | `docs/plans/YYYY-MM-DD-<feature>-context-brief.md` |
| Specify | BR, FR, TR, ADR, US docs | `docs/<type>/` directories |
| Approve | Validated traceability | (conversation) |
| Push to Tickets | Epic + Issues + Documents | Ticketing system |
| Execute | Code | (codebase) |
| Verify | Tests | (test directories) |

---

## Quick Reference: Phase Transitions

| From | To | Trigger |
|------|----|---------|
| Brainstorm | Context Brief | "I understand. Let me produce the context brief." |
| Context Brief | Specify | "Routing to the requirements orchestrator." |
| Specify | Approve | "All documents complete. Ready for review." |
| Approve | Push to Tickets | "Documents approved. Push to ticketing system?" |
| Push to Tickets | Execute | "Tickets created. Ready to implement?" |
| Execute | Verify | "Implementation complete. Deriving tests from acceptance criteria." |

---

## Anti-Patterns

- **Jumping to implementation** without specifying requirements first
- **Orphan FRs** not tracing to any BR or US
- **Duplicating TR/ADR content** inline in FRs instead of linking
- **Creating TRs for standard practices** (only for measurable criteria)
- **Creating ADRs for trivial decisions** (only for significant architectural choices)
- **Skipping the approval step** and pushing unreviewed docs to the ticketing system
- **Gold-plating** by adding features not in requirements

---

## For Smaller Features

If a feature is small (< 1 day of work), you can abbreviate:

1. **Brainstorm** → Quick clarifying questions
2. **Context Brief** → Mental note of entry point and scope
3. **Specify** → Single FR with acceptance criteria (skip BR if obvious)
4. **Approve** → "This covers X, Y, Z, right?"
5. **Push to Tickets** → Single issue with checklist
6. **Execute** → Build it
7. **Verify** → Tests from acceptance criteria

The discipline scales down, but the traceability mindset remains.

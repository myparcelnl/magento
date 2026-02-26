# User Stories Template

## What They Are

User Stories describe specific functionality from an end-user perspective, focusing on who needs something, what they need, and why. They're the smallest unit of work that delivers value to users.

## When to Create

- During sprint planning
- When breaking down Functional Requirements into implementable work
- When defining specific user interactions
- During backlog refinement sessions

## Key Components

- **User Role**: Who is the user?
- **Goal**: What do they want to accomplish?
- **Benefit**: Why is this valuable to them?
- **Acceptance Criteria**: How we verify it works
- **Story Points**: Estimated effort

## Ownership

Product Owner with input from development team.

---

## Template

```markdown
# US-[NUMBER]: [Brief Title]

## Parent Functional Requirement

- **FR:** [FR-XXX - Title] (link)

## Story

As a [user role],
I want [goal/desire],
So that [benefit/value].

## Acceptance Criteria

### Scenario 1: [Scenario Name]

**Given** [precondition]
**When** [action]
**Then** [expected result]

### Scenario 2: [Scenario Name]

**Given** [precondition]
**When** [action]
**Then** [expected result]

### Scenario 3: [Scenario Name]

**Given** [precondition]
**When** [action]
**Then** [expected result]

## Story Points

**Estimate:** [number]
**Complexity:** [Low / Medium / High]

## Technical Notes

[Any technical considerations, dependencies, or implementation hints.
Reference TRs or ADRs where relevant rather than duplicating content.]

## Dependencies

- [Other User Stories this depends on]
- [External dependencies]

## Definition of Done

- [ ] Code complete and reviewed
- [ ] Unit tests written and passing
- [ ] Acceptance criteria verified
- [ ] Documentation updated
- [ ] Deployed to staging and verified
```

---

## Example

**US-045: View Security Scan Results in PR**

**Parent FR:** FR-003 (Automated Security Scanning)

**Story:**
As a developer,
I want to see security scan results directly in my pull request comments,
So that I can fix issues before merging my code.

**Acceptance Criteria:**

Given a pull request with security vulnerabilities,
When the security scan completes,
Then results appear as a formatted comment showing vulnerability details and remediation suggestions.

---

## INVEST Criteria Checklist

Good User Stories follow the INVEST criteria:

- **I - Independent:** Can be developed and delivered independently of other stories
- **N - Negotiable:** Details can be discussed and refined with the team
- **V - Valuable:** Delivers clear value to the user or business
- **E - Estimable:** Team can estimate the effort required
- **S - Small:** Can be completed within a single sprint
- **T - Testable:** Has clear acceptance criteria that can be verified

---

## Key Reminders

- User Stories describe specific **user interactions**, not system capabilities (that's for FRs)
- Always link back to the parent Functional Requirement
- Use the Given/When/Then format for acceptance criteria to make them testable
- Keep stories small and focused -- if a story is too large, break it down further
- Avoid vague stories like "As a user, I want a good experience" -- be specific and measurable

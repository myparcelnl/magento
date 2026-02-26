# Architectural Decision Record (ADR) Template

## What They Are

ADRs document important technical decisions, including the context, options considered, and rationale for the chosen solution. They capture not just what was decided, but why, preserving institutional knowledge.

## When to Create

- When choosing between multiple viable technical approaches
- Before introducing new technologies or frameworks
- When making decisions with long-term implications
- When deviating from standard practices
- Making significant architectural or technical decisions
- Documenting decisions that impact system design, cost, or compliance
- Recording why certain trade-offs were accepted

**Only create an ADR if:**

- Multiple viable technical approaches exist
- The decision has significant cost, risk, or complexity implications
- The choice will constrain future decisions
- The rationale needs to be documented for future reference

If the implementation approach is straightforward, skip ADR creation.

## Key Components

- **Context**: The situation forcing a decision
- **Decision Drivers**: Criteria for evaluation
- **Considered Options**: Alternatives evaluated
- **Decision Outcome**: What was chosen and why
- **Consequences**: Trade-offs and implications

## Status Definitions

- **Proposed:** Under discussion, not yet approved
- **Accepted:** Approved and being implemented
- **Deprecated:** No longer recommended but still in use
- **Superseded:** Replaced by another ADR (link to new one)

## Ownership

Technical team members making the decision, reviewed by architects.

---

## Template

```markdown
# ADR-[NUMBER]: [DECISION TITLE]

**Status:** [Proposed / Accepted / Deprecated / Superseded by ADR-XXX]
**Date:** [YYYY-MM-DD]
**Decision Makers:** [Names/roles of people involved in the decision]

**Related FRs:** [FR-XXX, FR-XXX] (links)
**Related TRs:** [TR-XXX] (links)
**Related ADRs:** [ADR-XXX] (links to related decisions)

---

## Context

### Background

[Describe the situation that requires a decision. Include relevant technical,
business, or organizational context that helps readers understand why this
decision is necessary.]

### Problem Statement

[Clearly articulate the specific problem or question that needs to be
resolved. This should be concise and focused.]

### Constraints

- [List technical constraints (performance, compatibility, etc.)]
- [List business constraints (budget, timeline, resources)]
- [List regulatory/compliance constraints]
- [List organizational constraints]

---

## Decision Drivers

[Prioritized list of factors that influence the decision. Number them
by priority.]

1. **[Driver name]:** [Description and why it matters] (highest priority)
2. **[Driver name]:** [Description and why it matters]
3. **[Driver name]:** [Description and why it matters]
4. **[Driver name]:** [Description and why it matters]
5. **[Driver name]:** [Description and why it matters]

---

## Considered Options

### Option 1: [Option Name]

**Description:**
[Detailed description of what this option entails]

**Pros:**
- **[Benefit category]:** [Specific benefit and impact]
- **[Benefit category]:** [Specific benefit and impact]
- **[Benefit category]:** [Specific benefit and impact]

**Cons:**
- **[Drawback category]:** [Specific drawback and impact]
- **[Drawback category]:** [Specific drawback and impact]
- **[Drawback category]:** [Specific drawback and impact]

---

### Option 2: [Option Name]

**Description:**
[Detailed description of what this option entails]

**Pros:**
- **[Benefit category]:** [Specific benefit and impact]
- **[Benefit category]:** [Specific benefit and impact]
- **[Benefit category]:** [Specific benefit and impact]

**Cons:**
- **[Drawback category]:** [Specific drawback and impact]
- **[Drawback category]:** [Specific drawback and impact]
- **[Drawback category]:** [Specific drawback and impact]

---

### Option 3: [Option Name]

**Description:**
[Detailed description of what this option entails]

**Pros:**
- **[Benefit category]:** [Specific benefit and impact]
- **[Benefit category]:** [Specific benefit and impact]

**Cons:**
- **[Drawback category]:** [Specific drawback and impact]
- **[Drawback category]:** [Specific drawback and impact]

---

## Decision Outcome

**Chosen Option:** Option [NUMBER] - [Option Name]

### Rationale

[Explain why this option was chosen, referencing the decision drivers and
how this option best satisfies them. Be specific about trade-offs accepted.]

Key points to address:

1. **How it addresses the highest priority drivers**
2. **Trade-offs accepted and why they're acceptable**
3. **Context-specific factors that influenced the choice**
4. **Timeline or phase considerations (e.g., "for the PoC phase")**

**Why we rejected alternatives:**

- **Option [X] ([Name]):** [Specific reasons for rejection]
- **Option [Y] ([Name]):** [Specific reasons for rejection]

### Implementation Approach

**[Component/Layer Name]:**

    [Code example showing key implementation details.
     Focus on interfaces, error handling, or critical logic.]

**[Additional Component if needed]:**

    [Additional implementation details.]

---

## Consequences

### Positive Consequences

- **[Impact area]:** [Specific positive outcome]
- **[Impact area]:** [Specific positive outcome]
- **[Impact area]:** [Specific positive outcome]
- **[Impact area]:** [Specific positive outcome]

### Negative Consequences

- **[Impact area]:** [Specific negative outcome or limitation]
- **[Impact area]:** [Specific negative outcome or limitation]
- **[Impact area]:** [Specific negative outcome or limitation]

---

## Compliance & Security Implications

- **[Compliance aspect]:** [Impact on compliance requirements]
- **[Security aspect]:** [Security considerations or improvements]
- **[Audit aspect]:** [Impact on auditability and traceability]
- **[Data governance]:** [Impact on data handling and sovereignty]

---

## Future Considerations

### When to Revisit This Decision

[List specific triggers that would warrant reconsidering this decision]

1. **[Trigger condition]:** [What would cause reconsideration]
2. **[Trigger condition]:** [What would cause reconsideration]
3. **[Trigger condition]:** [What would cause reconsideration]

### Potential Evolution Path

[Describe how this decision might evolve if conditions change]

- **Phase 1:** [Current state]
- **Phase 2:** [Potential next step with conditions]
- **Phase 3:** [Long-term vision if applicable]

### Monitoring & Alerting

**Critical metrics:**

- [Metric name and what it measures]
- [Metric name and what it measures]
- [Metric name and what it measures]

**Recommended alerts:**

- [Condition] -> [Action/Response]
- [Condition] -> [Action/Response]
- [Condition] -> [Action/Response]
```

---

## Tips for Effective ADRs

1. **Be specific:** Avoid vague language; use concrete examples
2. **Prioritize explicitly:** Make decision drivers ranked and clear
3. **Include code examples:** Show key implementation concepts
4. **Document rejections:** Explain why alternatives weren't chosen
5. **Consider the future:** Include triggers for revisiting the decision
6. **Keep it readable:** Use consistent formatting and clear headings

## Common Decision Driver Categories

- Compliance and regulatory requirements
- Performance and scalability
- Cost and resource efficiency
- Security and data protection
- User experience and accessibility
- Maintainability and simplicity
- Time to market
- Technical debt management

---

## Key Reminders

- ADRs are **stand-alone and immutable** -- document the decision once
- If a decision changes, create a **new ADR** that supersedes the old one (don't edit the original)
- Reference from any related FR, TR, or even other ADRs
- ADRs are about documenting the **"why"** behind decisions, not just the "what"
- Future readers (including yourself) need to understand the context and trade-offs
- Do NOT create separate ADRs for the same decision -- reference the single ADR from multiple FRs

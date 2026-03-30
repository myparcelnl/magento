# Functional Requirements Template

## What They Are

Functional Requirements (FRs) describe specific capabilities or functionalities that the system must provide to meet Business Requirements. They bridge the gap between business needs and technical implementation, describing **WHAT** the system should do without defining exactly *how* it will be implemented.

## When to Create

- After Business Requirements are approved
- When users request specific functionality
- When decomposing Business Requirements into actionable capabilities
- During product roadmap planning

## Key Components

- **Functional Description**: Clear explanation of the system capability
- **Business Justification**: Link to parent Business Requirement(s)
- **User Impact**: Who will use this and how
- **Acceptance Criteria**: How we'll know it's complete
- **Priority**: MoSCoW (Must/Should/Could/Won't) classification

## Ownership

Jointly owned by Product Managers and Technical Leads.

---

## Template

```markdown
# FR-[NUMBER]: [Functional Requirement Title]

## Parent Requirement

- **Business Requirement:** [BR-XXX - Title] (link)
- **Related User Stories:** [US-XXX, US-XXX] (links)

## Description

[What functionality must the system provide? Be specific about the capability
without prescribing the implementation approach.]

## User Impact

[Who will use this capability and what value does it provide? Describe the
affected user groups and how their workflow or experience changes.]

## Acceptance Criteria

- [ ] [Specific, testable criterion 1]
- [ ] [Specific, testable criterion 2]
- [ ] [Specific, testable criterion 3]
- [ ] [Specific, testable criterion 4]

## Priority

**Classification:** [Must Have / Should Have / Could Have / Won't Have]

**Justification:** [Why this priority level? Reference business impact or
dependency chain.]

## Technical Considerations

### Referenced Technical Requirements

- [TR-XXX - Title] (link) -- [Brief note on relevance]
- [TR-XXX - Title] (link) -- [Brief note on relevance]

### Referenced Architectural Decisions

- [ADR-XXX - Title] (link) -- [Brief note on relevance]

### Notes

[Any additional technical context. Do NOT duplicate TR or ADR content here;
reference only.]

## Dependencies

### Upstream (this FR depends on)

- [FR-XXX / External system / Third-party service]

### Downstream (depends on this FR)

- [FR-XXX / US-XXX]

## Cross-References

[List any additional BRs or USs that this FR also serves, beyond the
original parent requirement.]

- **Also implements:** BR-XXX (added [date], reason: [why this FR serves this BR too])
- **Also implements:** US-XXX (added [date])

## Implementation Notes

[High-level guidance for the development team. Reference ADRs for
architectural approach and TRs for specific criteria.]
```

---

## Example

**FR-003: Automated Security Scanning in CI/CD Pipeline**

**Parent BR:** BR-001 (Enable Secure Multi-Team Development Platform)

**Description:** The system shall integrate automated security vulnerability scanning that runs on every code commit and blocks merges if critical vulnerabilities are detected.

**User Impact:** Developers receive immediate feedback on security issues, security team gains visibility into vulnerabilities across all repositories.

**Acceptance Criteria:**

- Security scans run automatically on every PR
- Results appear in PR comments
- Critical vulnerabilities block merge
- Dashboard shows scanning metrics across all repos

**Priority:** Must Have

**Technical Considerations:** May require TR for performance thresholds and ADR for scanning tool selection.

---

## Key Reminders

- Every FR must trace back to either a BR or User Story -- no orphans
- Describe capabilities, not specific user interactions (that's for User Stories)
- Link to TRs and ADRs, never duplicate their content
- Multiple FRs can reference the same TR or ADR
- Check for existing FRs before creating new ones -- reuse where possible

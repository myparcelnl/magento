# Technical Requirements Template

## What They Are

Technical Requirements specify the non-functional requirements and technical constraints that the solution must meet. These include performance benchmarks, security standards, compatibility requirements, and operational characteristics. **Technical Requirements should be reusable across multiple Functional Requirements.**

## When to Create

- During technical design phase
- When Functional Requirements need technical specification
- When integration points require definition
- When performance or security standards must be met

**Only create a TR if:**

- Specific measurable criteria are needed (performance thresholds, scale requirements)
- Security or compliance specifications are required
- Integration standards must be documented
- Quality attributes are critical to success

If no specific technical requirements beyond standard best practices exist, skip TR creation.

## Key Components

- **Performance Criteria**: Speed, throughput, capacity requirements
- **Security Requirements**: Authentication, authorization, encryption needs
- **Compatibility**: Systems and standards to integrate with
- **Reliability**: Uptime, recovery, backup requirements
- **Scalability**: Growth and load expectations

## Ownership

Architects, Senior Engineers, and Security Team.

---

## Template

```markdown
# TR-[NUMBER]: [Technical Requirement Title]

## Related Functional Requirements

- [FR-XXX - Title] (link)
- [FR-XXX - Title] (link)

## Related ADRs

- [ADR-XXX - Title] (link) -- [How this ADR influences this TR]

## Category

[Performance / Security / Compatibility / Reliability / Scalability / Observability]

## Requirement

[Specific, measurable technical requirement statement. Use concrete numbers
and thresholds wherever possible.]

## Rationale

[Why this requirement exists. What business or technical need does it serve?
Reference the parent FRs to explain the context.]

## Specifications

### Performance Criteria

| Metric | Requirement | Measurement Method |
|---|---|---|
| [e.g., Response time] | [e.g., < 500ms at p95] | [e.g., APM monitoring] |
| [e.g., Throughput] | [e.g., 10,000 req/s] | [e.g., Load testing] |
| [e.g., Availability] | [e.g., 99.99% uptime] | [e.g., Uptime monitoring] |

### Security Criteria

- [Encryption requirements]
- [Authentication/authorization standards]
- [Data handling and privacy requirements]
- [Audit logging requirements]

### Compatibility Criteria

- [Required integrations]
- [Protocol standards]
- [API compatibility requirements]

### Scalability Criteria

- [Current load expectations]
- [Growth projections]
- [Scaling triggers and thresholds]

## Verification Method

[How we'll test and verify this requirement is met. Include specific test
approaches, tools, and acceptance thresholds.]

### Test Scenarios

1. **[Scenario name]:** [Description of test and expected outcome]
2. **[Scenario name]:** [Description of test and expected outcome]

### Monitoring

- [Ongoing monitoring approach]
- [Alerting thresholds]
- [Dashboard requirements]

## Assumptions

- [Technical assumptions that underpin these requirements]
- [Infrastructure assumptions]

## Constraints

- [Known technical limitations]
- [Budget or resource constraints affecting technical choices]
```

---

## Example

**TR-007: Security Scan Performance Requirements**

**Related FR:** FR-003 (Automated Security Scanning)

**Category:** Performance

**Requirement:** Security scans must complete within 5 minutes for repositories up to 1GB in size, with linear scaling for larger repositories.

**Rationale:** Developers should receive rapid feedback without significant delays to their development workflow.

**Verification Method:** Performance testing with repositories of varying sizes, monitoring of actual scan times in production.

---

## Key Reminders

- Technical Requirements are **stand-alone and reusable** -- create once, reference many times
- Multiple FRs should link to the same TR rather than duplicating content
- State the requirement, not the solution (implementation details go in ADRs)
- Always include measurable, verifiable criteria
- Update in one place and all linked FRs automatically benefit
- Check for existing TRs before creating new ones

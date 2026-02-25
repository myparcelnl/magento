# Business Requirements Template

## What They Are

Business Requirements define the business need, opportunity, or problem that requires a solution. They focus on business outcomes rather than solutions, establishing the **"WHY"** behind any development effort.

## When to Create

- At the start of any new initiative or project
- When regulatory or compliance needs arise
- When strategic business changes require system support
- When stakeholders identify new market opportunities

## Ownership

Owned and created by Business Stakeholders.

---

## Template

```markdown
# BR-[NUMBER]: [Business Requirement Title]

## Business Context

[Describe the business situation, market conditions, or organizational needs
that drive this requirement. Include relevant background information that
helps readers understand the broader context.]

## Objective

[State the clear business objective this requirement aims to achieve.
Focus on outcomes, not solutions.]

## Business Justification

[Explain why this requirement matters to the business. Include any of the
following that are relevant:]

- Revenue impact or opportunity
- Cost reduction potential
- Competitive advantage
- Regulatory or compliance necessity
- Customer retention or acquisition impact
- Risk mitigation

## Scope

### In Scope

- [Clearly define what is included]
- [Be specific about boundaries]

### Out of Scope

- [Explicitly state what is NOT included]
- [Prevent scope creep by being clear]

## Success Criteria

- [ ] [Measurable criterion 1 - include specific metrics where possible]
- [ ] [Measurable criterion 2]
- [ ] [Measurable criterion 3]

## Stakeholders

| Role | Name | Responsibility |
|---|---|---|
| Business Sponsor | [Name] | Final approval, funding |
| Product Owner | [Name] | Requirements refinement |
| Technical Lead | [Name] | Feasibility assessment |
| End Users | [Group/Team] | Validation and feedback |

## Constraints

- **Budget:** [Budget limitations if applicable]
- **Timeline:** [Deadline or time constraints]
- **Regulatory:** [Compliance requirements]
- **Technical:** [Known technical constraints]

## Dependencies

- [External dependencies]
- [Other BRs or initiatives this depends on]

## Risk Assessment

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| [Risk 1] | High/Medium/Low | High/Medium/Low | [Mitigation strategy] |
| [Risk 2] | High/Medium/Low | High/Medium/Low | [Mitigation strategy] |

## Approval

| Role | Name | Date | Status |
|---|---|---|---|
| Business Sponsor | | | Pending |
| Product Owner | | | Pending |
```

---

## Example

**BR-001: Enable Secure Multi-Team Development Platform**

**Business Context:** ING Bank has 50+ development teams currently using disparate tools (Jenkins, GitLab, TFS), leading to inconsistent security practices and inability to demonstrate SOC 2 compliance across all teams.

**Objective:** Establish a unified development platform that ensures consistent security practices and automated compliance reporting across all development teams.

**Success Criteria:**

- 100% of development teams migrated to unified platform within 6 months
- Automated SOC 2 compliance reports generated monthly
- Reduction in security incidents by 40%

---

## Key Reminders

- Focus on **what** the business needs, not **how** to build it
- Always include measurable success criteria
- Get stakeholder sign-off before decomposing into Functional Requirements
- A single BR may spawn multiple Functional Requirements

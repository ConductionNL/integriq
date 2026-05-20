# {{capability_name}} Specification

**Status**: idea | planned | in-progress | done
**Scope**: company-wide | {{app-name}}
**OpenSpec changes**:
- _(none yet)_

## Purpose
<!-- What this capability does and why it exists (2–5 sentences). Include relevant ADR references. -->

## ADDED Requirements

### REQ-001: {{requirement_name}}
<!-- Use RFC 2119: MUST/SHALL for normative requirements — prefer these over SHOULD/MAY -->
The system MUST ...

#### Scenario: {{scenario_name}}
- GIVEN ...
- WHEN ...
- THEN ...
- AND ...

## Non-Functional Requirements

- **Performance:** <measurable performance requirement>
- **Accessibility:** <WCAG or usability requirement>
- **Internationalization:** Dutch and English MUST be supported (hydra ADR-007)

## Acceptance Criteria

- [ ] <testable criterion>

## Notes

<!-- Open questions, constraints, dependencies, related ADRs. -->

## MODIFIED Requirements

<!-- Only include this section if existing requirements are changing.
     Copy the ENTIRE requirement block from the main spec, then edit it.
     Partial content will lose detail at archive time. Remove this section if unused. -->

### REQ-{NNN}: {{requirement_name}}
<!-- New behavior -->
The system MUST ...
<!-- Previous behavior: The system used to ... -->

#### Scenario: {{scenario_name}}
- GIVEN ...
- WHEN ...
- THEN ...

## REMOVED Requirements

<!-- Only include this section if requirements are being deprecated or deleted.
     ### Requirement: Name
     **Reason**: Why it's being removed
     **Migration**: What to use instead
     Remove this section if unused. -->

## RENAMED Requirements

<!-- Only include this section if requirement names are changing (behavior unchanged).
     Remove this section if unused. -->

### REQ-{NNN}: {{new_requirement_name}}
FROM: {{old_requirement_name}}
TO: {{new_requirement_name}}
<!-- No behavior change — rename only -->

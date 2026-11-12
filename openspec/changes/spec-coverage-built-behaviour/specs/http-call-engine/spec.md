# http-call-engine Specification

## ADDED Requirements

### Requirement: Templated call values are rendered at call time (REQ-SCB-001)

Integriq MUST render every value in a source call's headers, query and body that is written as a template, just before it sends the call. The template MUST see the source's own data. Rendering MUST run in a sandbox that allows only the functions integriq declares.

#### Scenario: a header carries a value from the source's settings
- GIVEN a source with header `X-Tenant: {{ source.configuration.tenant }}` and tenant `zuiddrecht`
- WHEN integriq calls the source
- THEN the request carries `X-Tenant: zuiddrecht`
- @e2e exclude outbound request rendering; covered by PHPUnit on CallService

#### Scenario: a body field is worked out per call
- GIVEN a call whose body holds a field written as a template
- WHEN integriq sends the call
- THEN the body carries the rendered value, not the template text
- @e2e exclude outbound request rendering; covered by PHPUnit on CallService

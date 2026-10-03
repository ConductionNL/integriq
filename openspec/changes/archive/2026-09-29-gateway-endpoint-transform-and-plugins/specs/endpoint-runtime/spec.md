# endpoint-runtime delta

## ADDED Requirements

### Requirement: An endpoint's output mapping reshapes its answer (REQ-GTP-001)

When an endpoint has an `outputMapping`, integriq MUST apply that mapping to the answer body after the endpoint's `after` rules have run. A list answer MUST have each item mapped and MUST keep its pagination fields.

#### Scenario: a consumer gets the partner's shape, not the register's
- GIVEN an endpoint on the `zaak` schema with an output mapping that renames `identificatie` to `zaaknummer`
- WHEN a consumer calls the endpoint for one zaak
- THEN the answer carries `zaaknummer` and not `identificatie`
- @e2e exclude gateway response shape; covered by PHPUnit on EndpointService

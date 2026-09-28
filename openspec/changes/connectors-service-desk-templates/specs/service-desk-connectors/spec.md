# service-desk-connectors Specification

## ADDED Requirements

### Requirement: TOPdesk, ServiceNow and GLPI are source templates with mapping presets (REQ-SDC-001)

Integriq MUST ship dormant source templates `topdesk`, `servicenow` and `glpi` in the catalogue category `Service management`, each with its authentication, its application record endpoint and its paging, and each holding its credential by broker reference only. For each it MUST ship an outbound mapping preset from stackiq's usage fields and an inbound preset that yields the system, record id, record link, name and supplier.

#### Scenario: an application manager links TOPdesk for stackiq
- GIVEN stackiq's `itsm` connection naming `topdesk` as its source template
- WHEN the administrator links a source from the Integrations page
- THEN the TOPdesk template is offered first, and after adding the tenant URL and the credential reference the source is ready for stackiq's flows
- e2e: `tests/e2e/link-itsm-template.spec.ts`

#### Scenario: the nightly inbound flow reads TOPdesk assets
- GIVEN a recorded page of TOPdesk application assets
- WHEN stackiq's inbound flow maps them with `itsm-topdesk-asset-inbound`
- THEN each record yields `system` topdesk, its id, its link, name and supplier
- @e2e exclude mapping behaviour; covered by PHPUnit against a recorded answer

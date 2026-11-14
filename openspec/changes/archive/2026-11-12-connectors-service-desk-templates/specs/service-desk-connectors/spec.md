# service-desk-connectors Specification

## ADDED Requirements

### Requirement: TOPdesk, ServiceNow and GLPI are source templates with two-way mapping presets (REQ-SDC-001)

Integriq MUST ship dormant source templates `topdesk`, `servicenow` and `glpi` in the catalogue category `Service management`, each with its authentication held by credential broker reference only, its record endpoints and its paging. It MUST ship mapping presets that map the desk's application records to stackiq's application fields and back, and for TOPdesk and ServiceNow also relations, licences and contracts into stackiq's fields, plus a spreadsheet preset with stackiq's own field names. It MUST ship a dormant synchronization per paged feed, keyed by slug.

#### Scenario: an administrator links TOPdesk for stackiq
- GIVEN a fresh install
- WHEN the administrator opens the connector catalogue
- THEN TOPdesk, ServiceNow and GLPI are listed under Service management, disabled, with no secret stored on the source
- @e2e exclude seed data; covered by PHPUnit `ServiceDeskConnectorsTest::testSourcesAreDormantAndHoldNoSecret` and `CatalogRegistryServiceTest::testServiceDeskTemplatesAreServiceManagement`

#### Scenario: the nightly inbound flow reads TOPdesk and ServiceNow applications
- GIVEN a page of TOPdesk application assets and a page of ServiceNow `cmdb_ci_appl` records
- WHEN stackiq's inbound flow maps them with `itsm-topdesk-application-inbound` and `itsm-servicenow-application-inbound`
- THEN each record yields its record id, its link, name, supplier, installed version, description and a status from stackiq's status list
- @e2e exclude mapping behaviour; covered by PHPUnit `ServiceDeskConnectorsTest` on recorded answers and by the live run recorded in the PR

### Requirement: Every mapped field has an owner, and an update never overwrites the other side's fields (REQ-SDC-002)

A mapping MAY declare `ownership`, an object from each output field to its owner: `source` for the outside system, or the name of the local app. Every preset this change ships MUST declare an owner for every mapped field. The `openconnector.apply-mapping` step MUST accept `ownership` (`inbound` or `outbound`) together with `exists` (a dot path on the item). When `exists` resolves to an empty value the step MUST keep every mapped field. Otherwise inbound MUST keep only the fields `source` owns, and outbound MUST keep only the fields `source` does not own. In ownership mode a mapping with an unowned field MUST be refused, not run. Without `ownership` the step MUST behave as before.

#### Scenario: the desk wins its own field and a stackiq field survives
- GIVEN a licence that exists in stackiq, whose supplier was renamed and whose seat count was changed in TOPdesk
- WHEN the inbound flow maps it with `ownership: inbound` and `exists` pointing at the stackiq record
- THEN the update carries the new supplier name and no seat count, so stackiq keeps its own seat count
- @e2e exclude flow node behaviour; covered by PHPUnit `ApplyMappingNodeOwnershipTest` and by the live conflict run recorded in the PR

#### Scenario: an outbound update sends only what stackiq owns
- GIVEN an application in use that ServiceNow already knows
- WHEN the outbound flow maps it with `ownership: outbound`
- THEN the body carries the stackiq-owned `u_` fields and no name, vendor, version or install status
- @e2e exclude flow node behaviour; covered by PHPUnit `ServiceDeskConnectorsTest::testOutboundPresetsSendOnlyStackiqFieldsOnUpdate`

### Requirement: A source call sends a mapped object whole (REQ-SDC-003)

The `openconnector.source-call` step MUST accept `bodyFrom`, a dot path on the item, and send the object found there as the JSON body without rendering it again. It MUST refuse `body` and `bodyFrom` together. When the path holds no object on any item, the step MUST fail before any request of the page is sent.

#### Scenario: an outbound create posts the mapped record
- GIVEN an item whose `send` key holds the mapped ServiceNow record
- WHEN a source-call step with `bodyFrom: send` posts to `/api/now/table/cmdb_ci_appl`
- THEN ServiceNow receives exactly that object as the body
- @e2e exclude flow node behaviour; covered by PHPUnit `SourceCallNodeTest::testBodyFromSendsTheObjectAtThatPathWhole` and the live run recorded in the PR

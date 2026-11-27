# synchronization-engine Delta: connectors-hr-data-exchange

## ADDED Requirements

### Requirement: A push can carry the objects that point at the pushed object (REQ-HRX-003)

A push from a `register/schema` source MUST be able to declare, in `sourceConfig.embedRelated`, objects of another register schema to embed before mapping, each found by filters whose values are read from the pushed object. The engine MUST embed them only after the synchronization's conditions hold, MUST embed nothing for a filter that reads no value instead of querying the whole schema, and MUST refuse the push rather than send a partial list when more objects match than the engine's cap.

#### Scenario: a handoff carries its mutations
- GIVEN a push declaring `mutations` as the `PayrollHandoffMutation` objects whose `handoffId` is the pushed object's `id`
- WHEN a handoff with three such mutations is pushed
- THEN the mapping reads the three mutations under `mutations`
- @e2e exclude backend synchronization; covered by PHPUnit on RelatedObjectEmbedder and the payroll set

#### Scenario: a filter reads nothing
- GIVEN a declared relation whose filter path is empty on the pushed object
- WHEN the object is pushed
- THEN the relation is embedded empty and no query is made
- @e2e exclude backend synchronization; covered by PHPUnit on RelatedObjectEmbedder

### Requirement: A pull can move the object its written objects point at (REQ-HRX-004)

A pull into a register schema MUST be able to declare, in `targetConfig.relatedWriteBack`, a related schema, the property of a written object that holds the related object's id, the state the related object must be in, and the fields to set. The engine MUST apply it once per run, after every write of the run, through a normal save so listeners on the related object run, and MUST NOT apply it in a test run. A related object that cannot be read or saved MUST be logged and MUST NOT fail the run.

#### Scenario: two payslips move their handoff once
- GIVEN a `verzonden` handoff and a pull that writes two payslips naming it
- WHEN the run finishes
- THEN the handoff is saved once with status `ontvangen` and `receivedAt`
- @e2e exclude backend synchronization; covered by PHPUnit on RelatedWriteBack and the payroll set

### Requirement: A push can deliver a file to an SFTP or FTPS server (REQ-HRX-005)

A push MUST accept the target types `sftp` and `ftps`. The mapped object MUST name the file and hold its rows; the engine MUST write the rows as CSV with a header row into the configured folder on the target source through the remote file client, so the egress guard, the root path, the host key pin and the brokered credential apply. It MUST refuse a file name that carries a path, MUST record the written path as the contract's target id and as `{{ response.path }}` for the write-back, and a delete MUST remove nothing on the server.

#### Scenario: a handoff becomes a file in the bureau's drop
- GIVEN a push with target type `sftp` and path `incoming`
- WHEN a handoff mapped to `812345671L01-2026-09.csv` with three rows is pushed
- THEN `incoming/812345671L01-2026-09.csv` holds a header and three rows, and the handoff's delivery reference is that path
- @e2e exclude backend synchronization; covered by PHPUnit on RemoteFileTarget and SynchronizationService

### Requirement: A write-back can stamp the time (REQ-HRX-006)

A push's `writeBack` MUST offer `{{ now }}`, the time of the write-back in ISO 8601, beside the outcome placeholders.

#### Scenario: the delivery time is recorded
- GIVEN a push whose write-back sets `deliveredAt` to `{{ now }}`
- WHEN the target accepts the object
- THEN the source object's `deliveredAt` holds the time of the delivery
- @e2e exclude backend synchronization; covered by PHPUnit on SynchronizationService

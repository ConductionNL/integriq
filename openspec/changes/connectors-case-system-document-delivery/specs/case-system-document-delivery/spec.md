# case-system-document-delivery Specification

## ADDED Requirements

### Requirement: A push writes its outcome back onto the object that started it (REQ-CSD-001)

A push synchronization MUST be able to declare fields to set on its source object on success and on failure, filled from the target's answer or the error. The engine MUST write them once per finished attempt, MUST write the failure fields only when the retry budget is spent, and MUST NOT trigger the push again by that write.

#### Scenario: a delivery reaches the case system
- GIVEN a push with write-back `written_back` and the created object's URL
- WHEN the case system answers 201
- THEN the source object has status `written_back` and the URL as its external id, and no second push starts
- @e2e exclude backend synchronization; covered by PHPUnit

#### Scenario: the case system keeps refusing
- GIVEN a push whose target answers 400 on every attempt
- WHEN the retry budget is spent
- THEN the source object has status `writeback_failed` and the case system's message, written once
- @e2e exclude backend synchronization; covered by PHPUnit

### Requirement: A filinq delivery becomes a document in the case system (REQ-CSD-002)

For a filinq `caseSystemDelivery` or a redacted `externalDocument` in `ready_for_writeback`, integriq MUST create a new document in the destination case system with the delivery's metadata and file, over the ZGW Documenten API or StUF-ZDS as the destination source declares. Over ZGW it MUST upload the file in parts when the API asks for it and MUST relate the document to the case when `zaakUrl` is set. A redacted copy MUST carry the title suffix "(geanonimiseerd)" and a reference to the original's identificatie. It MUST never update or replace an existing document, and before creating it MUST reuse a document it already created for the same delivery.

#### Scenario: an HR letter lands in the case file
- GIVEN humaniq generated a letter that filinq stored and queued for the Open Zaak source with a `zaakUrl`
- WHEN the push runs
- THEN an EnkelvoudigInformatieObject with the letter exists, related to that case, and the delivery reads `written_back` with the informatieobject URL
- @e2e exclude backend push; covered by PHPUnit against recorded ZGW exchanges and a dev compose run

#### Scenario: an anonymised copy goes back to a StUF-ZDS case system
- GIVEN a redacted document released for write-back to a StUF-ZDS destination
- WHEN the push runs
- THEN a `voegZaakdocumentToe` message with the title ending in "(geanonimiseerd)" is sent and the returned identificatie is written back
- @e2e exclude backend push; covered by PHPUnit against a recorded StUF answer

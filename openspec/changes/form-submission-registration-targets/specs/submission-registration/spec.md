# submission-registration Specification (delta)

## ADDED Requirements

### Requirement: An administrator defines a registration target of one of four kinds (REQ-SREG-001)

Integriq MUST store a registration target as a `registrationTarget` object of kind `zgw`, `objects-api`, `stuf-zds` or `json`, with its sources, a mapping from the hand-over to the outside shape, the settings of its kind and the apps allowed to use it. Only administrators MUST be able to read or change a target. Integriq MUST answer `GET /api/registration-targets?app={app}` for a signed-in user with the enabled targets that app may use, projected to slug, title and kind.

#### Scenario: an administrator adds the municipality's case system
- **WHEN** an administrator creates target `zaaksysteem-milieu` of kind `zgw` with the Zaken and Documenten sources, a case type url and the RSIN
- **THEN** the target is stored and portaliq's "Doorsturen" picker lists it by title and kind
- @e2e exclude admin modal covered by the integriq admin e2e; the list endpoint is covered by PHPUnit

#### Scenario: the list hides how a target is reached
- **WHEN** a signed-in user asks for the targets of `portaliq`
- **THEN** the answer carries slug, title and kind only, without sources or settings
- @e2e exclude backend endpoint; covered by PHPUnit

### Requirement: An app registers a submission through one typed command (REQ-SREG-002)

Integriq MUST offer `OCA\Integriq\Event\SubmissionRegistrationRequestedEvent` carrying the calling app, a target slug and a hand-over of the submission (reference, form, applicant, answers with field metadata, PDF and attachments as file ids). The listener MUST register the submission at the target and MUST answer `externalReference` and `externalUrl`, or a refusal with a code and a `retryable` flag. It MUST NOT throw to the caller, and it MUST refuse an app outside the target's `allowedApps`.

#### Scenario: a Woo request becomes a case in a ZGW case system
- **WHEN** portaliq hands over submission `OF-7Q2K` to target `zaaksysteem-milieu` of kind `zgw`
- **THEN** integriq creates a case of the configured case type, an initiator role with the applicant's BSN, an informatieobject for the PDF and one per attachment, each linked to the case
- **AND** the answer carries the case identificatie as `externalReference`
- @e2e exclude backend command against a ZGW mock; covered by PHPUnit and a Newman run against Open Zaak in the nightly

#### Scenario: a request lands as an object in the Objects API
- **WHEN** portaliq hands over a submission to a target of kind `objects-api`
- **THEN** integriq creates one object of the configured object type and version whose `record.data` is the mapped submission
- @e2e exclude backend command; covered by PHPUnit with a recorded Objects API answer

#### Scenario: a request becomes a case in a StUF-ZDS case system
- **WHEN** portaliq hands over a submission to a target of kind `stuf-zds`
- **THEN** integriq asks a case identification, sends `creeerZaak_ZakLk01` with the applicant as initiator, and sends `voegZaakdocumentToe_Lk01` for the PDF and each attachment
- @e2e exclude backend SOAP exchange; covered by PHPUnit with recorded StUF answers

#### Scenario: a request is posted as JSON
- **WHEN** portaliq hands over a submission to a target of kind `json` with a JSON Schema
- **THEN** integriq posts the mapped body once, after checking it against the schema
- **AND** a body that does not match is refused as `mapping-failed` with `retryable` false and nothing is posted
- @e2e exclude backend command; covered by PHPUnit

#### Scenario: the case system is down
- **WHEN** the outside system answers 503
- **THEN** the refusal is `source-unavailable` with `retryable` true, which portaliq's retry picks up
- @e2e exclude backend command; covered by PHPUnit

### Requirement: A submission is registered once (REQ-SREG-003)

Integriq MUST record each registration by target and submission reference with the steps already done. A repeated hand-over of a submission whose registration is done MUST answer the stored result without an outside call. A repeated hand-over of a registration that stopped halfway MUST resume after the last completed step and MUST NOT create a case, object or document a second time.

#### Scenario: portaliq retries after a timeout
- **WHEN** the first hand-over created the ZGW case and timed out before the documents, and portaliq hands the submission over again
- **THEN** integriq reuses the case, creates only the missing documents, and answers the same `externalReference`
- @e2e exclude backend command; covered by PHPUnit

#### Scenario: a repeat after success
- **WHEN** a submission whose registration is done is handed over again
- **THEN** the stored result is answered and no outside call is made
- @e2e exclude backend command; covered by PHPUnit

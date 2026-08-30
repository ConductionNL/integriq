# lti-platform Specification Delta

## MODIFIED Requirements

### Requirement: LTI registration model is a catalogue entry, not a menu (REQ-LTI-001)

The system MUST declare three new OpenRegister schemas in the `openconnector`
register — `lti_platform` (an external Platform that may launch into this
instance; this instance acts as Tool), `lti_tool` (an external Tool this
instance may launch; this instance acts as Platform), and `lti_deployment`
(links exactly one `lti_platform` OR one `lti_tool` to a consuming-app
placement: the LTI `deployment_id` claim value, `launchTargetUrl`, a
`gradeSink` and `rosterSource` each expressed as an ADR-008
`targetType`/`targetId` pair, and a mapping reference). The LTI adapter MUST
be delivered as a catalogue entry in the *Adapters* section with these three
schemas as its configuration surface, per ADR-017 Rule 1. It MUST NOT add a
top-level navigation menu or a per-adapter settings page. Tenant-wide signing
key management (REQ-LTI-002) MUST surface under *Beheer > Authenticatie*, per
ADR-017 Rule 3/Rule 7 (the same sanctioned split as
`digid-eherkenning-auth-adapter`).

The `lti_deployment` "exactly one of `lti_platform` OR `lti_tool`" constraint
MUST be enforced not only at write time (the OpenRegister schema `oneOf`) but
also **at read time**, when a deployment is resolved for dispatch. The single
deployment-by-uuid resolution owner (`LtiRegistrationResolverService::findDeploymentByUuid()`,
called by every AGS token-issuance and NRPS roster-read dispatch) MUST re-assert
the single-reference constraint and fail closed (reject the resolution) on any
deployment referencing both registrations or neither — so a row that reached
storage bypassing OR write-time validation cannot resolve to an ambiguous
registration at token-issuance or roster-read time.

@e2e exclude adapter catalogue registration + schema declaration + read-time deployment-resolution gate — covered by PHPUnit, no dedicated browser journey

#### Scenario: LTI ships as an Adapters card referencing three schemas

- **GIVEN** the LTI adapter is installed
- **WHEN** the *Adapters* catalogue is inspected
- **THEN** it SHALL show an "LTI 1.3 / LTI Advantage" catalogue entry backed
  by the `lti_platform`, `lti_tool`, and `lti_deployment` schemas
- **AND** no top-level menu item SHALL be added for it
- @e2e exclude catalogue registration — covered by PHPUnit

#### Scenario: an lti_deployment references exactly one registration

- **GIVEN** an `lti_deployment` record
- **WHEN** it is validated
- **THEN** it SHALL reference exactly one of `lti_platform` or `lti_tool`
  (never both, never neither)
- @e2e exclude schema validation — covered by PHPUnit

#### Scenario: the single-reference constraint is re-asserted at dispatch-time deployment resolution

- **GIVEN** an `lti_deployment` row that references BOTH an `ltiPlatformId`
  and an `ltiToolId` (or neither), reaching storage past OR write-time
  validation
- **WHEN** a live AGS token-issuance or NRPS roster-read dispatch resolves that
  deployment by UUID
- **THEN** resolution SHALL fail closed with an `LtiValidationException`
  rather than returning an ambiguous deployment
- **AND** a well-formed deployment referencing exactly one registration SHALL
  resolve unchanged
- @e2e exclude backend read-time deployment-resolution gate — covered by PHPUnit

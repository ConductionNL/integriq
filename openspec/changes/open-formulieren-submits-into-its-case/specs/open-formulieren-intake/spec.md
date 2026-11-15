# open-formulieren-intake Delta: open-formulieren-submits-into-its-case

**Status**: draft
**Scope**: Open Formulieren inbound and its form mappings. Implements hydra `form-submits-into-its-destination-object`.

## ADDED Requirements

### Requirement: An Open Formulieren mapping MUST be valid against its case schema before it is active

Saving an `openformulieren_form_mapping` SHALL run OpenRegister's form destination validator against the target schema. A mapping with findings SHALL NOT be activated.

#### Scenario: A mapping without the case type is not activated
- **GIVEN** a mapping into dossiq `case` with no source and no fixed value for `caseType`
- **WHEN** an administrator activates it
- **THEN** activation is refused with `required-unmapped` on `caseType`

### Requirement: An Open Formulieren submit MUST create its case in the inbound request

`inbound()` SHALL map the payload and create the case through OpenRegister's submit service in the same request, with the Open Formulieren submission id as idempotency key and `externalReference`. It SHALL answer 201 with the case reference, or 422 with findings. It SHALL store no submission for later processing.

#### Scenario: A valid submit returns the case reference
- **GIVEN** an active mapping and a valid Open Formulieren payload
- **WHEN** it arrives
- **THEN** the response is 201 with the dossiq case reference, and no `openformulieren_submission` object exists

#### Scenario: A redelivered submit creates one case
- **GIVEN** Open Formulieren retries a submit with the same submission id
- **WHEN** it arrives again
- **THEN** the first case's reference is returned and no second case exists

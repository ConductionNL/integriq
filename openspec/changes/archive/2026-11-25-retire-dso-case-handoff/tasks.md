# Tasks: retire-dso-case-handoff

## 1. Retire the handoff

- [x] 1.1 Red: `tests/Unit/Settings/DsoVerzoekDeclaresNoCaseHandoffTest.php` (no handoff on `dso_verzoek` in either register, no handoff route, no handoff method)
- [x] 1.2 Remove the `x-openregister-handoff` block (schema 1.6.0), route `dSO#handoff`, `DSOController::handoff()`, `DsoIngestService::handoff()`, `recordHandoffSuccess()`, `markFailed()`, and the handoff tests
  - `@spec openspec/changes/retire-dso-case-handoff/specs/dso-omgevingsloket/spec.md`

## 2. Verify

- [x] 2.1 Live: a mapped verzoek makes one dossiq case, and the retired endpoint makes none
  > 2026-11-25, live: sandbox Nextcloud 34.0.6 + MariaDB, OpenRegister main 292aa09d, dossiq development 7ba031ca with `dso_vergunningaanvraag_schema` set to integriq's `dso_verzoek` schema. A `dso_verzoek` created `received` makes no case. Updated to `mapped` (`mappedCaseTypes` naming the Omgevingsvergunning case type), it makes one dossiq case: "Omgevingsvergunning: Bouwen schuur Dorpsstraat 1" on that type, with `permitApplicationRef` = the verzoek. A second save of the verzoek leaves it one. `POST /api/dso/verzoeken/{id}/handoff` answers 404, OpenRegister's `/api/objects/integriq/dso_verzoek/{id}/handoffs` lists `[]`, and there is still one case afterwards.

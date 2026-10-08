# Tasks: retire-dso-case-handoff

## 1. Retire the handoff

- [x] 1.1 Red: `tests/Unit/Settings/DsoVerzoekDeclaresNoCaseHandoffTest.php` (no handoff on `dso_verzoek` in either register, no handoff route, no handoff method)
- [x] 1.2 Remove the `x-openregister-handoff` block (schema 1.6.0), route `dSO#handoff`, `DSOController::handoff()`, `DsoIngestService::handoff()`, `recordHandoffSuccess()`, `markFailed()`, and the handoff tests
  - `@spec openspec/changes/retire-dso-case-handoff/specs/dso-omgevingsloket/spec.md`

## 2. Verify

- [ ] 2.1 Live: a mapped verzoek makes one dossiq case, and the retired endpoint makes none

# Tasks: open-formulieren-submits-into-its-case

- [ ] 1.1 Mapping save calls OpenRegister's validator; activation blocked on findings
  - Files: lib/Service/OpenFormulieren/FormFieldMapper.php, mapping controller
- [ ] 1.2 `OpenFormulierenController::inbound` calls `FormSubmitService` with idempotency key and `externalReference`; 201 or 422
  - Files: lib/Controller/OpenFormulierenController.php, lib/Service/OpenFormulierenIntakeService.php
  - Test: unit tests for 201, 422 and the idempotent repeat; a control asserting no staging object is written
- [ ] 1.3 Audit log of payload hash, time and outcome
- [ ] 1.4 `occ integriq:openformulieren:drain`; remove the staging schema and `handoff()` path at zero pending
- [ ] 1.5 DSO mapping check at save time
- [ ] 2.1 `composer check:strict`, `npm run lint`, `openspec validate open-formulieren-submits-into-its-case --strict`

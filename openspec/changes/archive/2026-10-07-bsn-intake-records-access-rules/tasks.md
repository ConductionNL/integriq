Ruben approved the model on 2026-10-04: "intake account + a handler group".

## 1. Proof

- [x] 1.1 Prove the open access live before the change: an ordinary account reads a submission with its BSN and creates both record types (`iof-live/commands.md` run-2a)
- [x] 1.2 Read OpenRegister's authorization semantics from its code and specs (fail-closed blocks, admin and owner bypass, `user:<uid>`, group provisioning on import)

## 2. The blocks

- [x] 2.1 Add the authorization blocks to `dso_verzoek` and `openformulieren_submission` in both registers, with a version bump. Verify against the real register files (merged base and raw demo register) and move both schemas from KNOWN_OPEN to CLOSED in the ratchet

## 3. The groups

- [x] 3.1 Add `IntakeGroups` (ensure, enrol, withdraw) and enrol the chosen account in both connection settings, withdrawing the previous one and a refused one. Verify in PHPUnit
- [x] 3.2 Add the repair step `ProvisionIntakeGroups` (post-migration and install) and bump the app version. Verify: four groups, intake accounts enrolled, no handler enrolled, idempotent

## 4. Live proof

- [x] 4.1 Per role through the OpenRegister API on the throwaway instance: an ordinary account is refused read, create and update on both schemas; a handler reads and updates; the intake account creates (`iof-live/commands.md`)

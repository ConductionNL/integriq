# Proposal: bsn-intake-records-access-rules

kind: code. Cites **ADR-022** (apps consume OpenRegister abstractions). Ruben approved the model on 2026-10-04: "intake account + a handler group".

## Why

`dso_verzoek` and `openformulieren_submission` hold BSNs. Neither schema had an OpenRegister `authorization` block. OpenRegister then lets every signed-in account create, read and update every object.

A live run on 2026-10-04 proved it (throwaway Nextcloud 35, OpenRegister development e80cd62, integriq development 92d334b6d). An ordinary account `gewoon`, in no group:

- read the admin's Open Formulieren submission, BSN included;
- created an `openformulieren_submission` (201);
- created a `dso_verzoek` (201).

`SchemaAuthorizationRatchetTest` listed both schemas as knowingly open.

## What changes

- **Both schemas get an authorization block.** The intake group may create and update. The handler group may read and update. Administrators keep everything, as OpenRegister always grants them. Nobody else gets anything, and nobody may delete.
- **Four fixed groups.** `dso-intake`, `dso-behandelaars`, `openformulieren-intake`, `openformulieren-behandelaars`.
- **The intake account joins its group.** Choosing the account in the connection settings puts it in the intake group and takes the previous one out.
- **Existing instances keep working.** A repair step creates the four groups and puts each connection's current account in its intake group. It never fills a handler group.

## Capabilities

### Modified Capabilities

- `open-formulieren-intake`: new REQ-008.
- `dso-omgevingsloket`: new REQ-DSO-072.

## Impact

- Registers: `dso_verzoek` and `openformulieren_submission` gain the block, in both the base and the demo register, with a version bump.
- New: `lib/Service/Intake/IntakeGroups.php`, `lib/Repair/ProvisionIntakeGroups.php`.
- Changed: `DsoPkiSettingsController` and `OpenFormulierenSettingsController` enrol the chosen account.
- Operators: put the case workers who handle DSO verzoeken in `dso-behandelaars`, and those who handle Open Formulieren submissions in `openformulieren-behandelaars`. Until then only administrators can read them.

# Design: platform-action-rights-coverage

Kind: code. One matrix, one screen. The seed becomes the complete catalogue of
enforced actions and a test keeps it that way. Object rights are more rows in
the same matrix, applied to OpenRegister's schema authorization blocks.

## Where it fits

- Seed: `lib/actions.seed.json` keeps `actions` (name to default groups) and
  gains `labels` (name to a short label) and `areas` (name to an area such as
  "Sources", "Events", "Adapters", "Configuration objects").
  `lib/Controller/ActionMatrixController.php:134` (`seedActionKeys()`) reads
  the new keys and `:73` (`getMatrix()`) returns them with the actions.
- Test: `tests/Unit/Auth/ActionSeedCoverageTest.php` parses every PHP file
  under `lib/` for `requireAction(` and `can(` calls, resolves a string
  literal or an `ACTION_*` class constant, and fails naming each action that is
  not in the seed. Concatenated names, such as the
  `event.subscribe-nextcloud-` prefix, are resolved against the seeded
  variants or listed in the test as known prefixes.
- Screen: `src/views/admin/ActionAuthMatrix.vue` renders one section per area,
  the label with the action name beneath it, and the same checkbox per group it
  has today (`:43`).
- Object rights: actions named `object.<schema>.<verb>` for the nine
  configuration schemas and the verbs `create`, `read`, `update`, `delete`,
  seeded with the schema's current posture. A new
  `lib/Service/ObjectRightsService.php` writes an action's groups onto the
  schema's OpenRegister `authorization` block, always keeping `admin`, through
  OpenRegister's schema service (ADR-022). `lib/Controller/ActionMatrixController.php:111`
  (`setMatrix()`) calls it for `object.*` entries after storing the matrix.
- Repair: `lib/Repair/ApplyObjectRights.php`, registered after
  `lib/Repair/InitializeRegister.php`, applies the stored object rights again
  after each register import.

## D1. The seed is the catalogue, and a test enforces it

The screen already lists every seeded action, and `getAllowedGroups()`
(`lib/Service/ActionAuthService.php:149`) already treats an unseeded action as
admin-only. The defect is only that the seed drifted from the code: 34
enforced names are missing. Adding them fixes today; the test fixes the next
adapter. The alternative was to derive the list at runtime by scanning the
code. Rejected: runtime scanning of PHP source is slow and fragile, and a
declared catalogue is what `action-authorization` requires.

## D2. Object rights are matrix rows, not a second screen

An administrator asking "who may edit mappings" and "who may run a
synchronization" asks the same kind of question. Putting `object.mapping.update`
next to `synchronization.run` keeps one place to look, one storage key and one
save. The alternative was a separate object-rights screen writing schema
blocks directly. Rejected: two screens for rights invite two answers.

## D3. Integriq applies, OpenRegister enforces

OpenRegister enforces a schema's `authorization` block on its own object API,
which is where sources, mappings and synchronizations are created and edited.
Integriq does not add a second check; it writes the chosen groups into that
block. Because a register import writes the block from the JSON again, the
repair step re-applies the stored choice after import. The alternative was to
edit the register JSON per installation. Rejected: it would be undone on the
next upgrade and cannot differ per instance.

## D4. Applying changes nothing until someone changes a cell

The seeded posture of each `object.*` action is the schema's posture today:
`admin` for every verb of `source`, `rule` and `consumer`. For the six schemas
with no block, the first task measures OpenRegister's default for each verb on
a live instance and seeds that, so the first application writes what is
already in force. The alternative was to seed every verb `admin`. Rejected in
this change: it would silently remove access that non-administrators may rely
on today, and that decision belongs to a reviewed security change, not a
coverage change.

## Declarative versus imperative

Enforcement stays declarative: OpenRegister reads the schema `authorization`
block. Integriq's part is imperative only in writing that block from the
matrix, on save and after import.

## Risks

- Granting `read` on `source` or `consumer` exposes whatever is not write-only.
  Their secret fields are write-only through the `99-*-secrets-writeonly.json`
  fragments; the screen marks these two schemas with a warning.
- If OpenRegister's schema service refuses an update, the matrix and the
  schema disagree. `setMatrix()` reports which schemas were not applied, and
  the repair step retries them.

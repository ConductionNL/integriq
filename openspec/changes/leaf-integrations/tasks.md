# Tasks: leaf-integrations

## Implementation Tasks

### Task 1: Declare `configuration.linkedTypes` on `source` and `synchronization`
- **spec_ref**: `openspec/changes/leaf-integrations/specs/integration-leaves/spec.md#requirement-the-leaf-surface-is-declared-in-the-register-and-the-manifest-and-is-exactly-four-leaves-on-two-schemas-req-ocl-001`
- **files**: `lib/Settings/openconnector_register.json`
- **acceptance_criteria**:
  - GIVEN the register JSON WHEN edited THEN `source.configuration` carries `linkedTypes: ["files", "deck", "talk"]` and `synchronization.configuration` carries `linkedTypes: ["calendar"]`, added without touching any other key of either schema
  - GIVEN the file WHEN grepped for `linkedTypes` THEN exactly 2 schemas carry it and none of the other 37 does (assert absence on `endpoint`, `mapping`, `job`, `rule`, `consumer`, `sync_item_dead_letter`, and every `*_log` schema explicitly)
  - GIVEN each edit WHEN `python3 -m json.tool lib/Settings/openconnector_register.json` runs THEN it exits 0 and no pre-existing key is dropped; the `register.d/99-*` lockdown overlays are untouched
  - GIVEN the register is re-imported WHEN `Schema::validateLinkedTypesValue()` runs THEN no invalid-linked-type error is raised
- [ ] Implement
- [ ] Test

### Task 2: Add the three integration widgets to SourceDetail
- **spec_ref**: `openspec/changes/leaf-integrations/specs/integration-leaves/spec.md#requirement-leaves-are-pure-link-surfaces-and-never-read-or-write-source-properties-req-ocl-002`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN edited THEN SourceDetail gains `src-files` (`integrationId: "files"`, title "Supplier documents"), `src-deck` (`integrationId: "deck"`, title "Incident follow-ups"), and `src-talk` (`integrationId: "talk"`, title "Incident war-room"), each with `id`, `type: "integration"`, `integrationId`, `title`, `icon`
  - GIVEN the manifest WHEN grepped for `"type": "integration"` THEN the count is exactly 3, all on SourceDetail, and no custom page (`SynchronizationDetail`, `MappingDetail`, `DeadLetters`, …) gained a widget
  - GIVEN the built app WHEN the manifest validator runs THEN it passes
- [ ] Implement
- [ ] Test

### Task 3: e2e spec-coverage for the SourceDetail leaves
- **spec_ref**: `openspec/changes/leaf-integrations/specs/integration-leaves/spec.md#requirement-leaves-are-pure-link-surfaces-and-never-read-or-write-source-properties-req-ocl-002`
- **files**: `tests/e2e/spec-coverage/integration-leaves.spec.ts`
- **acceptance_criteria**:
  - GIVEN Deck and Talk enabled in the test env WHEN the suite runs THEN it asserts the three widgets render on SourceDetail against a seeded source, links a Talk conversation via the widget, and asserts the source object's properties are unchanged after linking (API read before/after)
  - GIVEN a source with a seeded `apikey` value WHEN the widget DOMs are inspected THEN the credential value appears nowhere inside them
  - GIVEN Deck or Talk is disabled WHEN SourceDetail renders THEN the test tolerates the absent widget (provider `isEnabled()` behaviour)
- [ ] Implement
- [ ] Test

### Task 4: Documentation
- **spec_ref**: `openspec/changes/leaf-integrations/specs/integration-leaves/spec.md#requirement-every-other-schema-and-leaf-type-stays-off-until-a-spec-change-argues-otherwise-req-ocl-004`
- **files**: `docs/`, `CHANGELOG.md`
- **acceptance_criteria**:
  - GIVEN `docs/` WHEN read THEN it records the incident workflow (documents/cards/war-room anchored on the source), the planning-only calendar leaf and where to find it (object sidebar — SynchronizationDetail is a custom page), and the OFF list with reasons including the deferred dead-letter files leaf
  - GIVEN `CHANGELOG.md` WHEN read THEN it records OpenConnector's first leaf adoption
- [ ] Implement
- [ ] Test

## Verification
- [ ] All tasks checked off
- [ ] `openspec validate leaf-integrations --type change --strict` passes
- [ ] Manual testing against acceptance criteria (link a file, a card, a conversation on a source; link a maintenance event on a synchronization via the sidebar)
- [ ] Code review against spec requirements

## Tests (company-wide ADR-009)

- [ ] Browser tests (Playwright MCP): `tests/e2e/spec-coverage/integration-leaves.spec.ts` (Task 3)
- [ ] All tests pass (`composer test` unaffected — no PHP in this change; zero new failures vs a self-measured baseline)
- PHPUnit: N/A — no PHP is added or changed.
- Newman/Postman: N/A — no HTTP endpoint is added; leaf data flows through OpenRegister's existing integrations API.

## Documentation (company-wide ADR-010)

- [ ] Feature documentation updated in `docs/` (Task 4)
- [ ] Screenshot of SourceDetail with the three leaf widgets committed to `docs/images/`

## i18n (company-wide hydra ADR-007)

- [ ] Dutch (`nl_NL`) and English (`en_US`) strings for the three widget titles ("Supplier documents", "Incident follow-ups", "Incident war-room"), via the mechanism the manifest's existing page titles use

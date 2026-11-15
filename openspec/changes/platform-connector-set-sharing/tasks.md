# Tasks: platform-connector-set-sharing

Kind: code, research-gated. Matrix row `integriq:plt-share-config`. Build decision 57: the transport is a research question for Ruben.
Do not start tasks 2 and 3 until Ruben has answered the open questions in the proposal and OpenRegister has a chosen design.

### Task 1: Record Ruben's answers
- **spec_ref**: openspec/changes/platform-connector-set-sharing/proposal.md
- **files**: `openspec/changes/platform-connector-set-sharing/proposal.md`, `design.md`
- **acceptance_criteria**:
  - GIVEN Ruben's reading of `for-ruben/config-sharing-over-ocm-research.md` WHEN it is recorded THEN each of the seven open questions has an answer or is marked out of scope
  - GIVEN an answer to question 7 WHEN recorded THEN `connectors-course-marketplace` Task 6 is rewritten to match it
- [ ] Record the answers
- [ ] Rewrite the requirements that the answers change, then rerun `openspec validate --strict`

### Task 2: A shareable type for connector sets (blocked on Task 1)
- **spec_ref**: openspec/changes/platform-connector-set-sharing/specs/configuration-export-import/spec.md#requirement-a-connector-set-is-shared-through-openregisters-configuration-sharing-req-cshr-001
- **files**: `lib/Service/Sharing/ConnectorSetShareableType.php` (implements OpenRegister's shareable type interface as chosen), `lib/AppInfo/Application.php` (registration), `tests/Unit/Service/Sharing/ConnectorSetShareableTypeTest.php`
- **acceptance_criteria**:
  - GIVEN a connector set WHEN serialised THEN the output is integriq's existing export document (`ConfigurationService::exportConfiguration`)
  - GIVEN a shared payload WHEN deserialised THEN it goes through the existing import preview and confirmation
  - GIVEN the share WHEN it runs THEN integriq makes no outbound HTTP call of its own
- [ ] Implement
- [ ] Test (PHPUnit)

### Task 3: No GitHub and no credentials on the way (blocked on Task 2)
- **spec_ref**: openspec/changes/platform-connector-set-sharing/specs/configuration-export-import/spec.md#requirement-sharing-a-connector-set-does-not-need-github-req-cshr-002
- **files**: `tests/Unit/Service/Sharing/ConnectorSetShareableTypeTest.php`, `tests/Integration/Sharing/ConnectorSetShareTest.php`
- **acceptance_criteria**:
  - GIVEN outbound GitHub hosts blocked and no token WHEN a set is shared with a peer THEN the share completes
  - GIVEN a source with an API key WHEN shared THEN the payload holds no key value and the `credentialRef` stays unresolved
- [ ] Implement
- [ ] Test (PHPUnit and integration)

## Verification

- `openspec validate platform-connector-set-sharing --strict`
- Tasks 2 and 3: PHPUnit for the touched classes, then `composer check:strict` once before push.

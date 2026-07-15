# configuration-export-import Specification (delta: environments-and-promotion)

## ADDED Requirements

### Requirement: credentialRef authentication placeholders pass through export and import unresolved and untranslated (REQ-010)

The system SHALL export and import a Source's
`configuration.authentication.credentialRef` placeholder (the reference
shape `{"credentialId": "<uuid>"}` or `{"credentialName": "<name>"}` used by
the credential broker per `http-call-engine`'s brokered-dispatch
requirements) byte-for-byte unchanged: `SensitiveFieldRegistry::redactArray()`
SHALL NOT redact the `credentialId` or `credentialName` leaf keys (neither
matches `SECRET_NAME_PATTERN` nor `EXACT_MATCH_NAMES`), and REQ-004's
id↔slug translation SHALL NOT rewrite them (its reference-field vocabulary —
`targetId`/`sourceId`/`inputMapping`/`outputMapping`/`rules[]`/nested
`<type>Id` keys — does not include `authentication`). An exported document's
`credentialRef` therefore always carries the SOURCE environment's own
credential id or name verbatim; it is the responsibility of any consumer
that moves the document between environments (see the
`environments-and-promotion` capability) to re-bind it before or during
import into a different environment — `ConfigurationService` and its
handlers themselves perform no environment-awareness or rebinding.

Notes: `ConfigurationImportPreviewService::missingCredentialFields()`
(REQ-009) checks only the fixed `CREDENTIAL_FIELDS` list
(`apikey`/`secret`/`username`/`password`/`jwt`/`authorizationHeader`/
`authenticationConfig`) and has no awareness of `credentialRef` — a
credentialRef-authenticated Source, which never had any of those fields to
begin with, is therefore always reported as "needs re-entry" for all of
them even though nothing was stripped from it. This is a pre-existing,
narrow imprecision in REQ-009's classification (harmless: the operator
re-checks a Source that in fact needs no re-entry) and is not changed by
this requirement; it is recorded here because `environments-and-promotion`
introduces the correctly-scoped `credentialRefsNeedingRebind` classification
specifically to avoid relying on REQ-009 for this case.

#### Scenario: A Source's credentialRef is not redacted on export
- GIVEN a Source whose `configuration` contains `{"authentication": {"credentialRef": {"credentialId": "550e8400-e29b-41d4-a716-446655440000"}}}`
- WHEN the Source is exported via `SourceHandler::export()`
- THEN the exported `configuration.authentication.credentialRef.credentialId` value is unchanged (`550e8400-e29b-41d4-a716-446655440000`), not `***REDACTED***`

#### Scenario: Importing a credentialRef that does not resolve on the target does not block the write
- GIVEN an OAS document containing a Source with `configuration.authentication.credentialRef.credentialId` set to a UUID that does not correspond to any credential broker entry on the importing environment
- WHEN the document is imported via `importConfiguration()`
- THEN the Source object is created or updated exactly as REQ-003 describes, with the `credentialRef` value written verbatim
- AND no exception is thrown at import time — the dangling reference only surfaces later, when that Source is actually dispatched and `BrokeredCallService` fails to resolve the credential

#### Scenario: credentialRef translation is absent from the id/slug mapping vocabulary
- GIVEN a Source whose `configuration.authentication.credentialRef.credentialName` is set to `"prod-api-key"`
- WHEN the Source is exported and then imported into an environment where a `source`-type or `register`/`schema` slug map entry happens to also be named `"prod-api-key"`
- THEN the `credentialRef.credentialName` value is NOT rewritten by REQ-004's translation (it is not a member of the translated field set), and remains the literal string `"prod-api-key"` on both export and import

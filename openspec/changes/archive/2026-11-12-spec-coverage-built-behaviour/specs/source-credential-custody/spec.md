# source-credential-custody Specification

## ADDED Requirements

### Requirement: A source's secrets are never read back (REQ-SCB-002)

The source schema MUST mark a source's API key, secret, password, JWT and authentication settings as write-only, including the nested secrets under `configuration.authentication`. Every rendered read of a source MUST leave these values out, also for an administrator. Only integriq's own call path MAY read the stored value, through an unrendered read.

#### Scenario: an administrator opens a source with a password
- GIVEN a source saved with a password
- WHEN an administrator reads the source through the app or the API
- THEN the answer carries no password value
- @e2e exclude render boundary; covered by PHPUnit and the schema fragment test

#### Scenario: the call still logs in
- GIVEN the same source
- WHEN integriq calls it
- THEN the request carries the stored password
- @e2e exclude outbound login; covered by PHPUnit on CallService

### Requirement: Migrated secrets live in the credential broker (REQ-SCB-003)

An operator MUST be able to move each source's inline secrets into OpenRegister's credential broker, which stores them encrypted and scoped to the organisation. After a verified move the source MUST hold only a reference to the broker credential, and no plaintext value. Until the operator runs the move, an inline value stays on the source.

#### Scenario: an inline API key moves to the broker
- GIVEN a source with an inline API key
- WHEN the operator runs `occ integriq:migrate-inline-secrets` and the broker returns the same key
- THEN the source holds a credential reference and no API key value
- @e2e exclude occ command; covered by PHPUnit on the migration executor

#### Scenario: a key that does not round-trip stays put
- GIVEN a source whose minted credential does not resolve back to the same key
- WHEN the move runs
- THEN the inline value is kept and the run reports the source as not moved
- @e2e exclude occ command; covered by PHPUnit on the migration executor

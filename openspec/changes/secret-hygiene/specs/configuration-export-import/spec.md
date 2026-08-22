# configuration-export-import Specification Delta

## MODIFIED Requirements

### Requirement: REQ-005 — Redact source credentials from exported configurations

The system SHALL strip or mask every sensitive value it can detect when exporting any of the six Integriq entity types (Source, Endpoint, Mapping, Rule, Job, Synchronization), via a single shared sensitive-field registry (`SensitiveFieldRegistry`), used identically by every `ConfigurationHandler`.

For Source specifically, the system SHALL continue to strip the following
top-level fields entirely (`unset`, field absent from the export — unchanged
from prior behaviour): `authorizationHeader`, `auth`, `authenticationConfig`,
`authorizationPassthroughMethod`, `jwt`, `jwtId`, `secret`, `username`,
`password`, `apikey`.

For every entity type's `configuration` array (a nested, potentially
multi-level array present on Source, Endpoint, Mapping, Rule, Job, and
Synchronization), the system SHALL walk the array recursively via
`SensitiveFieldRegistry::redactArray()` and replace the value of any key that
matches the registry's sensitive-name pattern
(`token|key|secret|password|passwd|apikey|api[-_]?key|access[-_]?token|bearer|auth|signature|assertion|private[-_]?key|x[-_]?api[-_]?token|client[-_]?secret`,
case-insensitive) OR an exact-match secret header name
(`authorization`, `proxy-authorization`, `cookie`, `set-cookie`) with the
literal placeholder string `***REDACTED***`. Matching applies to the key's own
name (for a dotted key such as `headers.Authorization`, the last dot-segment
is what is matched) and is case-insensitive throughout.

Redaction SHALL be irreversible masking, never encryption or a reversible
transform — the exported document MUST NOT contain enough information to
recover the original secret value.

The exported document for every entity type therefore SHALL NOT contain
plaintext credential values, even though those fields are stored unencrypted
in the live environment (ADR-007). Redaction is the only barrier protecting
these secrets for entity types other than Source, exactly as it already was
for Source alone (see prior Notes on this requirement).

<!-- Previous behavior: only SourceHandler::export() stripped a fixed list of
     top-level fields (authorizationHeader, auth, authenticationConfig,
     authorizationPassthroughMethod, jwt, jwtId, secret, username, password,
     apikey) and sanitised `configuration` keys starting with "headers." whose
     name contained "authorization", "token", "key", or "secret" by ad hoc
     substring match implemented locally in SourceHandler. EndpointHandler,
     MappingHandler, RuleHandler, JobHandler, and SynchronizationHandler
     performed zero redaction — their export() methods only unset `id` and
     `uuid`, so any secret-shaped value in their `configuration` array (e.g.
     a per-Rule inline auth override, or a templated header value on an
     Endpoint) was exported verbatim. There was no shared detection logic;
     CallService's `isSecretKeyName()` pattern (used for CallLog redaction,
     see http-call-engine#REQ-006) was never applied to configuration export. -->

#### Scenario: credential fields are stripped on Source export

- GIVEN a Source with `apikey = "live_xyz"`, `secret = "s3cr3t"` and a `configuration` entry `"headers.Authorization" = "Bearer abc"`
- WHEN the Source is exported
- THEN none of `apikey`, `secret`, or `headers.Authorization` appear in the exported array
- AND `headers.Authorization`'s absence is because the top-level exact-match unset() list removes `auth`/`authorizationHeader`, while the nested `configuration.headers.Authorization` key is masked to `***REDACTED***` by the shared registry

#### Scenario: non-sensitive headers are retained

- GIVEN a Source with a non-sensitive `configuration` entry `"headers.Accept" = "application/json"`
- WHEN it is exported
- THEN that header is retained unmodified

#### Scenario: an Endpoint with an inline auth override in its configuration is redacted on export

- GIVEN an Endpoint whose `configuration` contains `{"headers": {"X-Api-Key": "live_endpoint_key_123"}}`
- WHEN the Endpoint is exported via `EndpointHandler::export()`
- THEN the exported `configuration.headers.X-Api-Key` value SHALL be `***REDACTED***`
- AND the key `X-Api-Key` itself SHALL still be present (masking, not omission)

#### Scenario: a Rule's nested configuration secret is redacted on export

- GIVEN a Rule whose `configuration` contains a nested structure
  `{"action": {"headers": {"Authorization": "Bearer live_rule_token"}}}`
- WHEN the Rule is exported via `RuleHandler::export()`
- THEN `configuration.action.headers.Authorization` SHALL be `***REDACTED***`
  in the exported document, regardless of nesting depth

#### Scenario: a Mapping, Job, and Synchronization with secret-shaped configuration values are all redacted on export

- GIVEN one Mapping, one Job, and one Synchronization, each with a
  `configuration` (or, for Job, its `configuration` field distinct from
  `arguments`) entry named `client_secret` holding a live value
- WHEN each entity is exported via its respective handler
- THEN each exported document's `configuration.client_secret` value SHALL be
  `***REDACTED***`

#### Scenario: exporting every entity type in one configuration produces zero secret-shaped values (regression)

- GIVEN a configuration set containing one instance of each of the six entity
  types, each seeded with at least one secret-shaped `configuration` value
  using a different sensitive field name (e.g. `password`, `token`,
  `client_secret`, `apikey`, `Authorization` header, `Cookie` header)
- WHEN `ConfigurationService::exportConfiguration()` is called
- THEN the resulting JSON-serialised export SHALL NOT contain any of the
  seeded plaintext secret values as a substring, for any entity type

#### Scenario: imported entity has no credentials and needs re-entry

- GIVEN an exported entity of any of the six types, imported into a target environment
- WHEN import runs
- THEN the entity object is created/updated with masked/absent credential values
- AND an operator must re-enter credentials in the target environment for the entity to authenticate where applicable

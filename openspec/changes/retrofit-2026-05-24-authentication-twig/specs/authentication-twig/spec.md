---
retrofit: true
status: draft
---

# Authentication Service + Twig Runtimes

## Purpose

`AuthenticationService` builds outbound-call authentication configurations and signs
JWT tokens for use by `CallService`. Two Twig runtimes — `AuthenticationRuntime` and
`MappingRuntime` — expose the service's primitives (plus generic mapping/encoding
helpers) to Twig templates used in source configurations. The runtimes are the
template-side equivalent of the rule-pipeline's authentication processing: a source's
`configuration.authentication.<scheme>Token` template is rendered before dispatch,
and the runtime fetches the appropriate token for the source.

## ADDED Requirements

### REQ-001: OAuth Client Credentials and Password token fetch

`AuthenticationService::fetchOAuthTokens(array $configuration)` MUST require
`grant_type` and `tokenUrl` on the input; absence of either MUST throw
`BadRequestException`. It MUST dispatch to `createClientCredentialConfig` (when
`grant_type === 'client_credentials'`) or `createPasswordConfig` (when
`grant_type === 'password'`); any other value MUST throw `BadRequestException` with
message `Grant type not supported`. Each builder MUST verify all REQUIRED_PARAMETERS_*
fields are present (else `BadRequestException` listing the missing keys). For both
builders, when `configuration.authentication === 'body'` the credentials MUST be sent
in `form_params`; when `'basic_auth'` they MUST be sent via Guzzle's `auth` option as
`[client_id, client_secret]` (client_credentials) or `[username, password]` (password).
The Client Credentials builder MUST additionally support a JWT-bearer client assertion
when `configuration.client_assertion_type === 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer'`
by minting an inline JWT via `fetchJWTToken(algorithm=PS256, secret=private_key,
x5t=x5t, payload=payload)` and attaching it as `client_assertion` in `form_params`.
After dispatch the method MUST decode the JSON response and return either
`$result[$configuration['tokenLocation']]` if a token location is configured, or
`$result['access_token']` as the default.

#### Scenario: client_credentials with basic_auth attaches credentials to Guzzle auth

- **GIVEN** `configuration = {grant_type: 'client_credentials', scope: 'x',
  authentication: 'basic_auth', client_id: 'cid', client_secret: 'sec', tokenUrl: '...'}`
- **WHEN** `fetchOAuthTokens(...)` runs
- **THEN** the Guzzle POST options SHALL include `auth: ['cid', 'sec']`
- **AND** `form_params` SHALL contain only `grant_type` and `scope`

#### Scenario: missing grant_type throws BadRequestException

- **GIVEN** `configuration = {tokenUrl: 'https://idp/token'}` (no `grant_type`)
- **WHEN** `fetchOAuthTokens(...)` runs
- **THEN** `BadRequestException` SHALL be thrown with message
  `"Grant type not set, cannot request token"`

#### Notes

- The two builder methods do NOT validate that the credentials they extract are
  non-empty strings. An empty `client_secret` would be accepted and propagated to
  the IdP, surfacing as a 401 from the upstream rather than a clear local error.
  Observed.

### REQ-002: JWT token minting with HS/RS/PS algorithms

`AuthenticationService::fetchJWTToken(array $configuration)` MUST require `payload`,
`secret`, and `algorithm` (else `BadRequestException`). It MUST render the payload
through Twig (`getJWTPayload`), build a JWK appropriate for the algorithm family
(`getJWK` → `getHSJWK` for HS256/HS512, `getRSJWK` for RS256/RS384/RS512/PS256), and
sign via `generateJWT`. The signed JWT MUST be returned in compact serialisation
format. When `configuration.x5t` is supplied, it MUST be attached as the JWT header
`x5t`. The supported algorithms are HS256/HS384/HS512 (instantiated even though
`getJWK` only branches on HS256/HS512), RS256/RS384/RS512, and PS256. PS384/PS512
are NOT supported.

#### Scenario: a valid PS256 configuration mints a compact-serialised JWT

- **GIVEN** `configuration = {payload: '{"sub":"x"}', secret: <base64 RSA priv key>,
  algorithm: 'PS256'}`
- **WHEN** `fetchJWTToken(...)` runs
- **THEN** the JWT payload SHALL be Twig-rendered then JSON-decoded
- **AND** an RS private-key JWK SHALL be built from the secret
- **AND** a compact-serialised JWS SHALL be returned

#### Scenario: an unsupported algorithm throws

- **GIVEN** `configuration.algorithm = 'PS384'`
- **WHEN** `getJWK(...)` runs
- **THEN** `BadRequestException` SHALL be thrown with message
  `"Algorithm not supported by key generator"`

#### Notes

- **High severity**: `getRSJWK` writes the base64-decoded private key to
  `/var/tmp/privatekey-<microtime+pid>`, then `JWKFactory::createFromKeyFile` reads
  it, then `unlink`. On a crash, exception, or concurrent worker race in the same
  microsecond, private key bytes leak to `/var/tmp` (which is world-readable on many
  systems). Compare with the same pattern in `AuthorizationService::getJWK`
  (REQ-001 of `authorization-jwt`). Observed; flagged.
- **Medium severity**: `getHSJWK` builds the symmetric key as
  `rtrim(base64_encode(addslashes($configuration['secret'])), '=')`. `addslashes`
  before base64-encoding is unusual — if the secret contains `'`, `"`, `\`, or NUL,
  the JWK key bytes will not match what a peer signing with the raw secret
  produces. Observed; flagged.
- **Medium severity**: `generateJWT` catches its own `Exception` and **returns the
  exception message as the JWT string**. Downstream code (`fetchOAuthTokens`,
  `AuthenticationRuntime::jwtToken`) takes that string as if it were a valid token
  and sends it. A signing failure surfaces as an authentication 401, not as a clear
  error. Observed; flagged as a functional bug.
- `getJWTPayload` Twig-renders the payload before JSON-decoding. If the payload
  template comes from caller-controlled data (e.g., per-source configuration
  managed via the integriq UI), template injection lets the operator extract
  any Twig-accessible context. Observed.

### REQ-003: Decos non-standard OAuth token fetch

`AuthenticationService::fetchDecosToken(array $configuration)` MUST extract `tokenUrl`
+ `tokenLocation` from the configuration, then POST the remaining configuration as a
JSON body to `tokenUrl`. The response MUST be JSON-decoded; the token MUST be
returned from `$result[$tokenLocation]` if specified, else from `$result['token']`.

#### Scenario: Decos token fetch returns the token field by default

- **GIVEN** `configuration = {tokenUrl: '...', username: '...', password: '...'}`
- **WHEN** `fetchDecosToken(...)` runs
- **THEN** a POST SHALL be issued to `tokenUrl` with the remaining configuration as
  JSON body
- **AND** the method SHALL return `$response['token']`

#### Notes

- `fetchDecosToken` posts the **entire remaining configuration** (including
  `tokenLocation`) as the JSON body, not just credentials. Sources whose `auth`
  block contains unrelated config keys leak those keys to the Decos endpoint.
  Observed.

### REQ-004: Twig authentication runtime — token injection into sources

`AuthenticationRuntime` MUST expose three Twig functions — `oauthToken(source)`,
`decosToken(source)`, `jwtToken(source)` — each of which extracts
`$source['configuration']['authentication']` (via Adbar Dot for dotted keys) and
forwards it to the matching `AuthenticationService::fetch*` method. The return value
of each function is the token string, ready for direct injection into a templated
header (e.g. `Authorization: Bearer {{ oauthToken(source) }}`).

#### Scenario: jwtToken function returns the result of fetchJWTToken

- **GIVEN** a source with `configuration.authentication = {algorithm: 'HS256',
  secret: 'abc', payload: '{"sub": "x"}'}`
- **WHEN** a Twig template invokes `{{ jwtToken(source) }}`
- **THEN** the runtime SHALL call `AuthenticationService::fetchJWTToken($authConfig)`
- **AND** SHALL return the signed JWT string

#### Notes

- The three runtime methods are thin Adbar-Dot adapters with no error handling — any
  exception from the service propagates to the Twig template evaluation, which
  becomes a `LoaderError`/`SyntaxError` at the call site. Observed.

### REQ-005: Twig mapping runtime — encoding, mapping execution, file lookup, slug

`MappingRuntime` MUST expose nine Twig helpers used by source-configuration
templates and by the mapping engine:

- `b64enc(string)` → `base64_encode`
- `b64dec(string)` → `base64_decode`
- `json_decode(string)` → `json_decode($input, associative=true)`
- `generateUuid()` → `Symfony\Component\Uid\Uuid::v4()`
- `createSlug(string)` → lowercase + space/underscore-to-hyphen + non-`[a-z0-9-]`
  removal + collapse-multi-hyphen + trim-leading-trailing-hyphen
- `callSource(sourceId, endpoint, method='GET', config=[], decode=true)` →
  resolves the source via OR `find`, strips any `source.location` prefix from
  `endpoint`, calls `CallService::call(...)`, returns `response.body` from the
  resulting CallLog (or empty string when absent)
- `executeMapping(mapping, input, list=false)` → accepts `\OCA\OpenRegister\Db\Mapping`
  / array / string / int; hydrates an array or resolves a string/int via OR `find`;
  delegates to `MappingService::executeMapping`
- `getFileContents(fileId, objectId)` → resolves the object's files via `FileService`,
  filters to the matching `fileId`, returns the file contents (or `null` when not
  exactly one match)
- `getFiles(objectId)` → returns the formatted file metadata list for the object via
  `FileService::getFiles` + `formatFile`

#### Scenario: createSlug normalises a mixed-case underscored title

- **GIVEN** `createSlug('Hello_World 2026!')`
- **WHEN** the helper runs
- **THEN** the result SHALL be `'hello-world-2026'`

#### Scenario: callSource strips the source location prefix from the endpoint

- **GIVEN** a source with `location = 'https://api.example.test/v1'` AND a Twig
  call `callSource(sourceId, 'https://api.example.test/v1/things', 'GET')`
- **WHEN** `callSource(...)` runs
- **THEN** the effective endpoint passed to `CallService::call` SHALL be `'/things'`

#### Scenario: getFileContents returns null on ambiguous matches

- **GIVEN** an object with TWO files sharing the same `fileId`
- **WHEN** `getFileContents($fileId, $objectId)` runs
- **THEN** the method SHALL return `null` (count !== 1)

#### Notes

- `executeMapping` accepts caller-supplied arrays and hydrates them directly into a
  `Mapping` entity (line 151-155). If the array comes from caller-controlled input
  (e.g., a request body that flows into a Twig template), the caller can hydrate
  arbitrary fields onto a Mapping object. Observed; flagged.
- `callSource` returns the response body as a string by default but the `$decode`
  parameter is accepted and never used. Observed; flagged.
- `createSlug` regex permits only ASCII; non-ASCII characters (umlauts, accents,
  Cyrillic, etc.) are silently stripped. Observed.

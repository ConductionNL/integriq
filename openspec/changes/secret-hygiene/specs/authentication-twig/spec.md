# authentication-twig Specification Delta

## MODIFIED Requirements

### Requirement: JWT token minting with HS/RS/PS algorithms (REQ-002)

`AuthenticationService::fetchJWTToken(array $configuration)` MUST require `payload`,
`secret`, and `algorithm` (else `BadRequestException`). It MUST render the payload
through Twig (`getJWTPayload`), build a JWK appropriate for the algorithm family
(`getJWK` → `getHSJWK` for HS256/HS512, `getRSJWK` for RS256/RS384/RS512/PS256), and
sign via `generateJWT`. The signed JWT MUST be returned in compact serialisation
format. When `configuration.x5t` is supplied, it MUST be attached as the JWT header
`x5t`. The supported algorithms are HS256/HS384/HS512 (instantiated even though
`getJWK` only branches on HS256/HS512), RS256/RS384/RS512, and PS256. PS384/PS512
are NOT supported.

`getRSJWK(array $configuration)` MUST materialise the base64-decoded RSA private key
to a temp file via `tempnam(sys_get_temp_dir(), 'oc_privatekey_')` — never to a
fixed, predictable path — and MUST `chmod` the file to `0600` both immediately after
`tempnam()` allocates it and again after the key bytes are written, so the private
key is never readable to other local users on a shared host, and the exposure window
between file creation and the permission being asserted is empty. The method MUST
read the key via `JWKFactory::createFromKeyFile($filename, ...)` inside a `try`
block whose `finally` clause unconditionally removes the temp file (guarded by a
`file_exists` check) — cleanup MUST run whether `createFromKeyFile` succeeds,
throws, or the process is otherwise interrupted before returning, so a signing
failure can never leave private key bytes on disk indefinitely. If `tempnam()`
itself fails (returns `false`), the method MUST throw rather than silently falling
back to a lower-safety path.

<!-- Previous behavior: getRSJWK wrote the base64-decoded private key to a fixed,
     predictable path of shape /var/tmp/privatekey-<microtime><pid> with
     default-umask permissions (often world-readable on shared hosting). There was
     no try/finally around JWKFactory::createFromKeyFile — on a crash, exception, or
     concurrent worker race in the same microsecond, private key bytes could leak to
     /var/tmp indefinitely, readable by any local user. See #1012(a) and the
     equivalent pattern in AuthorizationService::getJWK (authorization-jwt#REQ-001). -->

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

#### Scenario: the private-key temp file is created with 0600 permissions and an unpredictable name

- **GIVEN** `configuration = {payload: '{"sub":"x"}', secret: <base64 RSA priv key>, algorithm: 'RS256'}`
- **WHEN** `getRSJWK(configuration)` runs
- **THEN** the temp file used to hold the decoded private key SHALL be created via
  `tempnam()` under the system temp directory (not a fixed `/var/tmp/privatekey-*`
  path)
- **AND** the file's permission mode SHALL be `0600` at the moment its contents are
  written

#### Scenario: the private-key temp file is removed even when key parsing fails

- **GIVEN** `configuration.secret` is a base64 string that does NOT decode to a
  valid RSA private key
- **WHEN** `getRSJWK(configuration)` runs and `JWKFactory::createFromKeyFile` throws
- **THEN** the exception SHALL propagate to the caller
- **AND** the temp file created for the (invalid) key material SHALL NOT remain on
  disk after the method returns/throws

#### Notes

- **Medium severity**: `getHSJWK` builds the symmetric key as
  `rtrim(base64_encode(addslashes($configuration['secret'])), '=')`. `addslashes`
  before base64-encoding is unusual — if the secret contains `'`, `"`, `\`, or NUL,
  the JWK key bytes will not match what a peer signing with the raw secret
  produces. Observed; flagged. Out of scope for `secret-hygiene` (functional
  correctness bug, not a secret-exposure issue — the symmetric secret is never
  written to disk or logged).
- **Medium severity**: `generateJWT` catches its own `Exception` and **returns the
  exception message as the JWT string**. Downstream code (`fetchOAuthTokens`,
  `AuthenticationRuntime::jwtToken`) takes that string as if it were a valid token
  and sends it. A signing failure surfaces as an authentication 401, not as a clear
  error. Observed; flagged. Out of scope for `secret-hygiene` (functional
  correctness bug, not a secret-exposure issue).
- `getJWTPayload` Twig-renders the payload before JSON-decoding, which carries a
  template-injection risk when the payload template comes from caller-controlled
  per-source configuration. Observed; out of scope for `secret-hygiene` — this is
  an injection surface (SSTI-adjacent), not a secret-handling gap, and is grouped
  with the deferred SSRF/SSTI/XXE wave (#1004/#962/#960) per the proposal's Out of
  Scope section.

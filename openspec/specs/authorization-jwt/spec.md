# authorization-jwt Specification

## Purpose
TBD - created by archiving change retrofit-2026-05-24-authorization-jwt. Update Purpose after archive.
## Requirements
### Requirement: JWT Bearer authorization with consumer-registered public keys (REQ-001)

`authorizeJwt(string $authorization)` MUST extract the JWT from the `Bearer ` prefix,
unserialize it via JWS compact serialization, and verify the signature using a JWK
built from the issuer consumer's `authorizationConfiguration.publicKey` /
`authorizationConfiguration.algorithm`. The issuer (`iss` claim) MUST be resolved by
querying the openconnector `consumer` schema in OR via `ObjectService::findAll`.
Supported algorithms are HS256/HS384/HS512 (HMAC), RS256/RS384/RS512 (PKCS#1), and
PS256/PS384/PS512 (PSS). On every failure path the method MUST throw
`AuthenticationException` with a non-empty `message` and a `details` array that names
the failure mode (no issuer, issuer not found, header algorithm rejected, signature
mismatch, token expired). On success the method MUST set the Nextcloud user session
to the consumer's configured `userId`.

#### Scenario: a valid JWT issued by a registered consumer logs the user in

- **GIVEN** the consumer `acme-co` is registered in OR with a matching public key and
  algorithm
- **AND** a caller presents `Authorization: Bearer <valid-JWT-signed-by-acme-co>` with
  a non-empty `iat` and an `exp` in the future
- **WHEN** `authorizeJwt(...)` runs
- **THEN** the JWS signature SHALL verify against the consumer's JWK
- **AND** the consumer's `authorizationConfiguration.userId` SHALL be set as the
  `IUserSession` current user

#### Scenario: a JWT signed by an unregistered issuer is rejected

- **GIVEN** the JWT's `iss` claim does not match any registered consumer
- **WHEN** `authorizeJwt(...)` runs
- **THEN** the method SHALL throw `AuthenticationException` with
  `message = "The issuer was not found"`
- **AND** the user session SHALL NOT be mutated

#### Scenario: a JWT with an unsupported algorithm is rejected

- **GIVEN** the JWT header carries an algorithm not in the union of
  `HMAC_ALGORITHMS` / `PKCS1_ALGORITHMS` / `PSS_ALGORITHMS`
- **WHEN** `authorizeJwt(...)` calls `checkHeaders`
- **THEN** `AuthenticationException` SHALL be thrown with
  `message = "The token could not be validated"` and a `details.reason` echoing the
  underlying `InvalidHeaderException` message

#### Scenario: an expired token is rejected

- **GIVEN** a JWT with `exp` in the past (or with no `exp` but an `iat` more than one
  hour ago)
- **WHEN** `validatePayload($payload)` runs
- **THEN** `AuthenticationException` SHALL be thrown with
  `message = "The token has expired"` and `details` including `iat`, `exp`, and
  `time checked`

#### Notes

- The HMAC `AlgorithmManager` instance lists `HS256` twice and never instantiates
  `HS512` (line 227-235). Tokens signed with `HS512` will fail verification even though
  `HS512` is documented as supported via `HMAC_ALGORITHMS`. Observed-but-suspicious;
  flagged.
- `getJWK` writes RSA/PSS public keys to `/var/tmp/publickey-<microtime><pid>` so the
  jose library's `createFromKeyFile` can read them, then `unlink`s. On race conditions
  or a crash before `unlink`, public-key bytes leak to a world-readable directory.
  Observed; flagged.
- `findIssuer` returns the **first** consumer match without checking for ambiguity.
  Two consumers with the same `name` would silently authorize against the first hit.

### Requirement: HTTP Basic authorization (REQ-002)

`authorizeBasic(string $header, array $users, array $groups)` MUST extract
`<base64(user:password)>` from the `Basic ` prefix, base64-decode it, split on the
first `:`, and call `IUserManager::checkPassword`. On success the method MUST set the
Nextcloud user session to the resolved user. On failure (`checkPassword` returns
`false`) the method MUST throw `AuthenticationException` with
`message = "Invalid username or password"`. The `$users` and `$groups` arguments are
accepted but **not enforced** — the user/group membership gate is commented out in
the implementation (see Notes).

#### Scenario: a correct username + password sets the session user

- **GIVEN** `Authorization: Basic <base64("alice:correctpw")>` and `alice` exists in
  Nextcloud with that password
- **WHEN** `authorizeBasic(...)` runs
- **THEN** `IUserSession::setUser($alice)` SHALL be called
- **AND** no exception SHALL be thrown

#### Scenario: an unknown user or wrong password is rejected

- **GIVEN** `IUserManager::checkPassword` returns `false`
- **WHEN** `authorizeBasic(...)` runs
- **THEN** `AuthenticationException` SHALL be thrown with
  `message = "Invalid username or password"`

#### Notes

- The `$users` + `$groups` membership-gate block is commented out as a `@TODO` waiting
  on frontend support for users/group selection. Currently any authenticated Nextcloud
  user passes this check regardless of the rule's allow-list. Observed-but-suspicious;
  flagged.
- The method does not validate that the header actually starts with `Basic ` — a
  malformed header would still be `substr`-stripped of 6 bytes and base64-decoded,
  producing garbage rather than a clean error.

### Requirement: OAuth bearer authorization via existing session (REQ-003)

`authorizeOAuth(string $header, array $users, array $groups)` MUST verify that the
header starts with the literal string `Bearer` and that the current Nextcloud session
is already logged in (`IUserSession::isLoggedIn() === true`). On either failure the
method MUST throw `AuthenticationException`. On success the method does NOT mutate the
session — it relies on the caller having already authenticated via the standard
Nextcloud OAuth2 flow before reaching this rule.

#### Scenario: an OAuth-authenticated session passes

- **GIVEN** the Nextcloud user is logged in via the OAuth2 app and the request carries
  `Authorization: Bearer <token>`
- **WHEN** `authorizeOAuth(...)` runs
- **THEN** the method SHALL return `void` without exception
- **AND** SHALL NOT modify the user session

#### Scenario: a request without an active OAuth session is rejected

- **GIVEN** the Nextcloud session is not logged in
- **WHEN** `authorizeOAuth(...)` runs with a `Bearer` header
- **THEN** `AuthenticationException` SHALL be thrown with
  `message = "Not authorized"` and a `details.reason` mentioning expiry or invalid
  token

#### Scenario: a non-Bearer authorization scheme is rejected

- **GIVEN** the header is `Authorization: Basic …` (or any prefix other than
  `Bearer`)
- **WHEN** `authorizeOAuth(...)` runs
- **THEN** `AuthenticationException` SHALL be thrown with
  `message = "Invalid method"`

#### Notes

- The `str_starts_with($header, 'Bearer')` check is prefix-only (no trailing space) and
  accepts both `Bearer foo` and `Bearertoken-with-no-space`. Observed; flagged.
- The `$users` and `$groups` membership-gate block is commented out as a `@TODO` here
  too, matching REQ-002.

### Requirement: API key authorization via header-to-uid map (REQ-004)

`authorizeApiKey(string $header, array $keys)` MUST treat the header value as an
exact lookup key into the `$keys` map; the map's values are Nextcloud uids. On a
successful lookup the method MUST set the Nextcloud session to the resolved user. On
a missing key or a uid that does not resolve to an `IUser`, the method MUST throw
`AuthenticationException` with `message = "Invalid API key"`.

#### Scenario: a configured API key sets the session user

- **GIVEN** `$keys = ['key-abc' => 'alice']` and the header is `key-abc`
- **WHEN** `authorizeApiKey('key-abc', $keys)` runs
- **THEN** `IUserSession::setUser($alice)` SHALL be called
- **AND** no exception SHALL be thrown

#### Scenario: an unknown key is rejected

- **GIVEN** the header is not present as a key in `$keys`
- **WHEN** `authorizeApiKey(...)` runs
- **THEN** `AuthenticationException` SHALL be thrown with
  `message = "Invalid API key"`

#### Scenario: a known key mapped to a deleted user is rejected

- **GIVEN** `$keys = ['key-zoe' => 'zoe-deleted']` and Nextcloud no longer has user
  `zoe-deleted`
- **WHEN** `authorizeApiKey('key-zoe', $keys)` runs
- **THEN** `IUserManager::get('zoe-deleted')` SHALL return `null`
- **AND** the method SHALL throw `AuthenticationException` with
  `message = "Invalid API key"`

#### Notes

- The header is used as the **key** (not the value), so the rule's `$keys` map must be
  configured as `{api-key-string => uid}`, not the other way around. This is observed
  behaviour and matches how the rule pipeline supplies the array.

### Requirement: CORS response header injection with credentials guard (REQ-005)

`corsAfterController(IRequest $request, Response $response)` MUST act as a controller
after-middleware. When the request carries an `HTTP_ORIGIN` header, the method MUST
(a) iterate the response headers, (b) throw `SecurityException` if any header is
`Access-Control-Allow-Credentials: true` (case-insensitive name + value), and (c) add
`Access-Control-Allow-Origin: <request origin>` to the response. When the request
does not carry `HTTP_ORIGIN`, the response MUST be returned unchanged.

#### Scenario: a cross-origin request gets its origin echoed back

- **GIVEN** the request carries `Origin: https://app.example.test`
- **AND** the response does NOT include `Access-Control-Allow-Credentials: true`
- **WHEN** `corsAfterController(...)` runs
- **THEN** the response SHALL include
  `Access-Control-Allow-Origin: https://app.example.test`

#### Scenario: a response asserting Allow-Credentials trips the CSRF guard

- **GIVEN** the request carries `Origin: …` AND the response already includes
  `Access-Control-Allow-Credentials: true`
- **WHEN** `corsAfterController(...)` runs
- **THEN** `SecurityException` SHALL be thrown with a message naming CSRF as the
  reason
- **AND** the response SHALL NOT have an `Access-Control-Allow-Origin` header added

#### Scenario: a same-origin request is a no-op

- **GIVEN** the request does NOT carry `HTTP_ORIGIN`
- **WHEN** `corsAfterController(...)` runs
- **THEN** the response SHALL be returned unchanged

#### Notes

- The method echoes any request `Origin` value back as-is — there is no allow-list of
  acceptable origins. This is wide open by design (openconnector is meant to be reached
  cross-origin), but operators relying on origin filtering need to add an upstream
  gate. Observed-but-suspicious; flagged.
- The `$users` parameter on the controller-style middleware does not exist here — CORS
  is unauthenticated. That is correct per CORS semantics.


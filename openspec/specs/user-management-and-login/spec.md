---
retrofit: true
status: implemented
---

# User Management and Login Specification

## Purpose

@e2e exclude Pure REST authentication surface (UserController + SecurityService + UserService). `/api/user/login`, `/api/user/me`, `/api/user/logout` are headless API endpoints — openconnector has no dedicated login SPA page; Nextcloud's own /login handles UI authentication. Rate-limit, lockout, memory-guard, CORS, and input-sanitisation scenarios require controlled infrastructure state that cannot safely be reproduced in a shared Playwright env. Covered by PHPUnit + Newman API tests.

OpenConnector exposes a self-contained REST authentication surface (`/api/user/login`,
`/api/user/me`, `/api/user/logout`) for browser SPA and external API clients, layered on
Nextcloud's `IUserManager` / `IUserSession`. Login is hardened with per-username + per-IP
rate limiting, progressive backoff, account/IP lockout, anti-enumeration error messages,
memory monitoring, and security event logging (`SecurityService`). The profile surface
(`UserService`) composes a comprehensive user payload (groups, quota, language, custom
name fields, AccountManager profile properties) and applies capability-gated updates. This
spec captures the observed behavior of 31 code units retroactively; the code already exists.

## Requirements

### REQ-001: Read and update the authenticated user's own profile

The system MUST expose `@NoAdminRequired @NoCSRFRequired` endpoints for a user to read and
update their own profile. `me()` resolves the session user (`UserService::getCurrentUser()`),
falling back to inline HTTP Basic auth (`Authorization: Basic`) when no session exists, and
returns the composed profile (`buildUserDataArray()`) — or HTTP 401 when unauthenticated and
500 on unexpected error. `updateMe()` requires a session user (401 otherwise), sanitises the
request body (`SecurityService::sanitizeInput()`), strips `_`-prefixed system keys, and
applies updates (`updateUserProperties()`). `buildUserDataArray()` composes uid, display
name, email, quota (`buildQuotaInformation()` → `getUsedSpaceMemorySafe()`), language/locale
(`getLanguageAndLocale()`), group memberships, backend capabilities (feature-detected via
`method_exists`), additional profile info (`getAdditionalProfileInfo()` /
`getAccountManagerPropertiesSelectively()`), custom name fields (`getCustomNameFields()` /
`setCustomNameFields()` in the `core` namespace), and organisation stats.
`updateUserProperties()` switches active organisation when requested, then applies standard
NC properties (`updateStandardUserProperties()` — capability-gated display name/email/
password/language/locale) and AccountManager profile properties (`updateProfileProperties()`
with default scopes from `getDefaultPropertyScope()`).

#### Scenario: Read own profile when authenticated

- GIVEN a logged-in session user
- WHEN `GET /api/user/me` is called
- THEN HTTP 200 with the composed user payload (uid, groups, quota, language, profile fields)

#### Scenario: Unauthenticated profile read is rejected

- GIVEN no session user and no valid Basic auth header
- WHEN `GET /api/user/me` is called
- THEN HTTP 401 with a generic "not logged in" message and security headers

#### Scenario: Update is capability-gated

- GIVEN a logged-in user whose backend reports `canChangeDisplayName() === false`
- WHEN `updateMe()` is called with a `displayName` field
- THEN the display name is NOT changed (the capability gate blocks it)

#### Scenario: System keys are stripped from updates

- GIVEN an update payload containing `_internal` keys alongside profile fields
- WHEN `updateMe()` runs
- THEN every `_`-prefixed key is removed before properties are applied

### REQ-002: Authenticate via the custom login endpoint with brute-force protection

The system MUST provide a `@PublicPage` `POST /api/user/login` that authenticates a
username/password against `IUserManager::checkPassword()` and establishes a Nextcloud
session token. Before authenticating it: monitors memory (returns 503 when usage exceeds
80% of `memory_limit`, parsed by `convertToBytes()`); resolves the client IP
(`getClientIpAddress()`, preferring validated public forwarded-header IPs over the remote
address); validates and sanitises credentials (`validateLoginCredentials()` — 400 on missing/
short/invalid-character username or over-long password); and checks the rate limit
(`checkLoginRateLimit()` — 429 with progressive delay / lockout window). On failure it
records the attempt (`recordFailedLoginAttempt()`, which locks out the username and/or IP
after `RATE_LIMIT_ATTEMPTS` within `RATE_LIMIT_WINDOW`) and returns a generic
"Invalid username or password" to prevent username enumeration. On success it records the
event (`recordSuccessfulLogin()`, clearing the username counter and progressive delay),
sets the session user, creates a session token, verifies the session, and returns the
sanitised user payload plus session cookie hints. All responses carry security headers.

#### Scenario: Successful login establishes a session

- GIVEN valid credentials for an enabled account within the rate limit
- WHEN `POST /api/user/login` is called
- THEN the user is set in the session, a session token is created, and HTTP 200 returns the user payload and session info

#### Scenario: Failed login is rate-limited and anti-enumeration

- GIVEN an invalid password
- WHEN `POST /api/user/login` is called
- THEN the failed attempt is recorded and HTTP 401 returns a generic "Invalid username or password" message (no indication whether the username exists)

#### Scenario: Lockout after repeated failures

- GIVEN `RATE_LIMIT_ATTEMPTS` failed attempts for a username within the window
- WHEN a further login is attempted
- THEN `checkLoginRateLimit()` returns `allowed: false` and the endpoint responds HTTP 429 with a lockout window

#### Scenario: Memory guard short-circuits login

- GIVEN initial memory usage already exceeds 80% of the configured `memory_limit`
- WHEN `POST /api/user/login` is called
- THEN HTTP 503 is returned before authentication is attempted

### REQ-003: Terminate the active session on logout

The system MUST provide a `@PublicPage` `logout()` endpoint that terminates the active user
session via `IUserSession::logout()` and returns `{ "logout": true }`.

#### Scenario: Logout clears the session

- GIVEN an active session
- WHEN `logout()` is called
- THEN the session is terminated and HTTP 200 returns `{ "logout": true }`

### REQ-004: Support credentialed cross-origin browser clients via CORS

The system MUST answer CORS preflights for `/api/user/me` and `/api/user/login`
(`preflightedCorsMe()` / `preflightedCorsLogin()` → `buildCorsPreflightResponse()`) and
attach CORS headers to credentialed JSON responses (`addCorsHeaders()`). Both reflect the
request `Origin` header into `Access-Control-Allow-Origin`, set
`Access-Control-Allow-Credentials: true`, and advertise the configured methods/headers/
max-age. When no `Origin` header is present (server-side tooling) the origin defaults to
`http://localhost:3000`.

#### Scenario: Preflight returns credentialed CORS headers

- GIVEN an OPTIONS request to `/api/user/login` with an `Origin` header
- WHEN `preflightedCorsLogin()` runs
- THEN the response reflects that origin, sets `Access-Control-Allow-Credentials: true`, and advertises the allowed methods and headers

### REQ-005: Sanitise input and harden responses

The system MUST sanitise user input and harden API responses. `sanitizeInput()` recursively
trims, length-caps (default 255), strips null bytes, HTML-encodes (`htmlspecialchars` with
`ENT_QUOTES | ENT_HTML5`), and removes dangerous patterns (script tags, `javascript:` /
`vbscript:` protocols, inline event handlers); non-string scalars pass through unchanged.
`addSecurityHeaders()` attaches `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`,
`X-XSS-Protection`, a strict `Referrer-Policy`, a locked-down `Content-Security-Policy`, and
no-store cache directives to every authentication response.

#### Scenario: Script payload is neutralised

- GIVEN a profile field containing `<script>alert(1)</script>`
- WHEN `sanitizeInput()` runs
- THEN the script tag is removed / HTML-encoded so it cannot execute when echoed

#### Scenario: Auth responses carry hardening headers

- GIVEN any response from `me()`, `updateMe()`, or `login()`
- WHEN it is returned
- THEN it includes `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, a strict CSP, and no-store cache headers

## Non-Functional Requirements

- **Security:** Login MUST resist brute force (rate limit + lockout + progressive delay) and username enumeration (generic error messages) — REQ-002.
- **Internationalization:** Controller messages MUST be translatable via `IL10N` (ADR-007); Dutch and English are supported.

## Acceptance Criteria

- [x] Self-profile read/update is capability-gated and session-bound
- [x] Login enforces rate limiting, lockout, and anti-enumeration
- [x] Logout terminates the session
- [x] CORS preflight + response headers support credentialed clients
- [x] Input sanitisation and security headers are applied to auth responses

## Notes

- **Security — reflected `Origin` + `Allow-Credentials: true` (REQ-004).** Both
  `buildCorsPreflightResponse()` and `addCorsHeaders()` echo the request `Origin` header
  verbatim into `Access-Control-Allow-Origin` while also setting
  `Access-Control-Allow-Credentials: true`. Reflecting an arbitrary origin with credentials
  effectively permits any site to make credentialed cross-origin requests once a session
  cookie exists — a recognised CORS misconfiguration (it defeats the SOP protection that a
  wildcard-with-credentials is explicitly forbidden to provide). Documented as observed
  behavior; recommend a follow-up to validate `Origin` against an allowlist (admin-config or
  trusted-domains) rather than reflecting it. Not a finding this change fixes — flagged for
  security review.
- **Security — `me()` accepts inline HTTP Basic auth but is not `@PublicPage`.** `me()` is
  `@NoAdminRequired @NoCSRFRequired` (so NC requires an authenticated session by default),
  yet the body also accepts a `Basic` credential and calls
  `IUserManager::checkPassword()` directly. The Basic-auth branch is only reachable when NC's
  own session check passes, so it is effectively dead unless the route is also public —
  confirm the route declaration in `appinfo/routes.php`. Documented as observed.
- **Observed quirk — `getUsedSpaceMemorySafe()` is overwritten in `buildQuotaInformation()`.**
  The latter calls `getUsedSpaceMemorySafe()` and then, if `getUsedSpace()` exists on the
  user, overwrites the result with it — so the "memory-safe" DB path is discarded whenever
  the backend implements `getUsedSpace()`. Likely not the intended fallback ordering.
  Documented as observed; not changed.
- **Observed typo — `logSecurityEvent()` switch has `case 'succesful_login'`** (missing an
  's'); the real event emitted by `recordSuccessfulLogin()` is `successful_login`, so it
  falls through to the default `info` branch. Benign (same log level) but the case is dead.
- **`UserController` constructs `SecurityService` with `new`** rather than DI. Observed; the
  service has no DI-only dependencies so behavior is unaffected, but it bypasses container
  test substitution.

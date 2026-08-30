# Design — Retrofit user-management-and-login

> **Retrofit change.** Tasks describe retroactive annotation, not new implementation work.
> The code described here already exists and ships in production. No behavior changes.

## Context

`user-management-and-login` groups OpenConnector's self-contained REST auth surface and
profile management, spanning three files:

1. **`UserController`** — the HTTP surface (`me`, `updateMe`, `login`, `logout`) plus CORS
   preflight/header helpers and the `convertToBytes` memory-limit parser.
2. **`UserService`** — profile composition and capability-gated updates over `IUserManager`,
   `IAccountManager`, and the `core` user-config namespace.
3. **`SecurityService`** — login rate limiting, lockout, progressive backoff, input
   sanitisation, IP resolution, security headers, and security-event logging.

## Decisions

- **One capability, five REQs.** The 31 units cluster into self-profile (REQ-001), login +
  brute-force protection (REQ-002), logout (REQ-003), CORS (REQ-004), and sanitisation +
  hardening (REQ-005). Login is split from generic security-helpers because its observable
  contract (rate limit, lockout, anti-enumeration, memory guard) is the highest-value review
  surface in this cluster.
- **Document, do not fix.** Four observed issues are recorded in the spec Notes rather than
  silently corrected: the reflected-`Origin` + `Allow-Credentials: true` CORS posture
  (security), `me()`'s inline Basic-auth branch vs its route auth posture, the
  `getUsedSpaceMemorySafe()` overwrite ordering, and the `succesful_login` switch-case typo.

## Annotation plan

Each method gets `@spec openspec/changes/retrofit-2026-05-25-user-management-and-login/tasks.md#task-N`
mapped per the REQ → task table:

| REQ | task | methods |
|---|---|---|
| REQ-001 | task-1 | me, updateMe, getCurrentUser, buildUserDataArray, updateUserProperties, getCustomNameFields, setCustomNameFields, buildQuotaInformation, getUsedSpaceMemorySafe, getLanguageAndLocale, getAdditionalProfileInfo, getAccountManagerPropertiesSelectively, updateStandardUserProperties, updateProfileProperties, getDefaultPropertyScope |
| REQ-002 | task-2 | login, convertToBytes, checkLoginRateLimit, recordFailedLoginAttempt, recordSuccessfulLogin, validateLoginCredentials, getClientIpAddress, sanitizeForCacheKey, logSecurityEvent |
| REQ-003 | task-3 | logout |
| REQ-004 | task-4 | preflightedCorsMe, preflightedCorsLogin, buildCorsPreflightResponse, addCorsHeaders |
| REQ-005 | task-5 | sanitizeInput, addSecurityHeaders |

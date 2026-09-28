# Tasks: sources-declared-basic-and-apikey-auth

Rows: `integriq:src-auth-basic`.

## Implementation tasks

### Task 1: Apply the declared Basic and API key login
- **spec_ref**: `openspec/changes/sources-declared-basic-and-apikey-auth/specs/http-call-engine/spec.md#requirement-a-source-logs-in-with-the-login-it-declares-req-sdl-001`
- **files**: `lib/Service/SourceAuthApplier.php`, `lib/Service/CallService.php`
- [x] Implement (basic to the `auth` tuple, apikey to the declared header, other strategies untouched)
- [x] Test (a red test first: a `basic` source's call carries no `auth` today; the Guzzle options asserted with the real CallService path)

### Task 2: What the operator wrote wins, and the broker is untouched
- **spec_ref**: `openspec/changes/sources-declared-basic-and-apikey-auth/specs/http-call-engine/spec.md#requirement-an-explicit-login-and-the-broker-win-req-sdl-002`
- **files**: `lib/Service/SourceAuthApplier.php`
- [x] Implement
- [x] Test (explicit `configuration.auth`, an explicit header, a `credentialRef` source)

### Task 3: The declared header is redacted in the call log
- **spec_ref**: `openspec/changes/sources-declared-basic-and-apikey-auth/specs/http-call-engine/spec.md#requirement-a-source-logs-in-with-the-login-it-declares-req-sdl-001`
- **files**: `lib/Service/CallService.php`
- [x] Implement
- [x] Test (a call log record of an apikey source holds no key, validated against the `call_log` schema)

## Verification

- [x] `openspec validate sources-declared-basic-and-apikey-auth --strict`
- [x] PHPUnit, exit code read

# Design — Retrofit http-call-engine

Retrofit change. Tasks describe retroactive annotation, not new implementation work.

## Context

`CallService` is openconnector's outbound-request hot path: 11 methods, 831 LOC, every
configured Source's REST dispatch flows through `call()`. `SOAPService` is the SOAP-side
counterpart (4 methods, 305 LOC) reached via `call()` when the Source is `type=soap`.
The two services together implement Twig templating over request config, certificate
materialisation, rate-limit tracking, request/response logging into the OR `call_log`
schema, and (for SOAP) WSDL-driven engine setup + diffgram payload parsing.

## REQ → method map

| REQ | Methods |
|---|---|
| REQ-001 | `CallService::call` + private helpers `decideMethod`, `applyConfigDot`, `renderConfiguration`, `renderValue`, `calculateExpires` |
| REQ-002 | `CallService::getCertificate`, `removeFiles`, `writeFile`, `removeFile` |
| REQ-003 | `CallService::sourceRateLimit` |
| REQ-004 | `SOAPService::callSoapSource`, `setupEngine`, `getSoapVersion` |
| REQ-005 | `SOAPService::parseDynamicXsd` |

Private helpers are folded under the public REQ they exclusively support — splitting
them out as their own REQs would inflate the spec without adding clarity.

## Observed-but-suspicious behaviour (flagged, not fixed)

| Site | Issue | Severity |
|---|---|---|
| `SOAPService::callSoapSource` | permissive `libxml_set_external_entity_loader` (returns `$system` verbatim) — XXE risk window | **high** |
| `CallService::call` line 562-568 | secrets stripping via `str_contains('authentication')` substring match | medium |
| `CallService::writeFile` | `microtime().getmypid()` filename suffix; no collision lock | medium |
| `CallService::sourceRateLimit` | hot-path OR write on every dispatch when decrement-only | medium |
| `SOAPService` | hard-coded handling of `edcLk01.inhoud`, `QueryExecute2Result`, `FileBytes` | low |
| `SOAPService::parseDynamicXsd` | substring `NewDataSet → DocumentElement` rewrite | low |
| `SOAPService::parseDynamicXsd` | `libxml_use_internal_errors(true)` never restored | low |
| `CallService::writeFile` | SSL keys land in `/var/tmp`, world-readable | medium |
| `CallService::removeFiles` | unsuppressed `unlink` warnings on missing files | low |

These are documented in REQ Notes rather than silently fixed via spec text. The XXE
window in REQ-004 deserves a separate hardening change.

## What the spec deliberately does NOT cover

- Twig extension wiring (`AuthenticationExtension`, `AuthenticationRuntimeLoader`) is
  constructor-time DI and not observable behaviour from the caller's perspective.
- The `errorRetention` / `successRetention` constants and their `IAppConfig` override
  path are implicit in REQ-001's `expires` derivation but not enumerated as their
  own REQ.
- Async dispatch (`$asynchronous = true`) returns the Guzzle `Promise` directly,
  bypassing the CallLog persistence. This is observable but a corner case; flagged
  in the REQ-001 description rather than as its own scenario.

## Validation

After archive, `openspec validate http-call-engine --strict` MUST pass and Specter
MUST register the spec as part of the retrofit cohort.

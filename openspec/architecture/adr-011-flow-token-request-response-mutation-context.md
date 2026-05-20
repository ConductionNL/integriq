# ADR-011: FlowToken is the request/response mutation context for endpoint and synchronization processing

## Status
Accepted (capturing existing decision)

## Date
2026-05-20

## Context

`EndpointService::handleEndpointRequest()` and `SynchronizationService` both
process requests that may be modified by one or more `Rule` objects (see
ADR-002) before and after the core operation (ObjectService CRUD or
CallService proxy). Passing mutable arrays through a deep call stack without
an explicit container would make it hard to track what changed, when, and why.

`lib/Service/Helper/FlowToken.php` is a 269-line value-object that carries
four dual (original + amended) arrays through the pipeline:

| Pair                    | Purpose                                          |
|-------------------------|--------------------------------------------------|
| `requestOriginal`       | Immutable snapshot of the inbound HTTP request   |
| `requestAmended`        | Mutable copy; rules and mappings write here      |
| `responseOriginal`      | Raw response from the target (ObjectService / source) |
| `responseAmended`       | Post-rule/post-mapping response to be returned   |
| `syncInputOriginal`     | Immutable snapshot of the object before sync     |
| `syncInputAmended`      | Mutable copy for sync-input transforms           |
| `syncOutputOriginal`    | Raw OR object after sync write                   |
| `syncOutputAmended`     | Post-rule output for the synchronization result  |

The constructor normalises a raw Nextcloud `Request` object into the
`requestOriginal` array (method, headers, parameters, path). Content-type
negotiation (JSON / XML / form-data / multipart) is handled in
`FlowToken::parseContent()` (FlowToken.php:112-146).

The token is created at the entry point of a request:
- `EndpointService.php:194` — `$flowToken = new FlowToken(requestOriginal: $request, path: $path)`.
- `SynchronizationService.php:743-744` — `if ($flowToken === null) { $flowToken = new FlowToken(); }`.

It is passed **by reference** (`FlowToken &$flowToken`) through the rule
processing chain so each rule can amend the request or response in-place while
the original snapshot remains accessible.

## Decision

Any new code that needs to inspect or modify an inbound request, an outbound
response, or a sync object during endpoint/synchronization processing MUST use
the `FlowToken` container. Do NOT add new mutable array arguments to
`processRules()`, `handleSchemaRequest()`, `handleSourceRequest()`, or
`synchronizeContract()` — instead, add a new field pair to `FlowToken` if a
new lifecycle stage requires it.

The "original" half of each pair is frozen at construction time and MUST NOT
be overwritten by rule processing. The "amended" half is the write surface.
This invariant is what lets `EndpointService` log before/after diffs for
observability.

## Consequences

- A new Rule type that needs to inject a header into the response MUST write
  to `$flowToken->getResponseAmended()['headers']`, not return a raw array
  from `RuleService`.
- Content-type negotiation for new inbound body types (e.g. CSV, protobuf)
  must be added to `FlowToken::parseContent()` (FlowToken.php:112), not
  scattered across individual controllers or services.
- `SynchronizationService` creates a null `FlowToken` as a default parameter
  (SynchronizationService.php:740-744), making the token optional for call
  paths that enter outside an HTTP context (e.g. background jobs). Code that
  calls `synchronizeContract()` outside an HTTP context SHOULD pass `null` to
  use the default empty token.
- Cross-reference: ADR-002 (MappingService / RuleService) — rules write to
  the FlowToken; the token is the shared state between before-rules and
  after-rules.
- Cross-reference: ADR-003 (CallLog) — after target dispatch,
  `EndpointService` uses `$flowToken->getRequestOriginal()` and
  `$flowToken->getResponseOriginal()` as the basis for the CallLog row.
- Cross-reference: ADR-008 (targetType dispatch) — FlowToken is created
  before the targetType branch; the same token flows into whichever dispatch
  path is taken.

## Evidence

- `lib/Service/Helper/FlowToken.php:11-41` — class definition with four
  original/amended pairs and constructor normalisation.
- `lib/Service/Helper/FlowToken.php:148-158` — `setRequestOriginal()` normalising
  a Nextcloud `Request` object into a canonical array.
- `lib/Service/Helper/FlowToken.php:112-146` — `parseContent()` handling JSON,
  XML, form-data, and multipart body types.
- `lib/Service/EndpointService.php:194` — `new FlowToken(requestOriginal: $request, path: $path)`.
- `lib/Service/EndpointService.php:252` — `$flowToken = $this->updateRequestWithRuleData(flowToken: $flowToken, ...)` — amended copy after rule run.
- `lib/Service/SynchronizationService.php:359` — `FlowToken &$flowToken` passed
  by reference into `synchronizeObjects()`.
- `lib/Service/SynchronizationService.php:740-744` — null-default FlowToken
  for background/non-HTTP call paths.

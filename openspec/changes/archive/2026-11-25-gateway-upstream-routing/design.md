# Design: gateway-upstream-routing

Kind: code. Size M. The endpoint schema, `EndpointService::handleSourceRequest()`, a new `UpstreamSelector`, and the endpoint editor.

## Context at development 92f282bc

- Endpoint schema: `targetType`, `targetId`, `conditions` (`lib/Settings/integriq_register.json`, endpoint).
- `EndpointService::handleSourceRequest()` (`lib/Service/EndpointService.php:2174`) loads the one source from `targetId` and calls `CallService::call()`.
- `EndpointService::checkConditions()` (`:2143`) runs `JsonLogic::apply()` over `parameters` and `headers`.
- The circuit breaker lives on the source: `CallService` reads `circuitBreakerState`, `circuitBreakerOpenedAt` and `circuitBreakerCooldownSeconds`, and short-circuits an open breaker with a synthetic 503 call log (`lib/Service/CallService.php:1953-2000`).

## D1. Targets on the endpoint, not several endpoints

An endpoint gains `targets`: `[{ source, weight, when, group }]`. When `targets` is present and non-empty it replaces `targetId` for `targetType` `api`; an endpoint without it behaves exactly as today. Several endpoints on one path would clash with `findByPathRegex()`'s uniqueness rule, which protects against ambiguous routing and stays.

## D2. Selection order

1. Content: evaluate each target's `when` (JsonLogic, the library `checkConditions()` already uses) against `{ parameters, headers, body }`, where `body` is the decoded JSON body when the content type is JSON. The targets of the first group with a match are the candidates. Targets without `when` form the default group.
2. Health: drop candidates whose source breaker is open and still cooling down, read from the source fields `CallService` already maintains.
3. Weight: pick one by weight. With `sticky` on the endpoint, the pick is a stable hash of the resolved consumer uuid, so a caller keeps its target while weights stay the same.

If every candidate is down, answer 503 naming the group, without calling anything.

## D3. One retry, idempotent methods only

For GET, HEAD, PUT and DELETE, a connection error or a 502, 503 or 504 from the chosen target triggers one call to another healthy candidate of the same group. POST and PATCH are never retried, because the first call may have had an effect.

## D4. The call log says why

The call log gains `routedTarget` (source uuid) and `routedReason` (`rule:<index>`, `weight`, `sticky`, `retry`). The endpoint page shows the split of the last day's calls per target, from `x-openregister-aggregations` on `call_log`.

## Declarative versus imperative

| behaviour | path | why |
|---|---|---|
| calls per target on the endpoint page | declarative: `x-openregister-aggregations` on `call_log` grouped by `routedTarget` | a count |
| target selection | imperative, in `UpstreamSelector` | request-time routing |

## Seed data

One disabled endpoint `zaken/routed` with three targets: `zaken-oud` weight 95, `zaken-nieuw` weight 5, and `zaken-archief` with `when` `{"==": [{"var": "parameters.archief"}, "true"]}`.

## Risks

- A body-based rule reads a large body. Mitigation: the body is decoded only when a `when` rule references `body`, and only up to the endpoint's existing size limit.
- Sticky routing unbalances weights with few consumers. Mitigation: the editor explains it, and sticky is off by default.

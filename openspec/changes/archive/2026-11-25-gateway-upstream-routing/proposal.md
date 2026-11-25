---
kind: code
depends_on: []
---

# Proposal: gateway-upstream-routing

## Summary

An integriq endpoint points at exactly one source. A service that runs on two servers cannot share the load, a new release cannot be tried on a few callers first, and a request cannot go to a different system depending on what is in it. This change lets an endpoint carry several targets: chosen by a rule on the request's content, spread by weight, and skipped while their circuit breaker is open.

## Why

Three rows of integriq's capability matrix, gateway area (the core area), decided `build` in the OpenSpec pass of 2026-09-27.

| row | rating | decision |
|---|---|---|
| `integriq:gw-loadbalance` | no | build: core area, three competitors yes |
| `integriq:gw-canary` | no | build: core area, three competitors yes |
| `integriq:gw-content-route` | no | build: core area, five competitors yes |

Competitor cells, quoted from the matrix:

- `gw-loadbalance`: Tyk v5.15.0 `apidef/oas/upstream.go:1335` "upstream.loadBalancing spreads calls over weighted targets and :1340 skipUnavailableHosts skips hosts that fail their uptime tests". APISIX 3.18.0 `apisix/schema_def.lua:483` round robin with health checks that "marks nodes down so they are skipped". WSO2 v4.7.0 publisher "Load Balanced Endpoints".
- `gw-canary`: MuleSoft https://docs.mulesoft.com/gateway/latest/policies-included-traffic-management.md "Manages weighted API instance traffic to multiple upstream services from a single consumer endpoint". APISIX `apisix/plugins/traffic-split.lua:86` weighted upstreams. Tyk weighted targets (`upstream.go:1350`).
- `gw-content-route`: MuleSoft https://docs.mulesoft.com/mule-runtime/latest/choice-router-concept.md "the Choice router uses expressions that evaluate message content". n8n 2.40.7 `SwitchV3.node.ts:121`, Tyk `url_rewrite.go:86` rules over the request body, APISIX route vars on body fields, Frank!Framework `SwitchPipe.java:64`.

The matrix note on `gw-content-route`: "'conditions' looks like it could route by content but only gates pass/fail on one fixed target; two endpoints on the same path and method is an error, not a router."

## What integriq already has

- An endpoint has one `targetId` (`lib/Settings/integriq_register.json`, endpoint). `EndpointCacheService::findByPathRegex()` treats more than one endpoint on a path and method as an error.
- `EndpointService::checkConditions()` (`lib/Service/EndpointService.php:2143`) evaluates JsonLogic over parameters and headers, only to accept or reject.
- A per-source circuit breaker in `CallService` (http-call-engine REQ-008): `circuitBreakerState`, `circuitBreakerOpenedAt` and a half-open probe (`lib/Service/CallService.php:1953-2000`).

## What this change builds

1. `targets` on an endpoint: a list of sources, each with a weight and an optional `when` rule.
2. Content-based routing: the first target group whose `when` rule matches the request (parameters, headers, JSON body) is used.
3. Weighted spread within a group, skipping a target whose circuit breaker is open, and one retry on another target for idempotent methods.
4. Canary support: a weight such as 95 and 5, optionally sticky per consumer so one caller keeps landing on the same target.
5. The call log records which target served the call and why.

## Out of scope

- Active health probes on a timer. The circuit breaker is the health signal; the connection registry's health job already probes linked sources.
- Routing on anything but the request (for example time of day).

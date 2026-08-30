---
kind: code
depends_on: []
---

# Proposal: dashboard-http-datasource

## Summary

Add a governed, reusable **HTTP/JSON data-source run API** to OpenConnector
that a dashboard/widget host (initially LaunchPad's `live-data-tile-widget`)
can call at render time to resolve a single scalar value from an external
HTTP source — with credentials, host allow-listing, rate-limiting and
response caching all handled inside OpenConnector rather than in the leaf
app. A leaf app never fetches third-party HTTP directly; it names a
pre-configured OpenConnector `source` plus a value expression and receives
back a resolved, cached value.

This reuses OpenConnector's existing Source engine (`source-management`,
`http-call-engine`), authentication (`authentication-twig`), and the
encrypted secret store (ADR-007/ADR-016) end to end — no new transport code.
It adds a thin, read-only "resolve one value from a source" façade plus its
capability advertisement so leaf apps can probe for it (runtime-consumption
pattern) and degrade when OpenConnector is absent.

## Motivation

Per the established Conduction platform boundary, **integration transports
live in OpenConnector; leaf apps consume the result** (mirrored by
`endoflife-date-source`, `brp-haalcentraal`, `kvk`). Market research on
LaunchPad (Spectr `lp-live-data-json-tile`, demand 12, competitorCoverage
12) found live-data tiles to be LaunchPad's single biggest functional gap —
every serious competitor (Workspace 365, Viva, Homarr, gethomepage, Glance,
Basaas) shows live data on tiles. Building the fetch/credential/rate-limit
plumbing inside LaunchPad would duplicate OpenConnector and put egress and
secrets in the wrong app. This change puts that capability where it belongs
and lets LaunchPad (and any future dashboard host) reintegrate it as a leaf.

## Capabilities

- `dashboard-http-datasource` — new capability (this spec). Built on the
  existing `source-management`, `http-call-engine`, `authentication-twig`,
  and secret-store capabilities, none of which are modified.

## What Changes

- **Capability advertisement** — OpenConnector advertises
  `dashboard-http-datasource` (version + enabled flag) via the existing app
  capability registry so leaf apps can probe for it and fall back cleanly.
- **Resolve endpoint** — `POST /api/datasource/{sourceId}/resolve` with body
  `{ valueExpr, params?, ttl? }`. It runs the named `source` through the
  existing HTTP-call engine (honouring the source's configured auth from the
  encrypted store), evaluates a JSONPath-lite `valueExpr` against the
  response, and returns `{ value, raw?, fetchedAt, stale }`. Read-only: it
  never mutates the source.
- **Governance** — egress is constrained to hosts configured on the source
  (no arbitrary URL from the caller); per-source rate-limit and a response
  cache (`ICache`, TTL = min(requested, source-max), stale-on-error
  fallback). Callers are authenticated Nextcloud users; the endpoint honours
  the source's own read authorization.
- **PHP service seam** — `DashboardDatasourceService::resolve(sourceId,
  valueExpr, params, ttl)` so in-process consumers (and the controller) share
  one path.

## Affected Projects

- [ ] Project: `openconnector` — new resolve controller + service + capability
  advertisement, reusing the existing source/HTTP/auth engines. No schema
  change to sources.
- [ ] Consumer (out of scope here): `launchpad` `live-data-tile-widget`
  consumes this as a leaf via the capability probe.

## Notes

- Out of scope: write/POST actions against a source (this façade is
  read-only).
- Out of scope: charting/time-series (returns a single scalar; the consumer
  formats it).
- Out of scope: websocket push (polling only).

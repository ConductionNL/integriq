# prometheus-metrics Specification (Delta)

## ADDED Requirements

### Requirement: Per-API-Product request and error gauges (REQ-PROM-012)

The app MUST expose `openconnector_api_product_requests_total` as a gauge
with labels `product` (the `api_product`'s `productSlug`) and `status`
(HTTP status code), and `openconnector_api_product_errors_total` as a gauge
with label `product`, both computed declaratively from inbound `call_log`
rows carrying a `product` uuid (`endpoint-runtime` `REQ-EP-009`), the same
`source.kind: "tableCount"` + `groupBy` mechanism that already produces
`calls_total{status,direction}` (`REQ-PROM-005`) — extended with a `product`
label, resolved from the `call_log.product` uuid to its `productSlug` via
the existing label-resolution join pattern.

#### Scenario: request counts exposed per product and status

- GIVEN 40 inbound `call_log` rows for product `woo-publications` with status 200 and 3 with status 429
- WHEN the metrics endpoint is called
- THEN the output includes `openconnector_api_product_requests_total{product="woo-publications",status="200"} 40` and `...{product="woo-publications",status="429"} 3`

#### Scenario: error count reflects statusCode >= 400 rows

- GIVEN a product with 100 inbound rows, 5 with `statusCode >= 400`
- WHEN the metrics endpoint is called
- THEN `openconnector_api_product_errors_total{product="<slug>"} 5`

#### Scenario: a product with no inbound traffic emits a zero placeholder

- GIVEN an `api_product` with no inbound `call_log` rows yet
- WHEN the metrics endpoint is called
- THEN `openconnector_api_product_requests_total{product="<slug>",status="200"} 0` is emitted, consistent with every other `REQ-PROM-*` zero-placeholder scenario

### Requirement: Per-API-Product latency percentile gauges (REQ-PROM-013)

The app MUST expose `openconnector_api_product_latency_seconds` as a gauge
with labels `product` and `quantile` (`0.5`|`0.95`|`0.99`), produced by the
`OpenConnectorMetricsProvider` `IMetricsProvider` escape hatch (the same
mechanism `circuit_breaker_state` uses, `REQ-PROM-011`) — a percentile
cannot be expressed by the declarative `tableCount`/`objectCount` `groupBy`
vocabulary used by `REQ-PROM-012`, since it requires sorting values within a
group rather than counting rows. Per product, the provider MUST read at
most the most recent 1000 inbound `call_log` rows carrying that product's
uuid and compute p50/p95/p99 from their `responseTime` values (milliseconds,
converted to seconds for the gauge per Prometheus convention).

#### Scenario: latency gauge exposes p50/p95/p99 per product

- GIVEN a product's 1000 most recent inbound `call_log` rows with `responseTime` ranging 10-500ms
- WHEN the metrics endpoint is called
- THEN the output includes `openconnector_api_product_latency_seconds{product="<slug>",quantile="0.5"}`, `...quantile="0.95"`, and `...quantile="0.99"` reflecting those percentiles in seconds

#### Scenario: a product with no traffic reports zero latency, not a missing series

- GIVEN a product with zero inbound `call_log` rows
- WHEN the metrics endpoint is called
- THEN all three quantile samples for that product are emitted as `0`, not omitted

#### Scenario: provider query failure falls back to zero, degraded not broken

- GIVEN the `call_log` query for a product's percentile computation raises an exception
- WHEN the metrics endpoint collects this metric
- THEN a zero-value fallback is emitted with a warning logged, and the overall endpoint still returns HTTP 200 (same degraded-but-not-broken contract as `REQ-PROM-001`'s partial-failure scenario and `REQ-PROM-011`'s query-failure scenario)

#### Scenario: percentile computation stays within the scrape performance budget

- GIVEN 50 active `api_product` rows each with up to 1000 inbound rows
- WHEN the metrics endpoint is called
- THEN percentile computation for all products completes within the existing `REQ-PROM-001` 500ms budget (bounded row count per product, in-memory sort, no additional joins)

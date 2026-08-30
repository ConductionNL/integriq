# prometheus-metrics Specification Delta

## ADDED Requirements

### Requirement: Circuit Breaker State Gauge (REQ-PROM-011)

The app MUST expose `integriq_circuit_breaker_state` as a gauge with
label `source` (the Source's `name`), queried directly from the
`openconnector_sources` table's `circuitBreakerState`/`circuitBreakerFailureCount`
columns (same query-time pattern as `REQ-PROM-004`'s `integriq_sources_total`
— no new join, no new table). The value MUST be `1` when
`circuitBreakerState = 'open'` and `0` when `closed`. Sources with no
`circuitBreakerState` set (not yet evaluated, or `retryPolicy`/breaker never
configured) MUST be reported as `0` (closed).

#### Scenario: an open breaker is reported as 1

- **GIVEN** a Source named "kvk-api" with `circuitBreakerState = 'open'`
- **WHEN** the metrics endpoint is called
- **THEN** the output includes `integriq_circuit_breaker_state{source="kvk-api"} 1`

#### Scenario: a closed breaker is reported as 0

- **GIVEN** a Source named "brp-api" with `circuitBreakerState = 'closed'`
- **WHEN** the metrics endpoint is called
- **THEN** the output includes `integriq_circuit_breaker_state{source="brp-api"} 0`

#### Scenario: a source with no breaker state defaults to closed

- **GIVEN** a Source that has never had a breaker evaluation recorded
- **WHEN** the metrics endpoint is called
- **THEN** the output includes `integriq_circuit_breaker_state{source="<name>"} 0`

#### Scenario: metrics query failure falls back to zero

- **GIVEN** database access fails for the circuit-breaker-state query
- **WHEN** the metrics endpoint collects this metric
- **THEN** a zero-value fallback is emitted with a warning logged, and the
  overall endpoint still returns HTTP 200 (degraded-but-not-broken, per
  REQ-PROM-001's partial-failure scenario)

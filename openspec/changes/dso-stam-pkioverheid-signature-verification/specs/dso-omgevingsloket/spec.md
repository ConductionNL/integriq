## MODIFIED Requirements

### Requirement: STAM Koppelvlak Endpoint Registration (REQ-DSO-001)

The adapter MUST register a STAM-compliant inbound REST endpoint in
OpenConnector that receives vergunningaanvragen, meldingen, and
informatieverzoeken pushed from DSO-LV. The endpoint accepts the DSO-verzoek
payload (JSON or XML), **cryptographically verifies the request signature
against the configured PKIoverheid certificate chain (or HMAC shared secret in
pre-production mode)**, and enqueues it for processing. The endpoint path
follows `/api/dso/stam/verzoeken` and returns an HTTP 202 Accepted with
verzoekId confirmation. A request whose signature does not verify against the
configured trust chain MUST be rejected before any payload parsing occurs.

#### Scenario: Valid vergunningaanvraag accepted and enqueued

- **WHEN** the DSO adapter endpoint is registered in OpenConnector with valid
  PKIoverheid certificates and DSO-LV pushes a vergunningaanvraag payload to
  the STAM endpoint
- **THEN** the adapter cryptographically verifies the webhook signature against
  the configured certificate chain, returns HTTP 202, and enqueues the verzoek
  for asynchronous processing

#### Scenario: Invalid webhook signature rejected

- **GIVEN** a request arrives at the STAM endpoint with an `X-DSO-Signature`
  header that does not verify against the configured PKIoverheid certificate
  chain (forged, expired certificate, or untrusted issuer)
- **WHEN** the adapter validates the signature
- **THEN** the adapter returns HTTP 401 Unauthorized with a descriptive error
  message, logs the failed attempt in the CallLog, and does NOT call
  `DSOParserService::parseVerzoek()`

#### Scenario: Missing signature header rejected

- **GIVEN** a request arrives at the STAM endpoint with no `X-DSO-Signature`
  header
- **WHEN** the adapter validates the signature
- **THEN** the adapter returns HTTP 401 Unauthorized without evaluating any
  payload content

#### Scenario: Pre-production request tagged

- **WHEN** the DSO adapter is configured for the pre-production environment
  (HMAC shared-secret mode) and a request arrives from the DSO-LV test
  environment with a valid HMAC signature
- **THEN** it is accepted and processed identically to production requests but
  tagged with `environment: pre-productie` in the verzoek record

#### Scenario: Malformed payload rejected

- **WHEN** DSO-LV sends a payload with a valid signature that does not conform
  to the STAM schema and schema validation fails
- **THEN** the adapter returns HTTP 400 Bad Request with field-level error
  details and does not create a verzoek record

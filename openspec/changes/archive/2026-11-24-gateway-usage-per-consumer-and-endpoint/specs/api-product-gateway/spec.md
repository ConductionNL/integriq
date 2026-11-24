# api-product-gateway Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- gateway-usage-per-consumer-and-endpoint

## Purpose

An administrator sees an API product's usage split per consumer and per
endpoint, not only as one product-wide figure. Row `integriq:obs-analytics`.

## ADDED Requirements

### Requirement: Product usage is split per consumer and per endpoint (REQ-APGU-001)

The analytics of an API product MUST give, besides the product total, one entry
per consumer and one entry per endpoint that had traffic in the period. Each
entry MUST carry the request count, the error rate and the p50, p95 and p99
response times, computed the same way as the product total of REQ-APG-007. The
entries MUST be sorted by request count, highest first. A consumer or endpoint
without traffic in the period MUST NOT appear. The analytics MUST stay readable
by administrators only.

#### Scenario: an administrator sees which partner causes the errors
- GIVEN an API product with two consumers, where one consumer's calls fail one time in five and the other's never fail
- WHEN an administrator opens the product's usage
- THEN the consumer list shows both consumers with their own request count and error rate
- AND the product total equals the sum of the consumer entries

#### Scenario: an administrator sees which endpoint is slow
- GIVEN an API product with three endpoints, of which one answers in two seconds and two answer within a hundred milliseconds
- WHEN an administrator opens the product's usage
- THEN the endpoint list shows the slow endpoint with its own p95 response time

#### Scenario: a subscriber cannot read another subscriber's figures
- GIVEN a user who is not an administrator
- WHEN they ask for a product's analytics
- THEN the request is refused and no consumer entry is returned

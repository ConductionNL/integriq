---
kind: code
depends_on: []
---

# Proposal: gateway-usage-per-consumer-and-endpoint

## Summary

An API product's analytics today give one product-wide figure: the request
count, the error rate and the latency percentiles over every consumer and every
endpoint together. This change splits those figures per consumer and per
endpoint, so an administrator sees who uses a product and which of its
endpoints carry the load or the errors.

## Why

Matrix row `integriq:obs-analytics`, "See usage figures per consumer and
endpoint." The row was marked built on the product-wide analytics of
`api-product-gateway` REQ-APG-007, but
`lib/Controller/ProductSubscriptionsController.php` `analytics()` aggregates
every consumer's call log rows into one figure and has no breakdown. Following
decision 389 the row is set back to `specified` with this change as its plan.
The IqApiProduct board already draws the breakdown ("Per consument", "Per
endpoint").

This change covers two rows: `integriq:obs-analytics`, and the "by whom" part of `integriq:acc-product-analytics`.

## What changes

- `GET /api/products/{productId}/analytics` adds a `byConsumer` and a
  `byEndpoint` list, each entry with the same request count, error rate and
  percentiles the product total carries.
- The usage tab of the API product page shows both lists.

## Out of scope

- Usage across products for one consumer. That is a report, not a product view.

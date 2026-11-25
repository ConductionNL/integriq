# Gateway design findings do not block products

## Why

The publish guard of `gateway-api-design-rules-check` refuses to make an API product active while an error-level design finding is open. On a live instance every product the gateway describes fails four built-in rules that judge what the gateway writes, not what the product owner chose: `/core/doc-openapi-contact` (the publisher writes no `info.contact`), `/core/uri-version` (the server URL `/apps/integriq/api` has no `/vN`), `/core/version-header` (no response declares `API-Version`) and `/core/transport/tls` (on an http instance). So no product could become active without three or four waivers, and every product seeded as `active` (Zaken API, WOO Publications API, KVK Lookup API and the rest of `api-product-gateway.json`) was refused at import and silently missing. The developer portal showed only the deprecated Besluiten API.

## What changes

- The four rules carry `"scope": "gateway"` in `nl-api-design-rules.json`.
- `ApiDesignRuleService::blockingFindings()` leaves gateway-scoped findings out. They are still found, stored and shown on the product page, and can still be waived.
- REQ-ADRC-003 says which findings block.

## Decision

Delegated product decision (22 Nov 2026), taken by the live lane: a finding that no product owner can fix must not stop a product from being published. The alternative, making the publisher satisfy the four rules, needs versioned gateway routes, a contact source and an API-Version header on every proxied answer. That is a larger change and stays open as its own follow-up. Until then, these findings inform and do not block.

---
status: done
---

# API contract

## Purpose

How Integriq's JSON API meets the NL API Design Rules that apply to every endpoint, whatever it does: each response says which version answered it, errors come back as RFC 9457 problem details, and the OpenAPI document that describes the API is published by the API itself. The document lives at `openapi.json` in the repository root and is linted with the Spectral ruleset of the API Design Rules (`https://static.developer.overheid.nl/adr/ruleset.yaml`).

## Requirements

### Requirement: Every API response carries the API version

Every response of an endpoint under `/api/` MUST carry an `API-Version` header with the installed app version, which is also the `info.version` of the OpenAPI document (/core/version-header). Responses outside `/api/` MUST NOT carry it.

#### Scenario: A successful API call names its version

@e2e exclude a response header, not browser behaviour; covered by PHPUnit ApiContractMiddlewareTest.

- GIVEN Integriq is installed at version 1.2.3
- WHEN a client calls any endpoint under `/index.php/apps/integriq/api/`
- THEN the response MUST carry `API-Version: 1.2.3`

### Requirement: API errors are problem details

A JSON error response (status 400 or higher) of an endpoint under `/api/` MUST be served as `application/problem+json` with the members `type`, `title` and `status`, plus `detail` when the body already carries a `detail`, `message` or `error` text (/core/error-handling/problem-details). Every member the body already had MUST be kept, so existing callers see no change. A body that already uses `type`, `title` or `status` for something else, or that is a JSON list, MUST be left as it is. Endpoints whose error format an external standard fixes MUST keep that format: SCIM 2.0 under `/api/scim/` (RFC 7644) and the OAuth 2.0 token and credential endpoints `/api/eudi/token`, `/api/eudi/credential` and `/api/lti/token` (RFC 6749).

#### Scenario: A not-found error becomes problem details

@e2e exclude a content type and a body shape, not browser behaviour; covered by PHPUnit ApiContractMiddlewareTest.

- GIVEN an endpoint answers `404` with `{"error": "Thing not found"}`
- WHEN the response leaves the app
- THEN its content type MUST be `application/problem+json`
- AND its body MUST be `{"type": "about:blank", "title": "Not Found", "status": 404, "detail": "Thing not found", "error": "Thing not found"}`

### Requirement: The API publishes its own OpenAPI document

`GET /index.php/apps/integriq/api/openapi.json` MUST return the OpenAPI document without authentication, with `Access-Control-Allow-Origin: *` and with `info.version` set to the installed app version (/core/publish-openapi). When the document is not installed it MUST answer 404.

#### Scenario: Anyone can read the API description

@e2e exclude an anonymous JSON endpoint, not browser behaviour; covered by PHPUnit OpenApiControllerTest.

- GIVEN a client that is not logged in
- WHEN it requests `/index.php/apps/integriq/api/openapi.json`
- THEN it MUST receive the OpenAPI document with status 200
- AND the response MUST carry `Access-Control-Allow-Origin: *`

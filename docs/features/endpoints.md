# Endpoints

## Overview

An **Endpoint** is a URL path exposed by OpenConnector that can act as a reverse proxy to an external source, a gateway to OpenRegister data, or a rule-execution surface for custom logic. Endpoints allow other systems and clients to interact with OpenConnector as if it were a native API.

## Endpoint Types

| Target Type | Description |
|-------------|-------------|
| `source` | Proxy requests to an external Source |
| `register/schema` | Read and write OpenRegister objects |
| `fixed` | Return a static response |

## HTTP Methods

Each endpoint is configured with one or more HTTP methods (`GET`, `POST`, `PUT`, `PATCH`, `DELETE`). An endpoint can be registered for multiple methods with different rule sets.

## Endpoint Path and Routing

Endpoints are registered at `/index.php/apps/openconnector/api/endpoint/{path}`. Path parameters (e.g. `/{id}`) are supported and injected into the request context for use in rules and target resolution.

Slug-based identifiers allow consistent references across environments.

## Rules

Rules are the core logic layer of an endpoint. Each endpoint has an ordered list of Rules that execute in sequence on every incoming request. Rule types include:

- **Authentication** — Validate incoming credentials before proxying
- **Synchronization** — Trigger a sync run when the endpoint is called
- **Download** — Serve a file from OpenRegister or a source
- **Upload** — Accept file uploads and store them
- **Locking** — Acquire an exclusive lock on a resource
- **Audit Trail** — Expose the change history of an object

Rules can be conditionally applied using JSON Logic conditions evaluated against the incoming request (body, headers, query parameters, path, method).

See [Rules](rules.md) for full documentation.

## Request Flow

```
Incoming HTTP Request
        |
        v
Endpoint matched by path + method
        |
        v
Rules executed in order (authentication first)
        |
        v
Request proxied to Source / OpenRegister / fixed response
        |
        v
Response returned to caller
```

## Proxy Behavior

When target type is `source`, OpenConnector forwards the request to the configured Source using `CallService`. Request headers, query parameters, and body are forwarded (with configurable overrides). The source response is returned to the caller with its original status code and content type.

## OpenRegister Gateway

When target type is `register/schema`, the endpoint provides CRUD access to OpenRegister objects. The register and schema are configured on the endpoint. Standard JSON:API-compatible request/response format is used.

## CORS

OpenConnector registers CORS `OPTIONS` preflight routes for all public endpoints. The `Access-Control-Allow-Origin`, `Access-Control-Allow-Methods`, and `Access-Control-Allow-Headers` headers are configurable per endpoint.

## Authentication on Exposed Endpoints

Incoming requests to endpoints can be authenticated using an **Authentication Rule**. Supported incoming auth methods:

| Method | Description |
|--------|-------------|
| `basic` | HTTP Basic Authentication |
| `jwt` | Standard JWT Bearer token |
| `zgw-jwt` | VNG ZGW JWT (Dutch government standard) |
| `oauth` | OAuth 2.0 Bearer token introspection |
| `apikey` | API key in header or query parameter |
| `none` | No authentication (public endpoint) |

## Implementation

- `lib/Service/EndpointService.php` — Request handling, proxying, OpenRegister gateway
- `lib/Controller/EndpointsController.php` — REST CRUD API
- `lib/Db/Endpoint.php` — Entity
- `lib/Db/EndpointMapper.php` — Database mapper
- `lib/Service/EndpointCacheService.php` — Route caching for performance

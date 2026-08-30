# Rules

## Overview

**Rules** add execution logic to [Endpoints](endpoints.md). Each endpoint has an ordered list of rules that run on every incoming request. Rules can enforce authentication, trigger synchronizations, handle file operations, control resource locking, expose audit trails, and more. Rules are conditionally applicable via JSON Logic expressions.

## Rule Types

### Authentication Rules

Validate incoming credentials before the request is proxied or processed. If authentication fails, the request is rejected with HTTP 401.

| Auth Method | Description |
|-------------|-------------|
| `basic` | HTTP Basic Authentication |
| `jwt` | Standard JWT Bearer token validation |
| `zgw-jwt` | VNG ZGW JWT (Dutch government standard) |
| `oauth` | OAuth 2.0 Bearer token introspection |
| `apikey` | API key in header or query parameter |
| `none` | No authentication required (public endpoint) |

Configuration fields: `authType`, `secret` / `introspectionEndpoint` / `apiKeyHeader`.

### Synchronization Rules

Trigger a synchronization run when the endpoint is called. Useful for on-demand or webhook-triggered synchronization rather than scheduled cron execution.

Configuration fields: `synchronizationId` (ID of the synchronization to run), `async` (whether to run in background).

### Download Rules

Serve a file from OpenRegister or a source. Handles partial content (`Range` headers) and streaming for large files.

Configuration fields: `fileSource` (`register` or `source`), `fileId` or path expression, `mimeType`.

### Upload Rules

Accept file uploads from incoming `multipart/form-data` or `application/octet-stream` requests. Store files in OpenRegister or a configured target source.

Configuration fields: `targetRegister`, `targetSchema`, `maxFileSize`, `allowedMimeTypes`.

### Chunked Upload Rules

Handle partial/chunked uploads using the `Content-Range` header. Assembles chunks and finalizes the file when all parts are received.

### Locking Rules

Acquire an exclusive lock on a resource (identified by register + object ID) for the duration of the request. Rejects concurrent requests with HTTP 423 until the lock expires.

Configuration fields: `lockTimeout` (seconds), `lockResourcePath` (JSON path to resource ID in request).

### Audit Trail Rules

Expose the change history of an OpenRegister object. Returns a chronological log of all creates, updates, and deletes for the specified object.

Configuration fields: `registerId`, `schemaId`, `objectIdPath` (JSON path to object ID in request).

## JSON Logic Conditions

Any rule can be given a `conditions` field containing a JSON Logic expression. The rule only executes when the condition evaluates to true. The expression is evaluated against a context object containing:

| Variable | Description |
|----------|-------------|
| `request.body` | Parsed request body |
| `request.query` | Query parameters |
| `request.headers` | Request headers |
| `request.path` | URL path segments |
| `request.method` | HTTP method (`GET`, `POST`, etc.) |

Example — only run an authentication rule on non-GET requests:

```json
{
  "!=": [{ "var": "request.method" }, "GET"]
}
```

Example — only trigger sync when a specific header is present:

```json
{
  "!==": [{ "var": "request.headers.X-Trigger-Sync" }, null]
}
```

## Rule Execution Order

Rules are executed in the order they are listed on the endpoint. The first rule that rejects the request (e.g. authentication failure) stops execution and returns the error response. Subsequent rules are not evaluated.

Best practice: place authentication rules first, then authorization/locking rules, then processing rules (sync, upload, download).

## Rule Configuration Structure

```json
{
  "type": "authentication",
  "order": 1,
  "conditions": null,
  "configuration": {
    "authType": "jwt",
    "secret": "my-jwt-secret"
  }
}
```

## FlowToken

The **FlowToken** is an internal context object (`lib/Service/Helper/FlowToken.php`) that carries request metadata through the rule execution pipeline. It enables rules to share context (e.g. the authenticated user identity, extracted path parameters) without modifying the original request.

## Implementation

- `lib/Service/RuleService.php` — Rule execution engine
- `lib/Controller/RulesController.php` — REST CRUD API
- `lib/Db/Rule.php` — Entity
- `lib/Db/RuleMapper.php` — Database mapper
- `lib/Service/Helper/FlowToken.php` — Execution context carrier
- `lib/Service/ConfigurationHandlers/RuleHandler.php` — Rule import/export

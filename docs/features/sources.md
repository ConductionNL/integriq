# Sources

## Overview

A **Source** is a configured connection to an external system. Sources are the foundation of all outbound communication in OpenConnector. Every API call made through a synchronization, endpoint proxy, or job references a Source for its base URL, authentication, and connection defaults.

## Source Types

| Type | Description | Use Case |
|------|-------------|----------|
| `json` | REST/JSON API | Most modern REST APIs |
| `xml` | REST/XML API | XML-over-HTTP services |
| `soap` | SOAP web service | Legacy government SOAP APIs (StUF, etc.) |
| `ftp` | FTP/SFTP server | File-based integrations |

## Authentication Methods

Sources support multiple authentication strategies configured in the source's `authenticationConfig` field.

### API Key

Set a static value directly in the `headers` or `query` fields of the source:

```json
{
  "headers": {
    "Authorization": "Bearer my-static-api-key"
  }
}
```

### OAuth 2.0

Use a Twig expression in the Authorization header. OpenConnector resolves the token automatically:

```
Bearer {{ oauthToken(source) }}
```

Supported grant types:

| Grant Type | Required Fields |
|------------|----------------|
| `client_credentials` | `grant_type`, `scope`, `tokenUrl`, `authentication`, `client_id`, `client_secret` |
| `password` | `grant_type`, `scope`, `tokenUrl`, `username`, `password` |

Example `authenticationConfig`:

```json
{
  "grant_type": "client_credentials",
  "scope": "api",
  "authentication": "body",
  "tokenUrl": "https://example.com/oauth/token",
  "client_id": "my-client",
  "client_secret": "my-secret"
}
```

### JWT Bearer

Generate a signed JWT automatically:

```
Bearer {{ jwToken(source) }}
```

Required `authenticationConfig` fields: `payload`, `secret`, `algorithm` (e.g. `HS256`, `RS256`, `PS256`).

### ZGW JWT

Dutch government ZGW authentication using the VNG JWT standard. Uses `client_id` and `secret` from `authenticationConfig`, and automatically includes `iss`, `iat`, and `user_id` claims.

### Basic Auth

Set credentials in `authenticationConfig`:

```json
{
  "username": "user",
  "password": "pass"
}
```

The `Authorization: Basic ...` header is generated automatically.

### PKIoverheid mTLS

For connections to Dutch government services requiring client certificate authentication. Configure the certificate path and key in the source's `configuration` field. Used by the StUF adapter and Digikoppeling-compliant integrations.

## Source Configuration Fields

| Field | Description |
|-------|-------------|
| `name` | Human-readable identifier |
| `slug` | URL-friendly unique identifier |
| `location` | Base URL of the external system |
| `type` | Protocol type (`json`, `xml`, `soap`, `ftp`) |
| `auth` | Authentication method identifier |
| `authorizationHeader` | Header name for the auth token (default: `Authorization`) |
| `headers` | Default headers added to every request |
| `query` | Default query parameters added to every request |
| `configuration` | Auth-specific configuration (OAuth params, cert paths) |
| `authenticationConfig` | Dynamic auth parameters (resolved by Twig) |
| `timeout` | HTTP request timeout in seconds |
| `verify` | TLS certificate verification (boolean) |
| `isEnabled` | Whether the source is active |
| `logging` | Whether to log all calls to this source |

## Call Logging

When `logging` is enabled on a source, every HTTP request and response is stored in a `CallLog` entry. Logs include:

- Request method, URL, headers, and body
- Response status code, headers, and body
- Execution duration
- Associated synchronization or job reference

Logs are accessible via the Logs section in the OpenConnector UI and the `/api/logs` endpoint.

## Rate Limit Handling

OpenConnector detects rate limiting responses (HTTP 429, `Retry-After` headers, and common rate limit headers). When detected, the service throws a `TooManyRequestsHttpException` which causes the calling synchronization or job to back off and reschedule.

## Implementation

- `lib/Service/CallService.php` — HTTP execution, template rendering, error handling
- `lib/Service/AuthenticationService.php` — OAuth token fetching, JWT generation, ZGW JWT
- `lib/Controller/SourcesController.php` — REST CRUD API
- `lib/Db/Source.php` — Entity
- `lib/Db/SourceMapper.php` — Database mapper

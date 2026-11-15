# Design: forms-service-fetch-for-apps

No board of integriq's own. The resident-facing result is portaliq's FormulierVelden board (canvas `5NkFW28vZUUij43xzxHg5a`, the Kadaster check line) and FormulierNietBeschikbaar situation 4. The admin page follows integriq's existing index plus modal pattern (Mappings, Sources).

## D1. A fetch is configuration, not a declared connection

`ConnectionCallRequestedEvent` (REQ-CC-001 of `connection-calls`) resolves a connection an app declared at build time. A form fetch is added at run time by a functional administrator, so it lives as an object in integriq's register, named by slug, and lists the apps that may call it (`allowedApps`). The two commands share the source call path (`CallService` through `BrokeredCallService`); they differ in who names the target and in what comes back.

## D2. The `serviceFetch` schema

| Property | Type | Notes |
| --- | --- | --- |
| `slug` | string | Unique, what an app names. |
| `title`, `description` | string | Shown to designers. |
| `source` | uuid | An integriq source. |
| `method` | enum `GET`, `POST` | |
| `path` | string | Relative to the source location, with `{input}` placeholders. |
| `query` | object | Name to template. |
| `bodyMapping` | uuid, nullable | A Mapping from the inputs to the request body, POST only. |
| `inputs` | array | `{ name, type: string|number|date|boolean, required }`. |
| `outputMapping` | uuid | A Mapping from the answer to the outputs. |
| `outputs` | array | `{ name, type, label }`. Anything the mapping writes outside this list is dropped. |
| `allowedApps` | array of app ids | Empty means none. |
| `timeoutMs` | integer | Default 5000, at most 15000. |
| `cacheSeconds` | integer | Default 0. Cached per fetch and per input values. |
| `enabled` | boolean | |

Only administrators read or write `serviceFetch` objects. The list endpoint for designers projects slug, title, description, inputs and outputs, never source, path or mappings.

## D3. The command

`ServiceFetchRequestedEvent(string $app, string $fetch, array $inputs)` with a result slot, as ADR-041 typed commands do (`DigitalPostSendRequestedEvent`). The listener:

1. Loads the fetch by slug; `unknown-fetch` when absent or disabled.
2. Checks `$app` against `allowedApps`; `not-allowed` otherwise.
3. Checks every required input is present and of its type, and drops undeclared inputs; `invalid-input` naming the input otherwise.
4. Renders path and query with URL-encoded values. A rendered path that leaves the source location is `invalid-input`, as REQ-CC-001 refuses it.
5. Calls the source through the call engine, honouring circuit breaker and rate limit; `source-unavailable` on a refusal, a 5xx or an open breaker, `timeout` past `timeoutMs`.
6. Applies the output mapping and keeps the declared outputs; `mapping-failed` when the mapping throws or a declared output of a required kind is missing.
7. Answers `{ ok: true, outputs, fetchedAt }`.

A 404 from the source is not a failure: the mapping decides what it means (the Kadaster answering "not found" is the outcome "not the owner"). The mapping receives `{ status, body }`.

## D4. The call log

The call log line carries `context: { app, fetch }`. Input values are redacted with the same rules as REQ-006 of `http-call-engine`, and a BSN input is always redacted.

## D5. The admin page

*Beheer > Service fetches* lists slug, title, source, allowed apps and the last result. The modal edits D2's properties, with the Mapping pickers already used on synchronizations. "Testen" takes sample inputs and shows the raw answer and the mapped outputs side by side, for the administrator only.

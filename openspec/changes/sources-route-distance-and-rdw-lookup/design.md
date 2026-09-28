# Design: sources-route-distance-and-rdw-lookup

Kind: code. Size M. Read at integriq development `966d6458` on 2026-09-28.

## Context

- **Connections.** `app_connection` (`lib/Settings/register.d/app-connection-schema.json`) holds `app`, `key`, `title`, `declaration`, `status` and the linked `source`. `ConnectionRegistryService::resolveRow()` (:256) resolves a row's status.
- **Calls.** `CallService::call()` (:2957) takes a source object and a request; `BrokeredCallService` injects a broker credential and refuses a client certificate beside a `credentialRef` (:752-755).
- **Geocoding.** `PdokGeocodingClient` (`lib/Adapters/Pdok/`) with an HTTP and a mock binding answers a postcode with a point (spec `pdok-adapter`, "Response Transformation to Canonical PostalAddress Shape").
- **The humaniq callers.** `RouteDistanceService` (humaniq `expenses-travel-calculation` D3) needs `distanceKmOneWay` and `routeProvider`. `RegisterPrefillService` (humaniq `people-register-prefill` D1, D2) maps RDW fields `merk`, `handelsbenaming`, `datum_eerste_toelating`, `catalogusprijs`, `brandstof_omschrijving` itself.
- **No templates.** No route planner or RDW source exists in `lib/Settings/register.d/`.

## D1. A call through a declared connection

`ConnectionCallRequestedEvent(sourceApp, connectionKey, method, path, query = [], body = null, correlationId = '')`. The listener finds the `app_connection` row for `sourceApp` and `connectionKey`, refuses `not-declared` when there is none and `not-linked` when no source is linked, and otherwise calls `CallService::call()` on the linked source. The result slot holds `status`, `body` (decoded JSON or the raw text) and `sourceSlug`. Only GET and POST are allowed; the path is appended to the source's location and may not leave it (no scheme, no `..`). A call is logged like every other source call.

Alternative considered: keep resolving `CallService` from integriq's container through `FleetAppId`. Rejected: ADR-041 names cross-container service resolution as not the pattern, and a call from a background job has no guarantee integriq's classes are loaded.

## D2. Route distance is one command

`RouteDistanceRequestedEvent(sourceApp, fromPostcode, toPostcode, mode = 'car', connectionKey = 'route-planner')`. `RouteDistanceService` geocodes both postcodes with PDOK, asks the route planner source linked to the app's connection for the fastest road route, and answers `distanceKmOneWay` (one decimal), `routeProvider` and the two points' municipality names. A postcode PDOK cannot place answers `unknown-postcode` naming it. Modes map to the planner's profile (`car` to driving, `bicycle` and `e-bike` to cycling, `walking` to walking); other modes answer `unsupported-mode`.

## D3. Templates

| Slug | Location | Credential | Category |
|---|---|---|---|
| `rdw-open-data` | `https://opendata.rdw.nl/resource` (dataset of registered vehicles) | none | `Government registers` |
| `route-planner-openrouteservice` | `https://api.openrouteservice.org` | API key by `credentialRef` | `Geo / Maps` |
| `route-planner-tomtom` | `https://api.tomtom.com/routing` | API key by `credentialRef` | `Geo / Maps` |

All dormant. `rdw-open-data` is available without a credential, so its catalogue status is available once linked.

## Declarative versus imperative

| Behaviour | Path | Rationale |
|---|---|---|
| The call | Imperative, typed event and listener | ADR-041 command with a result. |
| Geocode and route | Imperative service | Two outside calls composed. |
| Templates | Declarative seed fragments | Configuration. |

## Seed data

The three templates. The PDOK mock binding's fixture answers postcodes 2611 AB (Delft) and 3011 AD (Rotterdam), and the route planner's recorded answer gives 15.8 km, so the route distance test runs without a key.

## Risks

- [Route planners differ per call] the provider name goes with every answer, and humaniq stores it with the distance.

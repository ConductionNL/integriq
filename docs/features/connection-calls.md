# Connection calls and route distance

Other Conduction apps reach outside services through integriq instead of resolving integriq's classes. An app declares the connections it needs in its own `lib/Settings/connections.json`; an administrator links each one to an integriq source on the Connections page. The app then dispatches a typed command, and integriq makes the call with the linked source's credential. The app never holds the credential.

Both commands answer synchronously on the event itself. They never throw: every outcome is a result or a refusal with a code. A consumer guards the dispatch with `class_exists()` and treats an unhandled event (`isHandled() === false`, integriq not installed) as "no answer", for example by letting the person type the value.

## Calling a declared connection

`OCA\Integriq\Event\ConnectionCallRequestedEvent`

| Field | Type | Meaning |
|---|---|---|
| `sourceApp` | string | The calling app, for example `humaniq` |
| `connectionKey` | string | One of the app's declared connection keys, for example `rdw-voertuigen` |
| `method` | string | `GET` or `POST` (default `GET`) |
| `path` | string | The path below the linked source's location, starting with `/` |
| `query` | array | Query parameters, one scalar value each |
| `body` | array, string or null | A POST body; an array is sent as JSON |
| `correlationId` | string | The caller's own id |

On success `getResult()` holds `status` (the HTTP status), `body` (decoded JSON, or the raw text when the answer is not JSON) and `sourceSlug`. Otherwise `getRefusal()` holds `code` and `reason`:

| Code | When |
|---|---|
| `not-declared` | The app declares no connection with this key |
| `not-linked` | The connection is declared but no source is linked yet; the reason names the connection's title |
| `invalid` | A method other than GET or POST, a path that leaves the source's location (a scheme or host, `..`, its own query or fragment), or a query value that is not a single value |
| `call-failed` | The call could not be made |

Every call is logged like any other source call.

Example: the company car behind a licence plate, from the RDW open data source.

```php
$event = new ConnectionCallRequestedEvent(
    sourceApp: 'humaniq',
    connectionKey: 'rdw-voertuigen',
    path: '/m9d7-ebf2.json',
    query: ['kenteken' => 'GB123X'],
);
$dispatcher->dispatchTyped($event);
```

## Road distance between two postcodes

`OCA\Integriq\Event\RouteDistanceRequestedEvent`

| Field | Type | Meaning |
|---|---|---|
| `sourceApp` | string | The calling app |
| `fromPostcode` | string | Where the route starts, for example `2611 AB` |
| `toPostcode` | string | Where the route ends |
| `mode` | string | `car` (default), `bicycle`, `e-bike` or `walking` |
| `connectionKey` | string | The app's route planner connection (default `route-planner`) |

Integriq places both postcodes through PDOK and asks the route planner linked to the connection for the fastest road route. `getResult()` holds `distanceKmOneWay` (one decimal), `routeProvider` (`openrouteservice` or `tomtom`), `fromMunicipality` and `toMunicipality`. Refusal codes:

| Code | When |
|---|---|
| `unknown-postcode` | A postcode is not a Dutch postcode or PDOK cannot place it; the reason names it |
| `unsupported-mode` | The planner has no profile for the mode |
| `unsupported-provider` | The connection is linked to a source that is not a route planner |
| `geocoder-unavailable` | PDOK did not answer |
| `no-route` | The planner answered without a route |
| `not-declared`, `not-linked`, `invalid`, `call-failed` | As for a connection call |

## Source templates

| Template | Location | Credential | Category |
|---|---|---|---|
| `rdw-open-data` | `https://opendata.rdw.nl/resource` | None | Government registers |
| `route-planner-openrouteservice` | `https://api.openrouteservice.org` | A credential named `openrouteservice` | Geo / Maps |
| `route-planner-tomtom` | `https://api.tomtom.com/routing` | A credential named `tomtom-routing` | Geo / Maps |

All three ship switched off. Create the credential where one is needed, link the app's connection to the source, and enable it.

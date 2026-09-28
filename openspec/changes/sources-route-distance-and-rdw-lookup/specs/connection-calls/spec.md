# connection-calls Specification

## ADDED Requirements

### Requirement: An app calls a connection it declared through a typed command (REQ-CC-001)

Integriq MUST offer `OCA\Integriq\Event\ConnectionCallRequestedEvent` carrying the calling app, one of its declared connection keys, a method (GET or POST), a path, a query and an optional body. The listener MUST call the source an administrator linked to that connection, with the source's credential, and MUST write the status, the body and the source slug into the result slot. It MUST refuse `not-declared` for an unknown key, `not-linked` for a connection without a source, and `invalid` for a path that leaves the source's location, and it MUST NOT throw to the caller. The calling app MUST never receive the credential.

#### Scenario: an HR officer fills in a company car from its plate
- GIVEN humaniq declares `rdw-voertuigen` and an administrator linked it to the `rdw-open-data` template
- WHEN humaniq dispatches the command for plate GB-123-X
- THEN the result holds status 200 and the RDW record with make, first registration date and catalogue price, which humaniq maps onto the car
- @e2e exclude backend command; covered by PHPUnit with a recorded RDW answer

#### Scenario: the connection is not linked yet
- GIVEN humaniq declares `rdw-voertuigen` and no source is linked
- WHEN humaniq dispatches the command
- THEN the refusal is `not-linked` with the connection's title, and no outside call is made
- @e2e exclude backend command; covered by PHPUnit

### Requirement: Integriq answers the road distance between two postcodes (REQ-CC-002)

Integriq MUST offer `OCA\Integriq\Event\RouteDistanceRequestedEvent` with two Dutch postcodes and a transport mode. It MUST place both postcodes through PDOK, MUST ask the route planner linked to the app's route planner connection for a road route, and MUST answer the one-way distance in kilometres with one decimal and the provider's name. It MUST refuse `unknown-postcode` naming a postcode it cannot place and `unsupported-mode` for a mode the planner has no profile for. Integriq MUST ship dormant route planner templates for OpenRouteService and TomTom and a credential-free RDW open data template.

#### Scenario: a commuting allowance needs a distance
- GIVEN humaniq's route planner connection linked to OpenRouteService
- WHEN humaniq asks the distance from 2611 AB to 3011 AD by car
- THEN the answer is 15.8 km one way with provider `openrouteservice`
- @e2e exclude backend command; covered by PHPUnit with the PDOK mock and a recorded planner answer

#### Scenario: a postcode that does not exist
- GIVEN the postcode 0000 XX
- WHEN a distance is asked from it
- THEN the refusal is `unknown-postcode` naming 0000 XX
- @e2e exclude backend command; covered by PHPUnit

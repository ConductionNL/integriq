# Tasks: sources-route-distance-and-rdw-lookup

Kind: code. Size M. Halves for humaniq `expenses-travel-calculation` and `people-register-prefill` (rows humaniq `td-commute-allowance`, `exp-mileage`, `dm-plate-lookup`).

## Implementation tasks

### Task 1: The connection call command
- **spec_ref**: `openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-an-app-calls-a-connection-it-declared-through-a-typed-command-req-cc-001`
- **files**: `lib/Event/ConnectionCallRequestedEvent.php`, `lib/EventListener/ConnectionCallRequestedListener.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN humaniq's `rdw-voertuigen` linked to `rdw-open-data` WHEN humaniq asks for plate `GB-123-X` THEN the result holds status 200 and the vehicle record
  - GIVEN a declared connection with no source WHEN it is called THEN the refusal is `not-linked` with the connection's title
  - GIVEN a path with `..` WHEN it is called THEN the refusal is `invalid` and no call is made
- [ ] Implement
- [ ] Test (PHPUnit with the real event class and a recorded RDW answer)

### Task 2: Route distance
- **spec_ref**: `openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-integriq-answers-the-road-distance-between-two-postcodes-req-cc-002`
- **files**: `lib/Event/RouteDistanceRequestedEvent.php`, `lib/EventListener/RouteDistanceRequestedListener.php`, `lib/Service/RouteDistanceService.php`
- **acceptance_criteria**:
  - GIVEN the PDOK mock and a recorded planner answer WHEN 2611 AB to 3011 AD by car is asked THEN the answer is 15.8 km with the provider named
  - GIVEN a postcode PDOK cannot place WHEN asked THEN the refusal is `unknown-postcode` naming it
- [ ] Implement
- [ ] Test (PHPUnit; one live run with an OpenRouteService key on a dev instance)

### Task 3: Templates and catalogue
- **spec_ref**: `openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-integriq-answers-the-road-distance-between-two-postcodes-req-cc-002`
- **files**: `lib/Settings/register.d/rdw-open-data-source.json`, `route-planner-openrouteservice-source.json`, `route-planner-tomtom-source.json`, `lib/Service/CatalogRegistryService.php`
- **acceptance_criteria**:
  - GIVEN an upgrade WHEN the catalogue is materialised THEN the three templates are listed in their categories
- [ ] Implement
- [ ] Test (PHPUnit on `CatalogRegistryService::collect()`)

### Task 4: Docs
- **spec_ref**: `openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-an-app-calls-a-connection-it-declared-through-a-typed-command-req-cc-001`
- **files**: `docs/features/`, `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN the docs WHEN an app developer reads them THEN both events, their fields and refusal codes are listed
- [ ] Implement
- [ ] Test (docs build)

## Verification
- [ ] `openspec validate sources-route-distance-and-rdw-lookup --strict` passes
- [ ] `composer check:strict` once before push; PHPUnit exit code read

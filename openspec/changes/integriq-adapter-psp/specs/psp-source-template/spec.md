# psp-source-template Specification

**Status**: in-progress
**Scope**: integriq
**OpenSpec changes**:
- integriq-adapter-psp

## Purpose
Make integriq's already-shipped `live-payment-providers` capability (provider-neutral payment creation, deterministic mock, signature-gated webhook — `openspec/specs/live-payment-providers/spec.md`) discoverable and instantiable for the iDEAL/ouderbijdrage use case (M3-integrations.md row I15, change-plan.md row `integriq-adapter-psp`), by seeding one named Source template. This capability does NOT re-implement the payment adapter, mock provider, or webhook handling — those already exist and are out of scope (see design.md).

## ADDED Requirements

### Requirement: A seeded, mock-mode iDEAL payment-source template is discoverable in the Catalog (REQ-001)
The system MUST seed a `source`-schema object at `lib/Settings/register.d/ideal-ouderbijdrage-source.json` with `type: payment`, `configuration.provider: log`, `configuration.method: ideal`, which the existing `CatalogRegistryService::collectFromSeedFragments()` MUST surface as a `source-template` Catalog entry with slug `source-template:ideal-ouderbijdrage`, without any new registry code.

#### Scenario: The template appears in the Catalog's assembled entries
- GIVEN `lib/Settings/register.d/ideal-ouderbijdrage-source.json` as seeded by this change
- WHEN `CatalogRegistryService::collect()` is called
- THEN its returned entries include one with slug `source-template:ideal-ouderbijdrage`
- AND that entry's `category` reflects the `payment` type label

### Requirement: The seeded configuration produces a working mock iDEAL payment (REQ-002)
The system MUST seed a configuration that, when passed to the existing `LogPaymentProvider::createPayment()` unchanged, produces a deterministic mock payment envelope whose `extras.method` is `ideal`.

#### Scenario: The seeded configuration works against the existing mock provider
- GIVEN the `configuration` object from the `ideal-ouderbijdrage` seed fragment
- WHEN `LogPaymentProvider::createPayment(sourceConfiguration: $configuration, payload: ['amount' => ['value' => '25.00', 'currency' => 'EUR'], 'description' => 'Schoolreisje groep 6'])` is called
- THEN the returned envelope's `extras['method']` is `ideal`
- AND `paymentStatus` is `open`
- AND no exception is thrown and no network call is attempted

## Non-Functional Requirements

- **Performance:** N/A — a static seed fragment, no runtime cost beyond the existing Catalog assembly.
- **Accessibility:** N/A — no user interface in this change (the Catalog UI itself is out of scope, already shipped).
- **Internationalization:** N/A — no new user-facing strings; `name`/`description` are the same operator-facing metadata shape every existing `register.d` template already carries.

## Acceptance Criteria

- [ ] `CatalogRegistryService::collect()` includes `source-template:ideal-ouderbijdrage`.
- [ ] The seeded `configuration` produces a valid `ideal`-flavoured mock payment via the unmodified `LogPaymentProvider`.
- [ ] No changes to `PaymentProviderInterface`, `LogPaymentProvider`, `MolliePaymentProvider`, `PaymentIntentService`, or `PaymentsController`.

## Notes
This capability deliberately does not restate or re-verify REQ-LPP-002/003 (provider abstraction, signature-gated webhook) — those are `live-payment-providers`' own requirements, already specified and tested there. This spec's job is narrowly the template's discoverability and correctness against the existing provider, not the provider itself.

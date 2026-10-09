# connector-extension-kit Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- platform-connector-extension-kit

## Purpose

A separate Nextcloud app adds intake channels, digital post providers,
payment providers and connector templates to integriq through one documented
collect event, without editing integriq. Row `integriq:plt-connector-sdk`.

## ADDED Requirements

### Requirement: An extension app contributes adapters through a collect event (REQ-CXK-001)

Integriq MUST dispatch `OCA\Integriq\Event\RegisterConnectorExtensionsEvent`
once when each of its intake channel, digital post and payment registries is
first built, and once for the connector catalogue. The event MUST accept
intake channel adapters, digital post providers, payment providers and
directories of connector templates, each with the contributing app id.
Contributed items MUST work exactly like built-in ones.

#### Scenario: a third party adds a chat channel without touching integriq
- GIVEN an integration engineer's app that answers the event with an intake channel adapter for `rocketchat`
- WHEN the app is enabled and a signed message arrives on `/api/intake/channels/rocketchat/inbound`
- THEN the message is received and routed like one from a built-in channel
- e2e: `tests/e2e/connector-extension-kit.spec.ts`

#### Scenario: an extension's payment provider can be chosen
- GIVEN an app that contributes a payment provider with id `buckaroo`
- WHEN an administrator sets a payment source's provider to `buckaroo`
- THEN payments on that source go through the contributed provider
- @e2e exclude a server-side provider choice; covered by an integration test with the skeleton app

### Requirement: An extension adds and never replaces a built-in (REQ-CXK-002)

Each registry MUST register integriq's own adapters before contributed ones
and MUST refuse a contributed item whose id is already taken, logging the
refused class and the app that offered it.

#### Scenario: an extension cannot take over the Teams channel
- GIVEN an app that contributes an intake channel adapter claiming `teams`
- WHEN the intake registry is built
- THEN integriq's Teams adapter keeps the id, the contribution is refused, and the log names the app
- @e2e exclude a boot-time registry decision; covered by PHPUnit on IntakeChannelRegistry

### Requirement: An administrator sees what each extension contributed (REQ-CXK-003)

The Store MUST show each contributed adapter and template with the app that
provided it, and `occ integriq:extensions` MUST list every contributed item
with its app, seam, id and whether it was refused.

#### Scenario: an administrator checks an extension after installing it
- GIVEN an installed extension app that contributed one channel and one template
- WHEN an administrator runs `occ integriq:extensions`
- THEN both items are listed with the app name, and the Store shows the template card as provided by that app
- e2e: `tests/e2e/connector-extension-kit.spec.ts`

### Requirement: A developer builds an extension from a guide, a skeleton and contract tests (REQ-CXK-004)

Integriq MUST ship `docs/developers/connector-kit.md`, a working skeleton app
in `examples/connector-extension/`, and abstract contract test cases for the
three adapter interfaces in `tests/Contract/`. The skeleton's own tests MUST
extend the contract test cases and pass in integriq's CI.

#### Scenario: a developer starts from the skeleton
- GIVEN a developer who copies `examples/connector-extension/` and renames it
- WHEN they run its tests
- THEN the contract tests run against the sample adapter and pass, and the guide names each file they change
- @e2e exclude a developer workflow outside the browser; covered by the skeleton's PHPUnit run in CI

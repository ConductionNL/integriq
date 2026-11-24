---
kind: code
depends_on: []
---

# Proposal: platform-connector-extension-kit

## Summary

A developer who wants to add a connector to integriq today has to edit
integriq's own `Application.php`, because every registry is filled from a
hard-coded list there. This change adds one documented extension point, a
typed collect event a separate Nextcloud app answers to contribute intake
channels, digital post providers, payment providers and connector templates,
plus a guide, a skeleton app and contract tests, so a third party ships a
connector without forking integriq.

## Why

Row `integriq:plt-connector-sdk`, "Build your own connector with a documented
kit", rated partial and built. Five competitors rate it yes:

- n8n: "packages/@n8n/node-cli/src/commands has new, dev, build, lint and
  release commands to scaffold, run and publish a custom node package, with
  packages/@n8n/create-node as the starter".
- tyk: "coprocess/coprocess_object.pb.go and coprocess/coprocess_object_grpc.pb.go
  are the generated protobuf contract for gRPC plugins in any language,
  documented in coprocess/README.md".
- apisix: "docs/en/latest/plugin-develop.md and apisix/plugins/example-plugin.lua
  document writing a plugin".
- mulesoft: "https://docs.mulesoft.com/mule-sdk/latest/index.md the Mule SDK
  builds your own connectors and modules for Mule 4;
  https://docs.mulesoft.com/connector-builder/index.md Connector Builder
  generates connectors from API specs".
- frank: "connectors are Java classes implementing
  core/src/main/java/org/frankframework/core/ISender.java:33 ... loadable as
  plugins by core/src/main/java/org/frankframework/components/plugins/PluginLoader.java:44".

No demand row. The matrix note: "There is an internal base class with a good
docblock, but no published guide, generator or extension point; a third party
would have to edit integriq's Application.php or implement OpenRegister's
IntegrationProvider in their own app."

## What integriq already has

- Interfaces with registries behind them, each filled in
  `lib/AppInfo/Application.php`:
  - `IntakeChannelAdapterInterface` into `IntakeChannelRegistry`
    (:485-493), with a first-wins collision policy
    (`lib/Intake/IntakeChannelRegistry.php:70`).
  - `DigitalPostProviderInterface` into `DigitalPostProviderRegistry`
    (:533-541).
  - `PaymentProviderInterface`
    (`lib/Service/Payment/PaymentProviderInterface.php:38`), whose docblock says
    a new provider "is added by implementing this interface", with the
    service choosing between two classes by name
    (`lib/Service/PaymentIntentService.php:368`).
  - Connector-category adapters on `AbstractCategoryAdapterProvider`
    (`lib/Service/Adapter/AbstractCategoryAdapterProvider.php`), added to
    OpenRegister's integration registry at :1561-1565.
- Connector templates read from `lib/Settings/register.d/` by
  `CatalogRegistryService` (`lib/Service/CatalogRegistryService.php:321`).
- `docs/developers/` holds `README.md`, `developers.md`,
  `dashboard-http-datasource.md` and `styleguide.md`, none about adapters.
- The fleet's precedent for contribution: OpenRegister's
  `RegisterLeafProvidersEvent` and `RegisterFlowNodesEvent`, and ADR-066,
  which separates a collect event from a command.

## What this change builds

1. `OCA\Integriq\Event\RegisterConnectorExtensionsEvent`, a collect event
   integriq dispatches once per request when a registry is first used. An
   app's listener calls `addIntakeChannel()`, `addDigitalPostProvider()`,
   `addPaymentProvider()` or `addConnectorTemplates()`.
2. The four registries fill from built-ins first and the event second, so an
   extension adds and never replaces a built-in.
3. A payment provider registry in place of the two-way name switch, so a
   contributed provider can be chosen by id.
4. The Store and `occ integriq:extensions` show which app contributed what.
5. `docs/developers/connector-kit.md`, a skeleton app under
   `examples/connector-extension/`, and abstract contract test cases in
   `tests/Contract/` an extension author extends.

## Out of scope

- SMS providers. `messaging-sms-providers-and-notifynl-mail` introduces the
  SMS provider registry; adding `addSmsProvider()` follows once it lands.
- Object sidebar leaves. A third party contributes those through
  OpenRegister's `RegisterLeafProvidersEvent` under ADR-066; the guide points
  there.
- A code generator CLI. The skeleton is copied, not generated.
- A marketplace for extensions. They are Nextcloud apps and ship through the
  Nextcloud app store.

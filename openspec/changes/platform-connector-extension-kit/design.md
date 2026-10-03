# Design: platform-connector-extension-kit

Kind: code. Size M. Read at `development` 92f282bc.

## Where it fits

| Seam | Interface | Registry and wiring today |
|---|---|---|
| Intake channels | `lib/Intake/IntakeChannelAdapterInterface.php` | `IntakeChannelRegistry`, `lib/AppInfo/Application.php:485-493`, first wins (`IntakeChannelRegistry.php:70`) |
| Digital post | `lib/Service/DigitalPost/DigitalPostProviderInterface.php` | `DigitalPostProviderRegistry`, `Application.php:533-541` |
| Payments | `lib/Service/Payment/PaymentProviderInterface.php:38` | no registry: `PaymentIntentService::resolveProvider()` (:368) picks Mollie or log by name |
| Templates | `register.d` source objects | `CatalogRegistryService::collectFromSeedFragments()` (:321) reads one directory |
| Category adapters | `AbstractCategoryAdapterProvider` | OpenRegister `IntegrationRegistry`, `Application.php:1561-1565` |

## D1. A collect event, not a command

ADR-066 separates a collect event, "give me all the X providers", from a
command. Contributing an adapter is the first kind, so the extension point is
`OCA\Integriq\Event\RegisterConnectorExtensionsEvent extends
OCP\EventDispatcher\Event`, with:

- `addIntakeChannel(IntakeChannelAdapterInterface $adapter, string $appId)`
- `addDigitalPostProvider(DigitalPostProviderInterface $provider, string $appId)`
- `addPaymentProvider(PaymentProviderInterface $provider, string $appId)`
- `addConnectorTemplates(string $directory, string $appId)`, a directory of
  template files in the shape `connectors-catalogue-expansion` defines, or
  of `register.d` source objects until that change lands.

An extension app registers a listener in its own `register()` and answers
with its adapters from its own DI container. Nothing in integriq's
`Application.php` changes when an extension is installed.

Rejected: DI tags or container scanning. Nextcloud's container has no tagged
service lookup across apps, and a scan by class name is the phantom lookup
ADR-041 records as the fleet's failure mode.

Rejected: letting an app call `IntakeChannelRegistry::register()` directly
from its boot. Boot order across apps is not defined, so the registry could
be built before or after the call.

## D2. Built-ins first, extensions second, never a replacement

Each registry becomes lazy: it is built on first use, registers integriq's
own adapters, then dispatches the event once and registers what it collects.
With the first-wins policy `IntakeChannelRegistry` already has, an extension
claiming `teams` is refused and logged, and the built-in stays. The digital
post and payment registries get the same policy. Each entry remembers the
`appId` that contributed it.

## D3. A payment provider registry

`PaymentIntentService::resolveProvider()` (:368) returns Mollie for `mollie`
and the log stub for everything else, so a contributed provider could never
be chosen. A `PaymentProviderRegistry` keyed by provider id replaces the
switch, filled like the others. This overlaps `messaging-payments-review`
D4, which refuses an unknown provider value: whichever lands second uses the
registry the first one built.

## D4. Showing what an extension added

`CatalogRegistryService` gains a fifth list: extension entries, one card per
contributed adapter or template with `provided by <app name>`. A new
`occ integriq:extensions` command lists every contributed item with its app,
seam, id and whether it was refused, which is the first thing to run when an
extension's channel does not appear.

## D5. The kit

- `docs/developers/connector-kit.md`: the four seams, the event, the
  credential rule (every outbound call through the broker, ADR-064), the
  collision policy, and the pointer to OpenRegister's
  `RegisterLeafProvidersEvent` for object sidebar leaves.
- `examples/connector-extension/`: a minimal Nextcloud app with
  `appinfo/info.xml`, an `Application` registering one listener, one intake
  channel adapter for a fictional chat tool, and its tests.
- `tests/Contract/IntakeChannelAdapterContractTestCase.php`,
  `DigitalPostProviderContractTestCase.php` and
  `PaymentProviderContractTestCase.php`: abstract PHPUnit cases an extension
  extends with its own adapter to check it honours the interface's contract,
  such as refusing an unsigned inbound message.

## Declarative versus imperative

Registration is a collect event, which ADR-066 sanctions. Templates stay
declarative JSON. Nothing adds lifecycle behaviour to a schema.

## Seed data

No schema changes. The skeleton app is not installed by default, so a demo
install shows no extension entries.

## Risks

- An extension is PHP in the same process, with the same trust as any
  Nextcloud app. The kit does not widen that; the guide says so and says the
  broker is the only credential path.
- Making the registries lazy changes when they are built. A test asserts
  each registry has the same built-in entries as today when no extension
  answers.
- An extension written against an interface breaks when the interface
  changes. The guide states that the three interfaces follow semantic
  versioning with integriq's own version.

# Design: exchange-read-defaults

## Architecture Overview

The action matrix lives in `IAppConfig` (`integriq` / `actions`) as JSON.
`InitializeActions` (the OpenRegister `GenericInitializeActions` subclass) seeds it from
`lib/actions.seed.json` only when it is empty. A seed edit therefore reaches fresh installs
only. The new repair step `BroadenExchangeReadDefault` covers installed instances.

Decision rule, on the stored matrix `M`:

| `M` | `M['exchange.read']` | action |
|---|---|---|
| empty | n/a | none (the seeding step owns it) |
| non-empty | absent | write new default (absent reads as the `["admin"]` fallback, so it is untouched) |
| non-empty | exactly `["admin"]` | write new default |
| non-empty | anything else | none |

The step runs once: after a decision other than "empty" it sets the IAppConfig marker
`exchange_read_default_d33_applied`, and returns early when the marker is set. Without it, an
administrator who restores `["admin"]` would be broadened again on every upgrade.

Comparison is on the normalised list returned by `ActionAuthService::getMatrix()`.

## Nextcloud Integration

- Repair step: `OCA\Integriq\Repair\BroadenExchangeReadDefault implements IRepairStep`, wired in
  `appinfo/info.xml` `<post-migration>` and `<install>`, each directly after `InitializeActions`
  (`<install>` too, because the openconnector to integriq rename arrives as a new app with a
  carried-over matrix).
- Services: `ActionAuthService::getMatrix()` / `setMatrix()`, `LoggerInterface`.
- The step never throws: a `JsonException` from `setMatrix()` is logged and reported as a warning,
  because an exception aborts `occ upgrade`.

## Security Considerations

Broadens read access to exchange job status and rejections for two groups, as decided in D33.
The read model returns error codes and record references, never record values, so no pupil data
is exposed. Write actions stay admin only.

## File Structure

```
lib/actions.seed.json                          (modified)
lib/Repair/BroadenExchangeReadDefault.php      (new)
appinfo/info.xml                               (repair step registered)
tests/Unit/Repair/BroadenExchangeReadDefaultTest.php (new)
docs/administrators/exchange-jobs.md           (modified)
```

## Seed Data

No OpenRegister schema changes. The only seed is the action matrix entry above.

## Declarative-vs-imperative decision

Not applicable: no lifecycle, aggregation, notification or relation behaviour. A repair step is
the only place an upgrade can change a stored config value.

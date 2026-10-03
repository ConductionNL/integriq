---
kind: code
depends_on: []
---

# Proposal: sources-ecb-exchange-rates

## Summary

Every exchange rate in shillinq today was typed by hand: its rate import job skips because the `treasury-rates` connection has nothing behind it. This change gives integriq a credential-free source for the euro reference rates the European Central Bank publishes every working day, stores them, and answers a typed rate request from a sibling app, including cross rates through the euro.

## Why

The owner-moves pass of 2026-09-28 handed this half to integriq from shillinq `banking-fx-at-booking`, merged on shillinq `development`. It is `build` by the decision rule, and a source is integriq's core area (sources is the area of most of the first 30 rows of its matrix).

shillinq `banking-fx-at-booking`, Cross-Project Dependencies: "integriq: a rates source serving ECB reference rates, which the `treasury-rates` connection and `TreasuryRateAdapterInterface` would then bind to instead of `LogTreasuryRateAdapter`. integriq has no such source on development (no ECB or exchange-rate source in its tree on 2026-09-27). Until it exists, rates are kept by hand and this change works on them." Its Out of Scope: "Fetching rates from the ECB or a market data provider. That source belongs in integriq (ADR-091)."

Row in the shillinq matrix: `bnk-fx-rates`, "Book foreign-currency transactions at daily exchange rates." Four competitors rate yes:

- Moneybird: "Moneybird haalt automatisch de wisselkoers op" (https://www.moneybird.nl/changelog/facturen-in-vreemde-valuta-vaker-automatisch-gekoppeld/).
- Odoo: automatic rates "Daily, Weekly, or Monthly" (https://www.odoo.com/documentation/19.0/applications/finance/accounting/get_started/multi_currency.html).
- Exact Online: `financial/ExchangeRates` in its REST API (https://start.exactonline.nl/docs/HlpRestAPIResources.aspx).
- Twinfield: "Koersen" maintained and imported (https://taasupportportal.wolterskluwer.com/nl/nl-nl/artikel/valuta-s-3041130).

## What integriq already has

- A credential-free public source populated by a seeded mapping, synchronization and job: `endoflife-date-source` (`lib/Settings/register.d/endoflife-date-source.json`, `endoflife-date-source-cycles.json`).
- XML source payloads converted to arrays before mapping (`openspec/specs/synchronization-engine/spec.md` REQ-003, scenario "XML payload converted to a sanitised array"), which the ECB file needs.
- Typed ADR-041 events with a result slot (`lib/Event/DigitalPostSendRequestedEvent.php`).

## What this change builds

1. A seeded source `ecb-eurofxref` for the ECB daily reference rates, with a 90-day history file for a first fill.
2. An `exchange_rate` schema and a daily synchronization that stores one object per currency per date.
3. `OCA\Integriq\Event\ExchangeRateRequestedEvent`: a sibling asks for the rate of a currency pair on a date and gets the rate, the date it applies to and the source, with cross rates computed through the euro.
4. A CloudEvent when a new day of rates is stored, and a catalogue item.

## Out of scope

- Applying a rate to a booking, manual overrides and revaluation (shillinq `banking-fx-at-booking`).
- Commercial market data providers. A second provider is a second source with its own mapping.
- Intraday rates.

## Impact

- New: `lib/Settings/register.d/ecb-exchange-rates-source.json` (schema, source, mapping, synchronization, job), `lib/Event/ExchangeRateRequestedEvent.php`, its listener and `lib/Service/ExchangeRateService.php`.
- Changed: `lib/AppInfo/Application.php`, `lib/Service/CatalogRegistryService.php`.

## Cross-project dependencies

- shillinq binds `TreasuryRateAdapterInterface` to an adapter that dispatches `ExchangeRateRequestedEvent` (its half of `banking-fx-at-booking`), and `FxRateImportJob` stores what it gets as `FxRate` with source `ecb`.

## Risks

- The ECB publishes no rate on weekends and TARGET holidays. The answer gives the newest rate on or before the date and says which date that is; the caller decides whether it is too old.

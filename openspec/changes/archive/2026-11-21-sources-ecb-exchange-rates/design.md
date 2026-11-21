# Design: sources-ecb-exchange-rates

Kind: code. Size S. Read at integriq development `966d6458` on 2026-09-28.

## Context

- **No rate source.** `grep -rli "\becb\b\|exchange.rate\|wisselkoers"` over `lib/` finds nothing; one archived design mentions an ECB rate UI in passing.
- **The shillinq caller.** shillinq `FxRateImportJob` (`lib/BackgroundJob/FxRateImportJob.php`, shillinq development) calls `TreasuryRateAdapterInterface::fetchFxSpot(baseCurrency, quoteCurrency, asOf)` per configured pair and skips while the adapter is dormant; `LogTreasuryRateAdapter` is bound today. shillinq `FxRate` stores `transactionCurrency`, `baseCurrency`, `date`, `source` (ecb, manual, bank-feed) and `rate`.
- **The pattern to copy.** `endoflife-date-source.json` declares its schemas in register `integriq` and seeds a credential-free source; `endoflife-date-source-cycles.json` seeds one mapping, synchronization and job per feed, with a comment on why each field is mapped the way it is.
- **Typed events.** `DigitalPostSendRequestedEvent` carries readonly inputs and a result slot; its listener catches every throwable and records a refusal.

## D1. Source and store

Source `ecb-eurofxref-daily`: `https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml`, no authentication, XML, shipped enabled. Its `configuration.historyLocation` names `eurofxref-hist-90d.xml`, which the first run against an empty store reads instead, for a first fill. Schema `exchange_rate` in register `integriq`: `baseCurrency` (always EUR for this source), `currency`, `rateDate`, `rate` (units of `currency` per euro), `source` (`ecb`), unique on `source`, `currency` and `rateDate`. Any signed-in account reads it; writes are left to administrators and the import's system context.

The import is a small service and a timed job (`ExchangeRateImportService`, `ExchangeRateSyncJob`), not a seeded mapping and synchronization. Measured while building: the ECB file puts the date on the parent `Cube time` element and the rate on each child `Cube currency`, so a per-item synchronization over the children never sees the date of the item it maps, and the contract key would have to combine two fields. The job runs every six hours; the ECB publishes around 16:00 CET, a run before that stores nothing, and the newest date lands the same day. Uniqueness is kept by reading the currencies stored for a date before saving.

## D2. The request answers one pair on one date

`ExchangeRateRequestedEvent(sourceApp, baseCurrency, quoteCurrency, asOf, maxAgeDays = 7)`. `ExchangeRateService` takes the newest stored date on or before `asOf`. EUR against another currency is the stored rate or its inverse; two non-euro currencies are divided through the euro rates of the same date. The result slot holds `rate` (units of `quoteCurrency` per unit of `baseCurrency`), `rateDate`, `source` `ecb` and `derived` (true for a cross rate). No rate within `maxAgeDays` answers refusal `no-rate` with the newest date there is.

Alternative considered: write shillinq's `FxRate` objects directly from integriq. Rejected: `FxRate` carries the administration and a manual-override reason, which are shillinq's, and shillinq already has the import job that stores what it gets.

## D3. Announce a new day

When a synchronization run stores a new `rateDate`, integriq emits `nl.conduction.fx.rates.published` with `{source, rateDate, currencies}`, so a consumer can import straight away instead of waiting for its own schedule.

## D4. Catalogue

A catalogue item for `ecb-eurofxref` in category `Bank`, one of the categories shillinq's integrations page shows (`platform-integration-catalogue` D1), with status available, since it needs no credential.

## Declarative versus imperative

| Behaviour | Path | Rationale |
|---|---|---|
| Fetching and storing rates | Seeded source; imperative import service and timed job | The date sits on the parent element (D1). |
| Answering a pair on a date | Imperative, a typed event and a small service | Cross rates and date fallback are arithmetic. |

## Seed data

The source only. Test fixtures are stored copies of the ECB files under `tests/fixtures/ecb/` (the daily file of 2026-10-30 and the 90-day file trimmed to three days), not seeded objects.

## Risks

- [The ECB changes the file format] the mapping test runs against a stored copy of the file, and a failed run keeps yesterday's rates and logs it.

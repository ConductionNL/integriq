# ECB exchange rates

## Overview

Integriq keeps the euro foreign exchange reference rates that the European
Central Bank publishes every working day, so a sibling app such as shillinq
can book a foreign-currency transaction at the rate of its date instead of a
rate somebody typed in.

The source `ecb-eurofxref-daily` ships enabled: the ECB files are public and
need no credential. It is listed in the connector catalogue under **Bank**.

## How the rates are stored

`ExchangeRateSyncJob` runs four times a day and stores every rate it has not
stored yet as an `exchange_rate` object in register `integriq`:

| Field | Meaning |
|-------|---------|
| `baseCurrency` | Always `EUR` for this source |
| `currency` | ISO 4217 code, for example `USD` |
| `rateDate` | The publication date the rate applies to |
| `rate` | Units of `currency` per euro |
| `source` | `ecb` |

- The first run against an empty store reads the 90-day history file, so the
  last three months are there from the start.
- A currency is stored once per date; a second run on the same day stores
  nothing.
- The ECB publishes around 16:00 CET and not at weekends or on TARGET
  holidays. A run before publication stores nothing; the next run picks the
  day up.
- A failed download keeps the rates already stored and is logged.

Any signed-in account can read the rates as OpenRegister objects, for example
`GET /apps/openregister/api/objects/integriq/exchange_rate?currency=USD&rateDate=2026-10-30`.

When a run stores a new date, integriq emits the CloudEvent
`nl.conduction.fx.rates.published` with the source, the date and the
currencies, so a consumer can import straight away.

## Asking for a rate from another app

A sibling app dispatches `OCA\Integriq\Event\ExchangeRateRequestedEvent`
with a base currency, a quote currency and a date, and reads the answer from
the event once `dispatchTyped()` returns:

```php
$event = new ExchangeRateRequestedEvent(
    sourceApp: 'shillinq',
    baseCurrency: 'USD',
    quoteCurrency: 'EUR',
    asOf: '2026-09-27',
    maxAgeDays: 7,
);
$dispatcher->dispatchTyped($event);

$event->getResult();
// ['rate' => 0.892618..., 'rateDate' => '2026-09-25', 'source' => 'ecb', 'derived' => false]
```

- The answer uses the newest stored date on or before `asOf`, and
  `rateDate` names that date. The caller decides whether it is too old.
- `rate` is units of the quote currency per unit of the base currency.
- A pair without the euro (USD to GBP) is computed through the euro rates of
  one and the same date and has `derived` set to true.
- When no rate lies within `maxAgeDays` (at most 31), `getResult()` is null
  and `getRefusal()` holds `code` `no-rate`, a `reason` and `newestDate`, the
  newest stored date. A malformed currency or date is refused with
  `invalid-request`, and a store that cannot be read with `unavailable`. The
  event never throws to the caller.
- `isHandled()` is false when integriq is not installed or not enabled.

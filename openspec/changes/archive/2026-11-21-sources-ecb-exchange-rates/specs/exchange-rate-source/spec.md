# exchange-rate-source Specification

## ADDED Requirements

### Requirement: The ECB reference rates are stored every working day (REQ-FX-001)

Integriq MUST ship a credential-free source for the euro foreign exchange reference rates of the European Central Bank, MUST store one `exchange_rate` per currency per publication date, and MUST NOT store the same currency and date twice. When a run stores a new date it MUST emit `nl.conduction.fx.rates.published` with the source, the date and the currencies. A failed run MUST keep the rates already stored.

#### Scenario: the daily run stores Friday's rates
- GIVEN the ECB file for 2026-09-25
- WHEN the daily synchronization runs twice
- THEN one `exchange_rate` per currency exists for 2026-09-25 and one published event names that date
- @e2e exclude scheduled synchronization; covered by PHPUnit against a stored file

### Requirement: A sibling app asks for a rate on a date (REQ-FX-002)

Integriq MUST offer `OCA\Integriq\Event\ExchangeRateRequestedEvent` with a base currency, a quote currency and a date. The answer MUST use the newest stored rate on or before that date and MUST name the date it applies to. A pair without the euro MUST be computed through the euro rates of the same date and marked derived. When no rate lies within the allowed age the event MUST be answered with refusal `no-rate` and the newest date available, and MUST NOT throw to the caller.

#### Scenario: shillinq books a dollar invoice on a Sunday
- GIVEN stored rates for Friday 2026-09-25 with USD at 1.1203 per euro
- WHEN shillinq asks for USD to EUR on 2026-09-27
- THEN the answer is 0.892618 euro per dollar, rate date 2026-09-25, source `ecb`
- @e2e exclude backend event; covered by PHPUnit with the real event class

#### Scenario: a pair without the euro
- GIVEN stored USD and GBP rates for 2026-09-25
- WHEN USD to GBP is asked for that date
- THEN the answer is derived through the euro and says so
- @e2e exclude backend event; covered by PHPUnit

#### Scenario: no recent rate
- GIVEN no stored rate in the seven days before the date asked
- WHEN a pair is asked
- THEN the event is answered with refusal `no-rate` and the newest date available
- @e2e exclude backend event; covered by PHPUnit

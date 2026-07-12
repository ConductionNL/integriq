---
status: in-progress
---

# psd2-ais-bank-feed-connector Specification

## Purpose

OpenConnector consumes PSD2 Account Information Service (AIS) feeds from
licensed EU aggregators (GoCardless Bank Account Data, Tink, Klarna Kosma,
Yapily, etc.) and exposes them to sibling apps as bank transactions, per ADR-022
and shillinq's `bookkeeping-bank-connectors` REQ-BC-001. It owns the
redirect-based SCA/consent flow, consent-token storage (credential broker,
ADR-007), account discovery, and the scheduled transaction sync that emits
`nl.conduction.bankfeed.transactions.synced`. shillinq references the
openconnector `Source` slug and consumes the events; no aggregator client,
OAuth flow, or consent record lives in shillinq. Built on the existing
`source-management`, `job-scheduling`, and `events-cloudevents` capabilities.

@e2e exclude backend PSD2 AIS feed (SCA flow, account discovery, scheduled sync) — covered by PHPUnit/Newman, no browser UI

**OpenSpec changes**
- `psd2-ais-bank-feed-connector` (active) — introduces the
  `Psd2AggregatorProviderInterface` abstraction (`log`/sandbox + generic `rest`
  bindings), the redirect-based SCA connect/callback endpoints with brokered
  token storage, account discovery, the `BankfeedSyncJob` scheduled transaction
  sync emitting `nl.conduction.bankfeed.transactions.synced`, and the
  consent-lifecycle CloudEvents. While active, the normative requirements
  (REQ-001..006) live in the change's delta spec
  (`openspec/changes/psd2-ais-bank-feed-connector/specs/psd2-ais-bank-feed-connector/spec.md`)
  and merge here on archive.

## Requirements

_Defined in the active change delta (REQ-001..006); merged here on archive._

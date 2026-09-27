# Design: integriq-adapter-lvs-imports

## Architecture Overview
Mirrors the existing dormant-adapter shape in `lib/Adapters/Berichtenbox` and `lib/Adapters/Pdok`:

```
UwlrResultImportSourceAdapter (lib/Sources/Lvs/)
  -> UwlrResultImportClient (abstract, lib/Adapters/Lvs/)
       -> UwlrResultImportClientMock   (default, deterministic)
       -> UwlrResultImportClientHttp   (NOT built this change — see proposal Out of Scope)
```

Four Source rows (`lvs-cito-dult`, `lvs-iep`, `lvs-boom`, `lvs-dia`) share one adapter class and one client family. The Source adapter's `toLvsImportPayload()` method is the single mapping seam between the UWLR-shaped wire record and learniq's `lvs-import-contract` payload field names.

## API Design
No new HTTP endpoint. The Source adapter is invoked by integriq's existing job-execution path (`DataExchangeRunHandler`-equivalent on the integriq side, i.e. whatever polls/pulls a Source and hands the result to the configured job) — this change adds the Source and its client, not a new controller route.

## Database Changes
None. No OpenRegister schema changes on the integriq side.

## Nextcloud Integration
- Controllers: none new.
- Services: `OCA\Integriq\Adapters\Lvs\UwlrResultImportClient` (abstract), `UwlrResultImportClientMock`.
- Source facade: `OCA\Integriq\Sources\Lvs\UwlrResultImportSourceAdapter` (constructor-injected `IAppConfig`, `LoggerInterface`, `UwlrResultImportClient`, following `BerichtenboxSourceAdapter`'s constructor shape exactly).
- Mappers/Entities: none (the seed row is plain JSON in `lib/sources.seed.json`, consistent with the PDOK precedent).
- Events/Hooks: none.

## Security Considerations
Pupil-identifying fields (`leerlingReference`, any BSN-shaped value) are NEVER passed to the structured logger, matching the `BerichtenboxSourceAdapter::checkMailbox()` precedent (`bsn_length_check` boolean only). The debug log records only a result-batch summary: supplier id, record count, and the configured `isActive()` flag. No new authentication surface — Source rows ship `auth: none` at the mock layer; a live binding (out of scope) would need to resolve real koppelpartner credentials through the same broker pattern as `PkiOverheidCredentialResolver`, never inline in a Source configuration value.

## File Structure
```
lib/
  Adapters/
    Lvs/
      UwlrResultImportClient.php        (abstract)
      UwlrResultImportClientMock.php
  Sources/
    Lvs/
      UwlrResultImportSourceAdapter.php
tests/
  Unit/
    Adapters/
      Lvs/
        UwlrResultImportClientMockTest.php
    Sources/
      Lvs/
        UwlrResultImportSourceAdapterTest.php
  fixtures/
    lvs/
      fixture-uwlr-result-batch.json
```

## Seed Data
`lib/sources.seed.json` gains four rows (`id`, `name`, `description`, `category: "onderwijs"`, `subCategory`, `adapterClass: "OCA\\Integriq\\Sources\\Lvs\\UwlrResultImportSourceAdapter"`, `location` set to each supplier's public product-page URL as a placeholder — no real endpoint exists to seed — `type: "uwlr"`, `auth: "none"`, `isEnabled: false`, `documentation`, `reference: "lvs.import.feature_flag"`). Not an OpenRegister schema addition, so the ADR-016 seed-object table does not apply here; the four seed rows themselves ARE the seed data, listed exhaustively above.

## Trade-offs
One shared client for all four suppliers versus four independent clients: chosen because the corpus evidence for all four points at the same UWLR wire format and doing otherwise would quadruple near-identical dormant code for zero behavioural difference in mock mode. If a live binding later needs a real per-supplier quirk (e.g. Cito's DULT-verwerking has its own step sequence per the LVS FAQ), that binding can subclass `UwlrResultImportClient` directly without touching the other three Source rows.

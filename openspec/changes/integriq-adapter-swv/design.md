# Design: integriq-adapter-swv

## Architecture Overview
```
SwvHandoffSourceAdapter (lib/Sources/Swv/)
  -> SwvHandoffClient (abstract, lib/Adapters/Swv/)
       -> SwvHandoffClientMock   (default, deterministic)
       -> a live OSO-shaped binding (NOT built this change)
```

Two Source rows (`swv-kindkans`, `swv-ldos`) share one adapter class and one client family, matching the `integriq-adapter-lvs-imports` precedent exactly.

## API Design
No new HTTP endpoint. Invoked through integriq's existing job-execution path, same as the LVS and rostering adapters.

## Database Changes
None.

## Nextcloud Integration
- Controllers: none new.
- Services: `OCA\Integriq\Adapters\Swv\SwvHandoffClient` (abstract), `SwvHandoffClientMock`.
- Source facade: `OCA\Integriq\Sources\Swv\SwvHandoffSourceAdapter`.
- Mappers/Entities: none.
- Events/Hooks: none.

## Security Considerations
A SWV support-request/TLV dossier carries sensitive pupil care data (zorgvraag, onderwijsbehoeften). Following the `UwlrResultImportSourceAdapter` precedent: pupil-identifying fields (`pupilReference`, any BSN-shaped value) are NEVER passed to the structured logger — only a `recordCount`-equivalent summary (dossier count = 1, `dossierType`, `isActive()`, `flavour()`).

**Privacyconvenant holder — operational gate, not a code blocker.** `SwvHandoffSourceAdapter::privacyconvenantHolder()` reads the `swv.privacyconvenant.holder` app-config key (default: empty string) and includes it in the debug-log summary so an operator/auditor can see at a glance whether the governance question has been answered for this instance. It is deliberately NEVER read by `isActive()` or any other gating logic — per the lane brief, this is recorded, not enforced, exactly like the DUO-certificate-holder note in `integriq-adapter-lvs-imports`'s proposal.md.

## File Structure
```
lib/
  Adapters/
    Swv/
      SwvHandoffClient.php        (abstract)
      SwvHandoffClientMock.php
  Sources/
    Swv/
      SwvHandoffSourceAdapter.php
tests/
  Unit/
    Adapters/Swv/SwvHandoffClientMockTest.php
    Sources/Swv/SwvHandoffSourceAdapterTest.php
  fixtures/
    swv/fixture-swv-dossier.json
```

## Trade-offs
One shared client for both receivers, same reasoning as the LVS and rostering adapters: behaviourally identical in mock mode, avoiding duplicate dormant code. Onderwijs Transparant and TOP dossier (also OSO-connected per care-swv/round1/sources.md) are deliberately not added as a third/fourth row — the lane brief scopes this change to Kindkans and LDOS only; adding more would silently expand scope beyond what was asked.

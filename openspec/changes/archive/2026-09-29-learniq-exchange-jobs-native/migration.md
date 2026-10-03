# Migration: learniq-exchange-jobs-native

## Database

No tables, columns or Nextcloud migration classes. Integriq stores everything in OpenRegister.

## Register

One ADR-037 fragment, `lib/Settings/register.d/learniq-exchange-jobs.json`, merged at load by
`InitializeRegister`:

- `job` 1.2.0 to 1.3.0: fourteen optional exchange properties (design D1). Existing jobs are
  unaffected; none of the new properties is required.
- `sync_item_dead_letter` 1.0.0 to 1.1.0: nine optional exchange properties (design D6).
- 28 `mapping` seed rows under `components.objects`.

The fragment signature is folded into the import version, so OpenRegister re-imports on upgrade
without a manual step.

## Data from learniq

Integriq migrates nothing by itself. learniq's repair step (change `data-exchange-to-integriq`)
sends each old `DataExchangeJob` through `ExchangeJobRequestedEvent` with a `history` block and
each customised `DataMappingProfile` through `ExchangeMappingRequestedEvent`. Integriq's side of
that is idempotent on `history.legacyId` and on the mapping slug.

## Rollback

Remove the fragment. The properties stay in stored objects and are ignored; the seed rows stay
until an administrator deletes them.

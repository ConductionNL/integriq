# Proposal: rename-dutch-columns-follows-the-schema

kind: code. Ruben approved the fix on 2026-10-05.

## Why

`RenameDutchColumns` maps `kenmerk` to `reference` and applied that to every shard table of the register. Four schemas keep `kenmerk` on purpose, because it is the partner's correlation field: `rod_message`, `verzuim_message`, `oso_message` and `uwlr_eduv_message`. On upgrade their column was renamed to `reference` while the schema still declares `kenmerk`.

Measured live: an upgrade logged "4 renamed" and left those four tables without `kenmerk` (`ifu-live` run-1). The ROD and Verzuimloket retours look an outbound message up by kenmerk and answered 503 "column t.kenmerk does not exist" (`iwh-live` run-7). The kenmerk stored before the upgrade sat in `reference`, which nothing reads.

No other column broke this way. Of the 13 pairs in the map, only `kenmerk` is declared in its Dutch form by a schema.

## What changes

- The step moves a table towards the name its schema declares. Schema declares the English name: Dutch column to English, as before. Schema declares the Dutch name: English column back to Dutch, which repairs the four tables. Both, neither, or an unreadable schema: the table stays as it is.
- Rename when the target column is missing, copy where MagicMapper has re-added it, never overwrite a stored value, never drop a column. A second run changes nothing.

## Capabilities

### New Capabilities

- `register-vocabulary`: REQ-RV-001.

## Impact

- `lib/Repair/RenameDutchColumns.php`; app version bumped so the step runs on upgrade.

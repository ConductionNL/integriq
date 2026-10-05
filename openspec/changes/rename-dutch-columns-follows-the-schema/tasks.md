# Tasks: rename-dutch-columns-follows-the-schema

Ruben approved the fix on 2026-10-05.

- [x] 1.1 Find the cause: `lib/Repair/RenameDutchColumns.php` COLUMN_MAP `'kenmerk' => 'reference'`, applied to every table; list the other pairs (none declared in Dutch)
- [x] 1.2 Direction from the schema, restore branch, PHPUnit over an in-memory model (5 of 6 red on the old step)
- [ ] 1.3 Live: a pre-fix install with history upgrades, the four tables get `kenmerk` back with their data, a ROD and a Verzuimloket retour are stored (`ifu-live/commands.md`)

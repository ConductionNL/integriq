# Tasks: brp-relations-read

- [x] Contract test against the mock answer the seeded source ships (red first).
- [x] `BrpRelationReader`, the source setting, the gateway's `readWithoutBodies` (first built as `readUnrecorded`) and `configuration()`.
- [x] The person read leaves relation fields out.
- [x] Dossiq's conflict-of-interest check reads relations here (dossiq pull request, paired). Landed on dossiq `development` with its change `brp-client-retired`: `lib/Service/Registry/PersonRelationReader.php` reaches `OCA\Integriq\PropertySource\Provider\BrpRelationReader` by class name and the conflict-of-interest check reads through it.
- [ ] Live pass against the Haal Centraal proefomgeving, with `relationFields` on a source whose authorisation covers the three fields.

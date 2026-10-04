# LANE-LOG f-rod-xsd (integriq)

- 18:35 start. #2248 merged at 2197a6cd7 (16:33Z). vendor + node_modules missing: npm ci ok; composer install needed `--ignore-platform-reqs` (ext-bcmath, ext-soap missing locally).
- XSD search: duo.nl zakelijk supplier pages PO/VO/MBO list PvE PDFs only; ROD pages no downloads; web + GitHub code search on DUO_PO_* contract names: nothing. PvE 5.1.3: supplier must register with DUO; supplier page: new suppliers contact helpdeskpo@duo.nl. Verdict: XSD not public.
- Branch `docs/rod-xsd-check` from origin/development: `docs/administrators/rod-xsd-check.md` + SUMMARY entry. Lists files to request, adapter output today, 5 divergences readable from the PvE (registration is not a DUO message; field names; no bedrijfsdocument; own wrapper; Advies case), and the validation to run (snippet smoke-tested against a dummy XSD).
- Finding: children of AanleverenAdviesVO_Request are built without namespace but serialise without xmlns="", so on the wire they are qualified; validating the in-memory DOM gives a different answer.
- 19:10 PR https://github.com/ConductionNL/integriq/pull/2259 (docs/rod-xsd-check @ d396d6d). check:strict exit 1 (11 AppInfo PHPUnit errors, env/inherited; static analysers green), lint/format/l10n 0, gates exit 1 (gate-53 runner ESM error, inherited). Not merged. DONE.

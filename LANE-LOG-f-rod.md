# Lane f-rod (integriq half), 2026-09-28

## (a) exchange-read-defaults
- branch feat/exchange-read-defaults (from origin/development e1e66b41a)
- seed exchange.read -> admin, coordinators, compliance-officers; repair step BroadenExchangeReadDefault (run-once marker, only untouched ["admin"] or absent); registered in post-migration and install after InitializeActions; docs updated
- diff checks: php -l 0, phpcs 0, phpunit --filter 6/6 OK
- check:strict exit 1: only 11 inherited errors in tests/Unit/AppInfo (TypeError assigning MockObject_IAppContainer to App::$container typed DIContainer, tests/Helpers/AppContainerInjection.php:93); lint, phpcs, phpmd, psalm, phpstan clean; 4293 tests
- npm run lint 0 (112 warnings, 0 errors), format 0, test:l10n 0, check:schema-l10n 0
- hydra gates --scope-to-diff: first run caught gate-110 (repair step without version bump) -> bumped to 0.4.8-unstable.20260928100001; rerun exit 1 only gate-112 newman-reach on untracked .tmp/r3/{devtree,prtree} left by the previous lane (local artifact)
- PR https://github.com/ConductionNL/integriq/pull/2245 opened; opsx-verify: 0 critical/warning

## (b) rod-adapter-bsn
- branch feat/rod-adapter-bsn (from origin/development e1e66b41a), pushed
- translator: persoonsgebondenNummer choice element (legacy bsn -> burgerservicenummer); schooladvies = AanleverenAdviesVO_Request; RodPersonalNumberRedactor on provider/transport/retour/retry messages; runner logs mapping exception class only; learner mapping row 1.1.0, new schooladvies row; default mapping for learniq bron-rod jobs in ExchangeJobService
- diff checks: php -l, phpcs, phpstan, phpmd (both rulesets) 0; phpunit Rod|Exchange|LearniqExchange 180 OK
- Element names are PvE field names in camelCase: the XSD is not in the PvE (noted in design + PR)
- strict run 1: phpmd NEW ExcessiveClassComplexity 50 on RodEnvelopeTranslator -> extracted RodAdviesVoBuilder; rerunning strict
- strict run 2 exit 1: only the 11 inherited AppInfo errors; lint/format/l10n/schema-l10n 0; gates exit 2 (gate-53 node ESM crash, gate-112 .tmp/r3 artifact, gate-23 WARN untouched file)
- PR https://github.com/ConductionNL/integriq/pull/2248 ; opsx-verify: 0 critical/warning

## (c) exchange-import-landing
- branch feat/exchange-import-landing pushed; ExchangeRecordsReceivedEvent + dispatcher land() for lvs-results/oso/migration-import import; runner uses acceptedCount; new code no-owner-answer (catalogue row 1.1.0); docs; contract in design.md
- diff checks 0; phpunit Exchange|Learniq 76 OK
- strict exit 1 (only 11 inherited AppInfo errors), lint/format/l10n/schema-l10n 0, gates exit 2 (gate-53 tooling crash, gate-112 .tmp/r3 artifact, gate-23 WARN)
- PR https://github.com/ConductionNL/integriq/pull/2251 ; opsx-verify 0 critical/warning
## Lane done: #2245, #2248, #2251. Not merged. CI not polled.

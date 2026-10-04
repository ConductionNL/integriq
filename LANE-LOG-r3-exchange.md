# Lane log r3-exchange (integriq half)

Lane: r3-exchange, part 1 of 2. Never staged.
Clone: /home/rubenlinde/memcap-work/lq-lanes/iq-adapters-b (previous lane's branch feat/integriq-adapter-uwlr-eduv untouched).
Remote: https://github.com/ConductionNL/integriq.git

## Change 1: learniq-exchange-jobs-native — PUSHED, PR OPEN

- Branch: feat/learniq-exchange-jobs-native, cut --no-track from origin/development 413357ec9.
- Commits: 2effdf211 (feature), b54937c77 (flat findAll list, isPassThrough, $ref on exchangeJob).
- PR: https://github.com/ConductionNL/integriq/pull/2220 (base development). Not merged, CI not polled.
- Dedupe: openspec/changes/connectors-data-exchange-dispatch (proposal only) designs the routing for a
  learniq-owned job; D7 supersedes its learniq half; its D4 ack listener still applies.
- Gate contract: ExchangeGateRequestedEvent in process (allow(records) / refuse(code, reason), first answer
  wins, fail closed on owner missing / app absent / unanswered / error); owning app serves
  GET /apps/<app>/api/exchange-gates/{jobId} with the same decision for people. Integriq never calls it
  over HTTP (cron has no session; ADR-041). Recorded as an assumption in the PR.
- Mapping slugs shared with learniq (23): learniq-bron-rod-export-learner, learniq-oso-export-dossier,
  learniq-leerplicht-export-melding, learniq-swv-export-zorgvraag, learniq-timetable-import-{zermelo,untis,
  xedule,timeedit}, learniq-lvs-results-import-uwlr, learniq-oso-import-dossier, learniq-uwlr-export-{pupil,
  group,teacher}, learniq-uwlr-import-results, learniq-edu-v-export-{onderwijsdeelnemers,onderwijsgroepen,
  onderwijsmedewerkers}, learniq-basispoort-sync-learner, learniq-entree-content-sync-learner,
  learniq-migration-import-{parnassys,esis,magister,somtoday}. Vocabulary rows:
  learniq-exchange-rejection-status, learniq-exchange-error-codes-{bron-rod,oso,leerplicht,integriq}.
- Verification: openspec validate --strict valid. check:strict run 1 exit 1 (phpmd 2 findings in my event,
  fixed; PHPUnit 4195 green). Run 2 exit 1: all static checks green; PHPUnit 3 failures, all
  DSOSignatureVerifierServiceTest (known order-dependent flake, passes standalone 13/13, passed in run 1).
  npm lint 0, format 0, test:l10n 0. Hydra gates run 1 exit 1 (gate-54 missing $ref, fixed), run 2 exit 0
  (53/53 applicable).
- Lesson: OpenRegister's real findAll returns a flat list; integriq's test stub returns {results, total}.
  All my reads use `$matches['results'] ?? $matches` and the tests use flat lists.
- Left undone: import landing (records hand-back to the owning app), acknowledgement correlation
  (dispatch change D4), exchange.read defaults to admin (an admin grants learniq's coordinator groups).
- opsx-verify: pass (10/10 tasks, 9/9 requirements, contract matches); verdict added to PR body.

## 2026-09-28 close
- af5679933 re-verified: 68 exchange tests green. PR #2220 body updated with the follow-up commit and the learniq PR link (ConductionNL/learniq#1157).
- Lane r3-exchange done.

## 2026-09-28 coordinator follow-up (from landing lane head 42f63ed18)
- Three reds introduced by #2220: check:schema-l10n (47 strings), check:l10n-js (JS catalogues not rebuilt), vitest jobDraft (ExchangeJobAction not offered in the jobs form).
- Fixed in 0a4ae25f5: 48 keys in en.json/nl.json (47 schema strings + 'Run a data exchange'), npm run l10n:build, manifest jobClass enum + JobFormFields label.
- check:schema-l10n 0, check:l10n-js 0, test:l10n 0, lint 0, format 0, stylelint 0, vitest 22 files / 327 tests green.
- Full PHPUnit running under with-slot (log .tmp/phpunit-full.log).

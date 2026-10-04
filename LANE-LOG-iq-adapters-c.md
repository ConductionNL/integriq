# Lane log — iq-adapters-c

Lane dir: `/home/rubenlinde/memcap-work/lq-lanes/iq-adapters-c`
Source checkout: `apps-extra/openconnector` (local clone, GitHub bulk transfer throttled)
Remote: `https://github.com/ConductionNL/integriq.git`
App id (`appinfo/info.xml`): `integriq`
Branch cut for this lane: `feat/integriq-adapter-oso` (from `origin/development` at `36c56415`) — never advanced, see below.

## Change 1/1: integriq-adapter-oso — redirected to review, no build

Setup ran normally (`setup-lane.sh` cut `feat/integriq-adapter-oso` from `origin/development`).
Before writing any openspec artifacts, the coordinator sent a mid-task correction:
PR #2182 (`feat/integriq-adapter-oso`) already existed, opened by an earlier lane
(`iq-adapters-b`, per its own `LANE-LOG.md` history read from that branch) before the
coordinator's split reached this lane. Instructed to not build a second one; instead
check PR #2182 against the brief, run opsx-verify headless against it, and post a PR
comment with any gaps. Did not touch openspec, did not write code, did not commit or
push from this lane's own branch.

### What was checked

- `gh pr view 2182` / `gh pr diff 2182` — full PR body and diff read.
- Confirmed the PR's structural shape against the brief: `OsoKennisnetClient` (dormant,
  blocked on `PkiOverheidCredentialResolver` per M3c, same as the ROD/verzuimloket
  siblings), `LogOsoProvider` (mock), `OsoProviderInterface`/`OsoProviderRegistry`
  (abstract client), `OsoImportTranslator` + `OsoDossierReceivedEvent` (inbound source
  adapter, field names verified to match learniq's `OsoImportDossier` shape verbatim —
  `sourceSchoolBrin`, `learnerEckId`, `categories`, `draftProfile`, `attachmentRefs`),
  `OsoExportEnvelopeTranslator` (outbound target, REQ-006 pass-through), fixtures under
  `tests/fixtures/oso/*.xml` (read directly — synthetic BRIN/eckId/names, no live
  credentials, `grep` for api_key/secret/password/credential across the Oso service and
  fixture files found nothing).
- Read every `logger->warning`/`logger->error` call site in `OsoService.php` and
  `OsoController.php` directly (not grep-and-trust): none logs `learnerEckId` or
  `sourceSchoolBrin`; those fields only reach `saveObject()` (OpenRegister audit
  persistence) and the typed event. The brief's "never log pupil-identifying values"
  requirement holds.
- `gh pr checks 2182` — three CI jobs red on the current head commit (`a211e0a`, confirmed
  via `gh api repos/.../commits/feat/integriq-adapter-oso` matching `gh pr view
  --json headRefOid`, so not a stale run):
  - `quality / Frontend Check (check:schema-l10n)`: FAIL — "10 schema string(s) added
    with no catalogue key" against baseline 0. `l10n/en.json`/`l10n/nl.json` are not in
    the PR's file list — the new `oso_message` schema's titles/descriptions have no
    catalogue entries.
  - `quality / Hydra Gates`: FAIL — `gate-101 demo-data-coverage`: "1 schema(s) without
    valid demo data (ADR-111 rule 1)". `lib/Settings/integriq_seed_data.json` is not in
    the PR's file list. This is the "dormant seed rows" element the brief asked for and
    the PR does not ship it — the PR body's own local hydra-gates run only reports
    gate-53/18/19, not gate-101, so this looks like an environment gap between the local
    run and CI rather than an intentional skip.
  - `quality / Quality Report`: FAIL — this is the rollup job; its own log shows it
    correctly detecting that every enabled test-tier job (PHPUnit, Newman) ran and
    passed, so this red is downstream of the two failures above, not a third cause.
  - Also checked: `gate-53 effective-manifest-crossref`, which the PR body names as a
    pre-existing failure, ran and PASSED in this CI run (visible in the hydra-gates job's
    TIMING lines, not in the FAIL or NOT-APPLICABLE lists) — looks resolved on
    `development` since the branch was cut. Noted in the comment, not treated as a gap.
- Did not re-run `composer check:strict`/`npm run lint` locally against the PR branch —
  the live CI run against the exact head commit is stronger, current evidence than a
  fresh local run would add, and LANE-RULES step 6 says verify by exit code, which CI
  already gives directly.
- Fetched the branch and checked it out read-only (`git fetch origin
  feat/integriq-adapter-oso && git checkout --no-track -b review/oso
  origin/feat/integriq-adapter-oso`) to read files directly rather than trust `gh pr
  diff` truncation; deleted that branch afterward and returned to this lane's own
  `feat/integriq-adapter-oso` branch, which was never advanced or pushed.
- Checked existing PR comments before posting: an `opsx-verify` verdict from
  `rubenvdlinde` was already on the PR (green: 17/17 tasks, 6/6 requirements, no
  CRITICAL/WARNING/SUGGESTION). Per the coordinator's instruction ("post the verdict as
  a PR comment if none exists yet"), did not duplicate it — that verdict covers
  spec-to-code coverage, not CI gate results, and would not have caught either failure
  above since neither is a spec/task mismatch. Instead posted a new comment with the
  CI-sourced gap analysis above.

### Posted

PR comment: https://github.com/ConductionNL/integriq/pull/2182#issuecomment-5846451394
(gaps: missing seed rows for `oso_message` — gate-101; missing l10n catalogue entries
for 10 new schema strings — check:schema-l10n; note that gate-53 is no longer failing).
Did not push anything to `feat/integriq-adapter-oso`. Did not merge.

### Left for someone else

- `lib/Settings/integriq_seed_data.json` needs a dormant seed row for `oso_message`
  (same posture as whatever `rod_message`/`verzuim_message` ended up with once/if their
  own gate-101 findings are resolved — worth checking those two PRs for the same gap,
  since they were built by the same lane pattern and this gate may not have existed, or
  not applied, when they were verified).
- `l10n/en.json` (identity) and `l10n/nl.json` (translated) need catalogue keys for the
  10 new schema strings on `oso_message`, then `npm run l10n:build`.
- Whoever owns PR #2182 (lane `iq-adapters-b`, per its LANE-LOG) should pick these up
  and push a fix commit; this lane did not push to avoid two agents in one branch.

### Time spent

Short: setup + corpus read (~10 min) before the coordinator's redirect landed; review
of PR #2182 diff, logger call sites, fixtures, and CI job logs, plus the PR comment
(~20 min). No openspec artifacts were created by this lane.

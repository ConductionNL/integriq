# Discovery: environments-and-promotion

## Question
The context brief calls for "promote = export from environment A, import into
environment B" and "credential re-binding via the credential broker." Two
things needed verifying against HEAD before design could proceed: (1) does
OpenConnector have ANY existing outbound-call capability suitable for
reaching a *different* OpenConnector instance's API, or would promotion
require a brand-new HTTP client; and (2) does the existing export/import
pipeline already do anything with `credentialRef`-shaped values, or is
re-binding entirely new ground.

## Approach Taken
Read `lib/Service/ConfigurationService.php`, `lib/Service/
ConfigurationImportPreviewService.php`, `lib/Service/ConfigurationHandlers/
SourceHandler.php`, `lib/Service/Security/SensitiveFieldRegistry.php`,
`lib/Service/BrokeredCallService.php`, `lib/Service/CallService.php`
(`call()` signature), `lib/Controller/ConfigurationController.php`, and
`appinfo/routes.php` at HEAD. Cross-checked against the `configuration-export-import`
spec (status: done) and the `source-broker-credentials` change referenced
from `BrokeredCallService`'s docblocks.

## Findings
- **Import/preview endpoints already exist and are routed**: `POST
  /api/configurations/import/preview` (REQ-007) and `POST
  /api/configurations/import` (REQ-008) are live, gated by
  `ActionAuthService::requireAction()` with `configuration.import`. A remote
  instance can already be pushed a configuration document by any caller that
  can authenticate to it — promotion does not need a new import/diff
  algorithm, only a way to REACH that endpoint on the target.
- **`CallService::call(ObjectEntity $source, ...)` is the only outbound HTTP
  path in the app**, and it is hard-bound to a `source`-schema `ObjectEntity`
  (`$sourceData = $source->getObject()`, reads `location`, `configuration`,
  drives CallLog/retry/rate-limit/redaction). There is no generic
  "make an authenticated HTTP call" utility outside the Source abstraction.
  `BrokeredCallService` layers `credentialRef` proxying and app-side
  injection on top of exactly this same Source-shaped configuration
  (`configuration.authentication.credentialRef`).
- **Consequence**: the cheapest, most reuse-faithful way to model "reach
  environment B's API" is to make each `environment` object point at an
  ordinary `source`-schema object (`type: "api"`, `location` = the target's
  base URL, `configuration.authentication.credentialRef` = the credential
  used to authenticate to it). Dispatching a promotion call then becomes a
  normal `CallService::call()` invocation — CallLog auditing, retry,
  rate-limiting, and `BrokeredCallService`'s broker-backed credential
  resolution all apply with zero new code. Inventing a parallel
  "EnvironmentClientService" with its own Guzzle client would duplicate all
  of that.
- **`credentialRef` is NOT touched by the existing export/import pipeline**:
  grepped `lib/Service/ConfigurationHandlers/*.php` and `SensitiveFieldRegistry.php`
  for `credentialRef`/`credentialId`/`credentialName` — zero matches.
  `SensitiveFieldRegistry::SECRET_NAME_PATTERN` does not match `credentialId`
  or `credentialName` as key names (no substring overlap with `token|key|
  secret|password|...|auth|...`), so a Source's `credentialRef` placeholder
  survives export completely unredacted and unresolved — it is exported
  verbatim as `{"credentialRef": {"credentialId": "<source-env-uuid>"}}` (or
  `credentialName`). REQ-004's id↔slug translation also does not touch it
  (its vocabulary is `targetId`/`sourceId`/`inputMapping`/`outputMapping`/
  `rules[]`/nested `<type>Id` keys — not `authentication`). This means a
  naive promotion today would carry a source-environment-specific
  credential UUID straight into the target environment, where it almost
  certainly does not resolve to any credential (broker credentials are
  per-instance/per-owner) — silently breaking the Source on first use rather
  than failing loudly at promotion time.
- **REQ-009's `credentialsNeedingReentry` is a different, narrower thing**:
  it flags the top-level fields `SourceHandler::export()` strips outright
  (`apikey`, `secret`, `username`, `password`, `jwt`, ...) — it says nothing
  about a `credentialRef` placeholder, because that field isn't stripped at
  all (it's not in `SourceHandler`'s `unset()` list and doesn't match
  `SensitiveFieldRegistry`). This change needs its own,
  additional classification bucket for `credentialRef` re-binding; it cannot
  reuse REQ-009's bucket as-is.

## Recommendation
Model `environment` as OR-object metadata that WRAPS an existing `source`
object rather than inventing new connectivity plumbing, and reuse
`CallService::call()` (with its existing `BrokeredCallService` credentialRef
proxy path) to dispatch promotion's remote preview/import calls. Reuse the
target's existing `/api/configurations/import/preview` and `/api/
configurations/import` endpoints unchanged. Add a dedicated,
promotion-specific `credentialRefsNeedingRebind` preview bucket — computed
client-side in the new `PromotionService` by scanning the exported document
for `credentialRef` placeholders (same detection logic as
`BrokeredCallService::containsPlaceholder()`/`isPlaceholder()`, applied to
already-exported JSON rather than a live source object) — since neither
REQ-004's translation nor REQ-009's reentry flag covers this case.

## Risks Uncovered
- If the target environment's `source`-schema object (the one an
  `environment` wraps) is itself misconfigured (wrong `location`, expired
  credentialRef), promotion calls fail the same way any broken Source call
  fails today (synthetic 409/403/502 CallLog) — acceptable and already
  well-tested behaviour, not a new failure mode to design around.
- `CallService::call()` persists a `CallLog` for every promotion dispatch.
  This is desirable (existing infra for free) but means promotion preview
  and import calls are visible in the Logs UI as regular Source calls, not
  labelled as "promotion" calls unless the `promotion_audit` object
  cross-references the CallLog id — design.md should decide whether to
  store that cross-reference.

## Next Steps
Proceed to design.md and specs with the environment-wraps-a-Source model and
the promotion-specific credentialRef rebind bucket as settled decisions.

# Tasks — english-vocabulary (openconnector)

Scan: **7 schemas / 15 Dutch properties**, **12 files / 12 classes / 45 methods**.
Most of it is adapter layer and stays. The work is mainly *classification*, not renaming.

## 1. Classify before renaming

- [ ] 1.1 Mark the five wire schemas (`kiss_klantcontact`, `stuf_message`,
      `iwmo_ijw_message`, `dso_verzoek`, `notificaties_abonnement`) with an explicit
      marker naming the standard each mirrors, so the classification travels with the
      schema and a later sweep does not "fix" them.
- [ ] 1.2 Classify each of the 45 Dutch method names and 12 class names as
      protocol-facing (keep) or ours (rename). One at a time — a bulk rename here
      misnames adapters after concepts they do not handle. This is the bulk of the change.
- [ ] 1.3 Resolve `event_subscription.action.kenmerken`: read its producer and consumer
      to determine whether it carries a ZGW Notificaties payload (wire, keep) or is a
      CloudEvents record that borrowed the word (ours, rename to `characteristics`).
      Its `title` hints at ours; the payload decides.

## 2. Rename what is ours (app-local, no coordination)

- [ ] 2.1 `ris_sync_record.risVergaderingId` → `risMeetingId`.
- [ ] 2.2 `ris_sync_record.besluitStatus` → `decisionStatus`, keeping the enum values
      `aangenomen` / `verworpen` / `aangehouden` / `doorgeschoven` — those are what iBabs
      sends, and `Mapping-iBabs Besluit to Decision-v0.0.1.json` compares against them.
- [ ] 2.3 Rename the method and class names classified as ours in 1.2.
- [ ] 2.4 Update every read site, including `IBabsConnectorService.php` around line 533
      and the mapping configurations under `configurations/decidesk-ris-import/`.

## 3. Hold the cross-app key

- [ ] 3.1 Do **not** rename `ris_sync_record.zaakId` in this change. Record it as blocked
      on procest, which owns `Case`. docudesk holds the same FK and moves in the same
      window.

## 4. Translations

- [ ] 4.1 Add Dutch translations for the renamed properties to `l10n/nl.json`,
      re-pointing existing keys rather than re-extracting; run `check-l10n`.

## 5. Verify

- [ ] 5.1 Re-run the token-aware scan; the residual Dutch SHALL be exactly the marked
      wire schemas plus the held `zaakId`, and nothing else.
- [ ] 5.2 Exercise one iBabs sync end to end and confirm a `decisionStatus` value still
      maps to the correct `Decision.outcome` — an adapter whose field stopped matching
      keeps running and produces empty output, so a green suite is not evidence here.
- [ ] 5.3 Full test suite plus hydra gates 46 / 53 / 54 / 55 / 57 / 61.

## Acceptance criteria

- Every wire schema carries a marker naming the standard it mirrors.
- All 45 method names and 12 class names are individually classified, with the
  protocol-facing ones listed and justified rather than silently skipped.
- `decisionStatus` retains its Dutch enum values and the iBabs mapping still matches.
- `zaakId` is unchanged and its block on procest is recorded.
- A live iBabs sync produces the same `Decision.outcome` as before the rename.

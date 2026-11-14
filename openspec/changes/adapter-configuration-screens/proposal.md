# Proposal: adapter-configuration-screens

## Summary

Give the adapter subsystems the **configuration** half of their surface. The evidence half landed in `feat/protocol-evidence-surfaces`: eight message schemas now have log pages carded on the Reports hub, so a failed StUF exchange or Peppol transmission is findable. What is still missing is everything an administrator has to *set up* before any of that traffic exists. Registering an LTI platform, approving it, suspending it, rotating its key: eleven schemas across six subsystems are configured today by curl or not at all.

Verified on `development`: eleven schemas are written by `lib/` and referenced by **zero** files under `src/`: `lti_platform`, `lti_tool`, `lti_deployment`, `lti_identity_link`, `eudi_credential_offer`, `eudi_issuance_session`, `eudi_status_list`, `fsc_service`, `bankfeed_connection`, `cardfeed_account`, `openformulieren_form_mapping`. LTI alone carries 13 routes, EUDI 10, and `lib/Settings/register.d/` ships four LTI lockdown and write-only-secret fragments for records no screen can create.

## Motivation

- **A machine-facing endpoint is not an excuse for a machine-only lifecycle.** An LTI launch is genuinely called by the LMS, not by a person, and needs no UI. But `lti#approve`, `lti#suspend`, `lti#generateKey` and `lti#rotateKey` are administrative decisions with an audit consequence. They have routes and no screen, so the decision is made with a shell and recorded nowhere a colleague can read.
- **The secrets machinery already assumes a UI that does not exist.** `99-lti-platform-secrets-writeonly.json` and `99-lti-tool-secrets-writeonly.json` mark fields write-only so a form can accept them without ever reading them back. That is the shape of a configuration screen. Nothing renders it.
- **Key rotation without a surface is key rotation that does not happen.** `generateKey` and `rotateKey` exist and are unreachable, so the practical rotation interval for every LTI registration is "never".
- **The evidence pages make the gap sharper, not smaller.** An operator can now see that an iWmo message failed. They still cannot see, or fix, the connection it failed on.

## Affected Projects

- [x] Project: `integriq`, in `src/manifest.json` (index + detail pages per subsystem), `src/icons.js`, `l10n/nl.json`, `tests/e2e/`.

## Scope

### In Scope

- **LTI** (highest value, most routes): index + detail for `lti_platform` and `lti_tool`, with the lifecycle actions (`approve`, `suspend`, `generateKey`, `rotateKey`) as row and header actions rather than free-floating buttons.
- **EUDI wallet**: index + detail for `eudi_status_list`, and a read surface for `eudi_issuance_session` and `eudi_credential_offer`.
- **The four single-record configurations**: `fsc_service`, `bankfeed_connection`, `cardfeed_account`, `openformulieren_form_mapping`. These are ordinary manifest index/detail pages over a register schema and cost little beyond their columns.
- Where a page is manifest-driven, it stays manifest-driven. A custom component is a last resort, and each one carries a `_note` saying what the declarative path could not express.

### Out of Scope

- `lti_deployment` and `lti_identity_link`. Both are written by the launch flow rather than configured, so they belong with the evidence surfaces, not here. Recorded so the next reader does not read their absence as an oversight.
- Any change to the protocol endpoints themselves. This change adds screens over records that already exist; it does not touch a single adapter.
- The remaining `openspec/changes/` backlog. `leaf-integrations` (0/17) and `hermiq-ai-tooling` (0/22) are separate and untouched.

## Open Questions

1. **Do the LTI lifecycle actions need a confirmation step?** `suspend` cuts off a live LMS integration and `rotateKey` invalidates the key the LMS holds. Both look like they warrant a confirm dialog naming the consequence, but the fleet's convention for destructive-but-reversible actions should decide it rather than this change.
2. **Does `eudi_status_list` belong to an administrator or to the issuer service?** It has an admin key surface (`eudiIssuerKeyAdmin`, 3 routes), which suggests administrator, but the status list itself may be machinery. Worth answering before building a page for it.
3. **Where do six new index pages go?** The nav has nine top-level entries and ADR-097 keeps it that way. A "Protocol configuration" group under Connections is one answer; a settings-section surface is another. The evidence half solved the same problem with Reports cards, and configuration cannot use that mechanism because a card hub is for reading.

## Answers (2026-11-13)

1. **Confirmation.** `suspend` and `rotateKey` confirm; `approve` and `generateKey` do not. Integriq already confirms an action that cuts something off and can be undone with work: the dead-letter bulk discard (`src/components/DeadLetterBulkBar.vue`) and the forced replay on the trace page (a separate confirmation step after the dry run). Suspending a platform stops a live LMS integration until someone reinstates it, and rotating a key invalidates the key the LMS holds until the LMS is given the new one. Both dialogs name that consequence and the platform it hits, not "are you sure". Approving and generating a first key add something and cut nothing off, so they run at once.
2. **`eudi_status_list` is machinery.** Nobody authors a row: issuance allocates its indices, revocation flips its bits, and `EudiStatusListRefreshJob` re-signs its token before it expires. An administrator's only lever is the issuer key, which has its own admin routes. Task 5 is closed with this reason and no page is built. `eudi_issuance_session` and `eudi_credential_offer` are not surfaced by this change either: neither is a record an administrator authors.
3. **Where the pages go.** ADR-097 keeps the nine top-level entries, so no group is added. `src/menu-layout.json` groups the nav by data direction, and each page goes in the group of its direction. FSC services, bank feeds and card feeds are systems this instance calls, so they go under Connections (outbound). LTI platforms, LTI tools and Open Formulieren forms are systems that call or post into this instance, so they go under Gateway (inbound). A settings-section surface was rejected: ADR-079 keeps app configuration in Nextcloud's admin settings and the in-app gear foldout per-user only. A card hub was rejected because, as this proposal says, a card hub is for reading.


---
kind: code
depends_on: []
---

# Proposal: sources-per-user-oauth

## Summary

Every source in integriq calls out with one shared credential. An app built in buildiq that shows a user's own calendar, mailbox or files at an outside service therefore has to use an organisation-wide account, which sees too much and logs nothing per person. This change lets a source call with the signed-in user's own OAuth 2.0 token: the user connects their account once, the credential broker keeps and refreshes the token, and each call runs as that user.

## Why

Row `buildiq:int-per-user-oauth` (rated no, built none) from buildiq's capability matrix, owned by integriq, decided `build` in the OpenSpec pass of 2026-09-27: a featureRequest demand row plus two competitors yes. Integriq owns the source credential, so the per-user token is integriq's half.

- featureRequest https://github.com/appsmithorg/appsmith/issues/3313 (Appsmith, open).
- Mendix https://docs.mendix.com/appstore/modules/oidc/ "API consumption: when the app calls other APIs" with the user's own token.
- Power Apps https://learn.microsoft.com/en-us/power-apps/maker/canvas-apps/add-manage-connections, connections made per user.

The buildiq matrix evidence: "Connections use shared credentials kept in the vault; no per-user sign-in for a connector" in the page editor.

## What integriq already has

- `BrokeredCallService` (`lib/Service/BrokeredCallService.php`) resolves a source's `credentialRef` and dispatches through OpenRegister's broker (`prepare()`, `:442`; `dispatch()`, `:494`), with an acting user for sessionless calls (`resolveActingUser()`, `:348`, `:460`).
- OpenRegister's broker, per ADR-064 decision 8 (amended 2026-09-04): an `oauth2-token-set` credential kind the broker refreshes and proxies, a broker-side connect flow with PKCE and a relay callback, and `personal` scope for a person's own account (decision 4). Consuming apps never see the token.

## What this change builds

1. A per-user credential mode on a source: `credentialRef` names an OAuth 2.0 provider and `perUser: true` instead of one credential id.
2. At call time the broker is asked for the calling user's own `personal` token set for that provider, and the call goes through the broker's proxy as that user.
3. A user without a connected account gets a 401 problem document with a link to the broker's connect flow; after connecting, the same call works.
4. A page where a user sees which accounts they connected for which sources, and disconnects one.

## Out of scope

- The connect flow and token refresh themselves. They are OpenRegister's broker (ADR-064 decision 8).
- Per-user credentials for background synchronizations and jobs. They have no signed-in user; they keep organisation credentials.
- buildiq showing the connect prompt on a built page. That is buildiq's half, named in the hand-back.

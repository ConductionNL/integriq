# http-call-engine Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- sources-per-user-oauth

## Purpose

A source calls an outside API with the signed-in user's own OAuth 2.0 account, held and refreshed by the credential broker. Row `buildiq:int-per-user-oauth` from buildiq's matrix.

## ADDED Requirements

### Requirement: A source can call with the signed-in user's own account (REQ-PUOA-001)

Integriq MUST let a source reference an OAuth 2.0 provider per user instead of one shared credential. For such a source it MUST use the calling user's own `personal` token set from the credential broker, through the broker's proxy, and MUST NOT fall back to any other credential. A call without a signed-in user MUST be refused with the reason.

#### Scenario: two colleagues each see their own calendar
- GIVEN a source on a calendar API set to each user's own account, and two users who connected their accounts
- WHEN each opens a buildiq page that calls the endpoint `me/calendar`
- THEN each sees their own events, and the call log names the user each call ran as
- @e2e exclude cross-app flow; covered by PHPUnit with a fake broker and a Playwright run against a mock provider

### Requirement: A user without a connected account is sent to connect it (REQ-PUOA-002)

When a user calls through a per-user source without a connected account, integriq MUST answer 401 with a problem document that names the provider and gives a link to the credential broker's connect flow.

#### Scenario: a first call leads to the connect screen
- GIVEN a user who never connected their account
- WHEN they call the endpoint `me/calendar`
- THEN the answer is 401 with type `connect-account-required` and a `connectUrl`, and after connecting the same call succeeds
- @e2e exclude covered by Newman and the Playwright run in task 3

### Requirement: A user sees and disconnects their own accounts (REQ-PUOA-003)

Integriq MUST show each user, in their personal settings, the accounts they connected for integriq sources with the account identity and the last use, and MUST let them disconnect one, which revokes it in the credential broker.

#### Scenario: a user disconnects an account
- GIVEN a user with a connected Microsoft account
- WHEN they open personal settings and disconnect it
- THEN it is gone from the list, and their next call gets the connect prompt
- e2e: `tests/e2e/personal-connected-accounts.spec.ts`

# Design: sources-per-user-oauth

Kind: code. Size M. `BrokeredCallService`, the source schema, `EndpointService`'s error path, and one personal settings page.

## Context at development 92f282bc

- `BrokeredCallService::prepare()` (`lib/Service/BrokeredCallService.php:442`) resolves one credential id and an acting user; `dispatch()` (`:494`) calls the broker, passing `actingUserId` for sessionless calls (`:514`).
- A source's credential lives under `configuration.authentication` as `{credentialRef: {...}}` (`hasCredentialRef()`, `:173`).
- ADR-064 decision 8: `oauth2-token-set` credentials, refreshed by the broker, reached only through `request()`; a broker connect flow (authenticated start, public throttled callback); `personal` scope for a person's own account.

## D1. Per-user is a property of the reference

`credentialRef` gains `{ provider: "<provider slug>", perUser: true }` as an alternative to `{ id: "<credential uuid>" }`. The source page offers "each user's own account" next to "one shared credential" when the chosen provider is an OAuth 2.0 provider in the broker's catalogue.

## D2. Resolving at call time

For a `perUser` source, `prepare()` looks up, through the broker, the credential of kind `oauth2-token-set`, scope `personal`, owner the current session user, provider the configured one. There is no fallback to a shared credential: a silent fallback would make a user see data through someone else's account. The call goes through `request()` as that user, so the token never enters integriq.

A sessionless call (cron, a job, a synchronization) on a `perUser` source is refused with a clear message, because there is no user whose account to use.

## D3. Not connected yet

When the user has no token set, `EndpointService` answers 401 with a problem document (`gateway-response-cache-and-problem-errors`) of type `connect-account-required`, with the provider's name and `connectUrl` pointing at the broker's connect start with a return URL. A built page or any client can send the user there. After the callback the broker holds the token set and the retry succeeds.

## D4. The user's own overview

A personal settings section "Connected accounts for integriq" lists the user's token sets used by integriq sources (provider, account identity, connected on, last used) with a disconnect action that revokes the credential in the broker. It reads through the broker's own listing and shows only the user's own entries.

## Declarative versus imperative

No lifecycle or notification behaviour. Credential resolution is authorization logic in `BrokeredCallService`.

## Seed data

A dormant example source `example-graph-calendar` pointing at `https://graph.microsoft.com/v1.0` with `credentialRef` `{ provider: "microsoft-entra", perUser: true }` and an endpoint `me/calendar` that proxies `/me/events`.

## Risks

- A provider slug missing from the broker catalogue. Mitigation: the source page only offers providers the broker lists, and a missing one blocks saving.
- A user connects the wrong account. Mitigation: the overview shows the account identity the provider returned.


# Proposal: sources-declared-basic-and-apikey-auth

## Summary

The source form asks how to log in (`auth`: apikey, basic, oauth, jwt, none) and takes a username, a password, an API key and the header to send it in. Nothing reads those fields when integriq calls the source, so a source set up only through them calls out without credentials. This change makes the call engine apply the declared API key and Basic login.

## Why

Matrix row `integriq:src-auth-basic`, "Log in to a source with an API key or a username and password", rated `partial`, state `building` with no change for the missing half. Decided `build` in the build-all pass of 29 Sep 2026: five competitors rate it `yes` and the row is in the core area (`sources`).

Competitor cells from the matrix:

- n8n `yes`: "packages/nodes-base/credentials/HttpBasicAuth.credentials.ts, HttpHeaderAuth.credentials.ts, HttpQueryAuth.credentials.ts and HttpDigestAuth.credentials.ts define basic, header (API key), query and digest auth".
- tyk `yes`: "apidef/oas/upstream.go:1074 upstreamAuth.basicAuth with username and password, applied per call".
- mulesoft `yes`: "http-authentication lists Basic, Digest, NTLM and OAuth2 authentication for the HTTP request configuration".
- wso2 and frank `yes` (see the matrix row).

## What integriq already has

- `lib/Settings/integriq_register.json` declares `source.auth`, `authorizationHeader`, `username`, `password` and `apikey`; the secrets are `writeOnly` (`99-source-secrets-writeonly.json`).
- `CallService` renders `configuration` and passes a `configuration.auth` tuple to Guzzle, and redacts it in the call log (`CallService.php` around :1456).
- An API key works today only by hand-writing `{{ source.apikey }}` into the free-form headers, and Basic has no working path at all: the call Twig sandbox has no base64 filter.

## What this change builds

The call engine reads the declared strategy: `basic` sends the username and password as Guzzle's `auth` tuple, `apikey` sends the key in the declared header (`Authorization` when none is named). A `configuration.auth` or an explicit header the operator wrote still wins, so no working source changes behaviour. A source on the credential broker (`configuration.authentication.credentialRef`) is untouched.

## Out of scope

OAuth and JWT, which already run through the authentication Twig functions. Moving inline secrets into the broker is `migrate-inline-secrets-to-broker`.

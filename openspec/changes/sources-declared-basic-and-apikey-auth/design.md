# Design: sources-declared-basic-and-apikey-auth

## Context

`CallService::call()` merges the source's `configuration` into the Guzzle options (`mergeSourceConfiguration()`), renders it through the sandboxed Twig, drops keys containing `authentication`, and sends. The top-level source fields `auth`, `username`, `password`, `apikey` and `authorizationHeader` are never read (35 `$sourceData[...]` reads, none of them these).

## D1. Apply the declared strategy after the configuration merge

A small resolver, `SourceAuthApplier::apply(array $sourceData, array $config): array`, runs after the merge and before the render. For `auth: basic` with a username it sets `$config['auth'] = [username, password]`. For `auth: apikey` with a key it sets `$config['headers'][authorizationHeader ?: 'Authorization'] = apikey`. Every other strategy returns the config unchanged.

## D2. What the operator wrote wins

When `configuration.auth` is set, or the header the key would go in is already present, the applier changes nothing. A source that works today through a hand-written header keeps working byte for byte.

## D3. Redaction stays where it is

The call log already replaces `auth` and secret-looking headers with a placeholder. The applied header uses the declared name, so a custom name like `X-Api-Key` is covered by the existing secret-looking header rule; the design adds the declared header name to that rule so a neutral name is redacted too.

## D4. Broker sources are left alone

A source with `configuration.authentication.credentialRef` is called through the broker, which injects its own credential. The applier returns early for it, so a stale inline field can never override the broker.

# Consumer tokens: OpenID Connect, client credentials and scopes

A consumer is a system that calls integriq's endpoints. This page covers three ways it can prove who it is with a token, how a client finds out where to get one, and how scopes limit what a consumer may do.

## Tokens from the consumer's own identity provider

Set the consumer's authorization type to JWT and choose where its signing keys come from:

- **Static key**: the public key is pasted on the consumer, as before. The token's `iss` must be the consumer's name.
- **Key set (JWKS)**: enter the address where the consumer publishes its keys. Integriq caches the key set. When a token names a key id it does not know, integriq fetches the set again, at most once per interval, so a consumer can rotate its key without telling you.
- **OpenID Connect**: enter the provider's issuer address, for example a Keycloak realm (`https://login.example.nl/realms/gemeente`), and the audience the tokens must carry (for example `integriq`). Integriq reads the provider's discovery document to find the key set and the exact issuer. A token whose issuer or audience does not match, or that lacks a required claim, is refused.

For a key set or OpenID Connect, only RS and PS algorithms are accepted. An HMAC token (`HS256` and the like) or an unsigned token is always refused, because a published key set is public.

## Tokens from integriq: client credentials

A partner system can also get its tokens from integriq itself.

1. An administrator creates integriq's signing key once: `POST /index.php/apps/integriq/api/oauth/issuer-key`. The private key is stored in OpenRegister's credential broker and never on an object. The public key is published at `/index.php/apps/integriq/.well-known/jwks.json`.
2. Create a consumer with authorization type `client_credentials` and give it the scopes it may use.
3. Set its client secret: `POST /index.php/apps/integriq/api/consumers/{id}/client-secret`. The answer shows the client id (the consumer's id) and the secret **once**. Only a hash of the secret is stored, so a lost secret cannot be shown again; set a new one instead.
4. The partner asks for a token:

   ```
   POST /index.php/apps/integriq/api/oauth/token
   Authorization: Basic base64(client_id:client_secret)
   Content-Type: application/x-www-form-urlencoded

   grant_type=client_credentials&scope=zaken:read
   ```

   The answer is a bearer token valid for five minutes. It carries the consumer as `sub` and the granted scopes as `scope`. A partner may ask for fewer scopes than it holds, never for more.

Integriq accepts its own tokens on any endpoint that requires a JWT.

## How a client finds the token endpoint

An endpoint protected by a JWT rule publishes where to get a token, following RFC 9728:

```
GET /index.php/apps/integriq/.well-known/oauth-protected-resource/<endpoint path>
```

The document names the resource, integriq as the authorization server, and the scopes the endpoint uses. A call to such an endpoint without a token gets `401` with a `WWW-Authenticate: Bearer resource_metadata="…"` header pointing at that document. An endpoint that is not token-protected has no document (404).

## Scopes

A consumer holds a list of scopes, for example `zaken:read`. An endpoint's authentication rule can require scopes, either for every method or per method:

```json
{ "type": "jwt", "requiredScopes": { "GET": ["zaken:read"], "POST": ["zaken:write"] } }
```

After the caller is authenticated, integriq checks the scopes: the token's `scope` claim for a JWT caller, the consumer's stored scopes for an API key or Basic caller. When one is missing, the answer is `403` naming the missing scope. An endpoint without `requiredScopes` behaves as before, so adding scopes to consumers changes nothing until an endpoint asks for them.

## Example consumers

A fresh install includes two examples:

- **Example OpenID Connect consumer**: key source OpenID Connect, with the example issuer `https://login.example.nl/realms/gemeente` and audience `integriq`. No token validates until you enter a real issuer.
- **Example client credentials consumer**: scope `zaken:read` and no client secret, so it cannot get a token until you set one.

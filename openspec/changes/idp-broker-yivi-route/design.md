# Design: idp-broker-yivi-route

No board. Portaliq's design for `resident-identity-in-forms` puts Yivi as one more button on the sign-in choice ("Inloggen met Yivi", "Deel alleen de gegevens die dit formulier nodig heeft.") until a board exists; Yivi is on the missing-boards list of canvas `5NkFW28vZUUij43xzxHg5a`. Integriq's own screen is the *Beheer > Authenticatie* section of `portal-idp-broker-config`.

## D1. Yivi is an OIDC registration

Yivi is reached through an OIDC bridge (the Yivi project's `irma-oidc` style bridge, or a broker that offers Yivi over OIDC). Integriq stays vendor neutral as decision 94 D1 asks: the bridge is an `issuer` URL. A `yivi` registration with kind `saml-sp` is refused on save.

## D2. Registration properties

| Property | Type | Notes |
| --- | --- | --- |
| `provider` | enum gains `yivi` | |
| `yiviAttributes` | array | `{ id, claim, label, carriesBsn }`. `id` is the Yivi attribute id, `claim` the claim name the bridge returns. |
| `yiviTrust` | enum `low`, `substantial` | Default `low`. Only an administrator sets it, with a warning that it is the organisation's own judgement of the attributes. |
| `yiviBsnToConsumer` | boolean | Default `false`: a disclosed BSN is pseudonymised. |

## D3. The start

`/api/idp/yivi/start` takes the parameters of REQ-IDP-001 plus `attributes` (comma-separated ids). Integriq refuses on its own error page, redirecting nowhere, when the list is empty or holds an id outside `yiviAttributes`. The signed state records the list. The authorization request carries `claims={"id_token":{...}}` with one essential claim per requested attribute.

## D4. The callback and the envelope

The ID token is verified as for any `oidc-rp` registration. Integriq reads only the claims of the attributes in the signed state and refuses the login when an essential one is missing ("U heeft niet alle gevraagde gegevens gedeeld"). The envelope:

```json
{ "provider": "yivi", "subType": "attributes", "sub": "<HMAC of the disclosure's stable attributes>",
  "trust": "low", "organisation": "gemeente-x", "audience": "portaliq",
  "attributes": { "pbdf.gemeente.personalData.fullname": "Sanne de Vries", "pbdf.sidn-pbdf.email.email": "sanne@example.nl" } }
```

`sub` is an HMAC over the requested attribute ids and values with the registration's salt, so the same disclosure gives the same subject and nothing more. A disclosed BSN attribute is replaced by its pseudonym unless `yiviBsnToConsumer` is true.

## D5. The floor

`TrustLevelMapper` applies the substantial floor of REQ-IDPC-006 to `digid`, `eherkenning` and `eidas`. For `yivi` it takes `yiviTrust`. This is the reading the proposal asks Ruben to confirm. If he keeps the floor for Yivi, a `yivi` registration with `yiviTrust: low` is never ready (REQ-IDPC-007) and the screen says why.

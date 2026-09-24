# Tasks: idp-broker-envelope-runtime

Kind: code. Size L. Parity ledger row 12.8. The spec
`openspec/specs/digid-eherkenning-auth-adapter/spec.md` is already written and
already archived; this is its first implementation. Everything here is inert
until the feature flag is flipped and a live adapter is bound.

## Implementation tasks

### Task 1: The trust table
- **spec_ref**: `openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-trust-levels-map-to-the-eidas-aligned-vocabulary`
- **files**: `lib/Auth/Idp/TrustLevelMapper.php`, `lib/Exception/IdpAssertionException.php`
- [x] Implement (the table, the eHerkenning and eIDAS URNs, tenant aliases,
      fail-closed on anything else)
- [x] Test (EH3 to substantial, Hoog to high, an unknown level refused, an
      unknown provider refused, an alias that maps, `satisfies()` ranking an
      unknown level below low)

### Task 2: The pseudonym
- **spec_ref**: `openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-bsn-pseudonymisation-at-the-broker-edge`
- **files**: `lib/Auth/Idp/SubjectPseudonymService.php`
- [x] Implement (polymorphic passthrough, salted HMAC with the organisation in
      the signed message, a minimum salt length)
- [x] Test (same subject and organisation stable across calls, same subject
      different organisation different pseudonym, the same salt reused across
      two organisations still yielding different pseudonyms, a short salt
      refused, the BSN absent from the result)

### Task 3: The envelope
- **spec_ref**: `openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-one-time-signed-subject-envelope-handoff`
- **files**: `lib/Auth/Idp/SubjectEnvelope.php`, `lib/Auth/Idp/SubjectEnvelopeService.php`
- [x] Implement (the claim set, HS256, `use`, `iss`, `jti`, `exp` capped at
      `iat` + 60, verification checking every one of them)
- [x] Test (a round trip, an expired envelope refused, a stretched `exp`
      refused, a wrong audience refused, a token missing `use` refused, a
      short signing key refused, a forged signature refused)

### Task 4: Single use
- **spec_ref**: `openspec/changes/idp-broker-envelope-runtime/specs/digid-eherkenning-auth-adapter/spec.md#requirement-single-use-artefacts-refuse-rather-than-degrade`
- **files**: `lib/Auth/Idp/EnvelopeReplayGuard.php`, `lib/Auth/Idp/EnvelopeCodeStore.php`
- [x] Implement (refuse without a shared atomic cache, `add()` for the replay
      guard, `cad()` for the code store, keys hashed)
- [x] Test (a second burn refused, a second redemption refused, another
      consumer's code refused, no shared cache refusing both issue and
      redeem)

### Task 5: The assertion guard
- **spec_ref**: `openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-replay-audience-confusion-and-idp-initiated-flows-are-rejected`
- **files**: `lib/Auth/Idp/AssertionGuard.php`
- [x] Implement (solicited, audience, window, replay; no clock skew)
- [x] Test (an unsolicited response refused, a mismatched `inResponseTo`
      refused, a wrong audience refused, a not-yet-valid and an expired
      assertion refused, a replay refused, a clean assertion accepted)

### Task 6: The exchange
- **spec_ref**: `openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-one-time-signed-subject-envelope-handoff`
- **files**: `lib/Auth/Idp/IdpBrokerConfig.php`, `lib/Auth/Idp/EnvelopeExchangeService.php`,
  `lib/Controller/IdpBrokerController.php`, `appinfo/routes.php`
- [x] Implement (per-consumer secret in constant time, one undifferentiated
      401, the flag and the signing key checked first)
- [x] Test (an issue and redeem round trip, a wrong secret refused, an unknown
      consumer refused, the flag off refusing everything, the controller
      answering 401 on every refusal and never naming which check failed)

### Task 7: The dormant seam
- **spec_ref**: `openspec/changes/idp-broker-envelope-runtime/specs/digid-eherkenning-auth-adapter/spec.md#requirement-dormant-seam-adapters-ship-config-flag-gated-and-inert`
- **files**: `lib/Auth/Idp/GovernmentIdpAdapterInterface.php`,
  `lib/Auth/Idp/LogGovernmentIdpAdapter.php`, `lib/AppInfo/Application.php`
- [x] Implement (log and throw, never authenticate, never log the payload)
- [x] Test (both methods throwing, `isConfigured()` false, the logged context
      carrying no part of the callback)

### Task 8: Say what an operator configures
- **spec_ref**: `openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-configuration-placement-follows-adr-017-rules-1-3-7`
- **files**: the broker documentation page
- [x] Implement (the five settings, the flip checklist, and what is not built
      yet)
- [x] Test (walked against the spec and the services' own tests, NOT against a
      live broker or a live tenant in this lane; the page says so rather than
      implying a walk that did not happen)

## Not done, and named as not done

- The SAML Service Provider and the OIDC Relying Party. Vendor work behind the
  adapter interface, blocked on Open Decision D1.
- The DigiD `AuthnContextClassRef` URNs, which are tenant configuration rather
  than a guess in code. See the proposal.
- The *Beheer > Authenticatie* screen. The settings are readable and settable
  with `occ config:app:set` today.
- Single logout, local or propagated.

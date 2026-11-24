# adapter-configuration Specification Delta: adapter-configuration-screens

New capability: which adapter configuration records an administrator can create, read and act on from the product rather than from a shell. This delta claims REQ-ACS-001..REQ-ACS-003.

The evidence half of this gap is already closed: eight adapter message schemas carry log pages carded on the Reports hub under "Protocol messages". This delta covers the records that have to exist before any of that traffic does.

## ADDED Requirements

### Requirement: Every configuration record an administrator owns has a screen (REQ-ACS-001)

Each schema an administrator authors SHALL be reachable from the product without a shell. Measured on `development` before this change, eleven schemas were written by `lib/` and referenced by zero files under `src/`: `lti_platform`, `lti_tool`, `lti_deployment`, `lti_identity_link`, `eudi_credential_offer`, `eudi_issuance_session`, `eudi_status_list`, `fsc_service`, `bankfeed_connection`, `cardfeed_account` and `openformulieren_form_mapping`.

Of those, `lti_platform`, `lti_tool`, `fsc_service`, `bankfeed_connection`, `cardfeed_account` and `openformulieren_form_mapping` are administrator-authored and SHALL each have a manifest-driven index. `lti_deployment` and `lti_identity_link` are written by the launch flow rather than configured and SHALL NOT gain a configuration screen. `eudi_issuance_session` and `eudi_credential_offer` are machine-written and, where surfaced at all, SHALL be read-only. `eudi_status_list` is decided by open question 2 in the proposal, and SHALL be resolved with a written reason before a page is built or refused.

Every column declared on such a page MUST name a property the schema declares. A field the schema does not carry renders blank rather than failing, so this is checked against the schema and not against the rendered page.

#### Scenario: no administrator-authored schema is invisible to the product

- GIVEN the repository at this change's completion
- WHEN each administrator-authored schema above is searched for across `src/`
- THEN each is named by at least one manifest page or component
- AND `lti_deployment`, `lti_identity_link`, `eudi_issuance_session` and `eudi_credential_offer` are absent by decision, each with a `_note` or spec line saying so
- @e2e exclude static repo-shape assertion: a grep over `src/` against the register, not a DOM behaviour

#### Scenario: a write-only secret is accepted and never shown

- GIVEN `register.d/99-lti-platform-secrets-writeonly.json` marks a field write-only
- WHEN the LTI platform detail form renders for a record that has that secret set
- THEN the field accepts a new value
- AND no request the page makes returns the stored value, and no rendered element contains it

### Requirement: An administrative action with a route has a control (REQ-ACS-002)

An action that changes a registration's lifecycle SHALL be invocable from the surface showing that registration. This covers `lti#approve`, `lti#suspend`, `lti#generateKey` and `lti#rotateKey`, which today have routes and no control, so the practical key-rotation interval for every LTI registration is "never".

Each action SHALL be attached to the record it acts on, as a row or header action, rather than offered as a free-standing button that takes an id. Where an action is destructive to a live integration (`suspend` cuts off an LMS, `rotateKey` invalidates the key the LMS holds), the confirmation behaviour SHALL follow the answer recorded for open question 1, and any confirmation SHALL name the consequence rather than asking for generic assent.

A failed action SHALL report what failed. A control that swallows an error is worse than no control, because it reports success for work that did not happen.

#### Scenario: a key can be rotated by the person responsible for it

- GIVEN an approved LTI platform registration
- WHEN the administrator invokes rotate key from that registration's surface
- THEN the new key is generated, the registration shows it has been rotated
- AND the previous key is no longer accepted by the token endpoint

#### Scenario: a refused action says so

- GIVEN an LTI registration whose suspension is refused server-side
- WHEN the administrator invokes suspend
- THEN the surface reports the refusal and its reason
- AND the registration's state is unchanged, in the UI and in the register

### Requirement: A declared screen is proven to render (REQ-ACS-003)

Every page this change adds SHALL be listed in `MANIFEST_PAGES` in `tests/e2e/regression/manifest-pages.spec.ts` and SHALL be driven by an e2e that asserts the SPA mounted, the router matched, and no console error fired.

Declaration is not evidence. A widget declared in `config.widgets` with no matching entry in `config.layout` is absent from the DOM rather than empty, and the full gate suite passes over it, so each page's widgets SHALL be checked against its layout. An icon name not registered in `src/icons.js` renders no glyph rather than a fallback, so each icon SHALL be registered and SHALL resolve in `vue-material-design-icons`.

Every user-visible string the manifest adds SHALL have a key in `l10n/nl.json` whose value is not the English source repeated, because a locale value equal to its key renders correctly and is indistinguishable from finished work.

#### Scenario: a page that does not render fails the build

- GIVEN a page added by this change
- WHEN the e2e drives its route
- THEN `#app-content` mounts, the router reports a match, and the console carries no error
- AND removing the page's layout entries makes this scenario fail

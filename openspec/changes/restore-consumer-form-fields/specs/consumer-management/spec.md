# consumer-management — Consumers index create/edit form delta

**Spec refs**: `consumer-management`, `endpoint-runtime`

## MODIFIED Requirements

### REQ-CON-UI-001: Consumer Management UI

Integriq MUST provide a Consumers section in its SPA where administrators can browse,
create, edit, and delete consumer configurations.

The create/edit form SHALL expose every authorable consumer property — name, description,
allowed domains, allowed IPs, authorization type, authorization configuration, rate limit and
quota — in a declared order. Display order, labels and help text for the schema-reading
surfaces (the consumer detail page's data widget and detail grid) SHALL come from the
`consumer` schema, not from the page's manifest entry.

The two allowlist properties SHALL be authored as multi-value lists that persist as JSON
arrays of strings. The form MUST NOT persist an empty array for either one: an empty array is
a configured allowlist that admits nobody (`REQ-CON-SCOPE-001`), so emitting one where the
operator expressed no restriction would reject every inbound request. An allowlist left empty,
or emptied of its last entry, SHALL persist as absent — the unrestricted state.

Authorization type SHALL be presented as a select over the types the app offers, and a stored
value outside that set SHALL be displayed rather than blanked, so saving an unrelated field
cannot silently rewrite it. The type list SHALL NOT be declared as a schema `enum`, which is
enforced on save and would make existing rows unsaveable.

#### Scenario: consumers list page mounts and shows content

- GIVEN an authenticated admin visits the integriq app
- WHEN they navigate to the Consumers section via the sidebar nav or direct URL `/apps/integriq/consumers`
- THEN the Consumers index page renders inside the main content area with content visible

#### Scenario: add consumer button opens the creation modal

- GIVEN the Consumers index page is loaded
- WHEN the user clicks the "Add Item" button
- THEN a modal or dialog opens containing the consumer creation form

#### Scenario: every authorable property is on the form

- WHEN the user opens "+ Add" on the Consumers index
- THEN the form renders name, description, allowed domains, allowed IPs, authorization type,
  authorization configuration, rate limit and quota — in that order, taken from the schema's
  `order`, not alphabetically

#### Scenario: a consumer created without an allowlist is unrestricted

- GIVEN the create form, with both allowlist inputs left empty
- WHEN the operator saves
- THEN the persisted consumer has NO `domains` and NO `ips` value — not an empty array
- AND inbound calls from any source SHALL NOT be rejected on source-scope grounds
- @e2e exclude the assertion is on the persisted shape and on inbound enforcement — covered by vitest over the payload builder, and by PHPUnit for `isAllowed()`

#### Scenario: removing the last allowlist entry stops restricting rather than denying all

- GIVEN a consumer with one allowed domain
- WHEN the operator removes that entry and saves
- THEN the persisted `domains` is absent, so the consumer becomes unrestricted
- AND it does NOT become an empty allowlist that rejects every request
- @e2e exclude asserted on the persisted shape — covered by vitest

#### Scenario: allowlist entries persist as an array, not a joined string

- GIVEN the create form
- WHEN the operator enters two domains as separate entries and saves
- THEN `domains` persists as a two-element array of strings, which is the shape
  `ConsumerScopeService::isAllowed()` reads

#### Scenario: a legacy comma-joined allowlist is repaired on save

- GIVEN a consumer whose `domains` was written by the pre-cutover editor as the single string
  `"example.com, example.org"` — a value that matched nothing at run time
- WHEN the operator opens that consumer and saves it
- THEN `domains` persists as `["example.com", "example.org"]`
- @e2e exclude asserted on the persisted shape — covered by vitest

#### Scenario: the type select preserves an off-list value

- WHEN a consumer holds an authorization type the picker does not offer
- THEN the select displays that stored value instead of reading as unset, and saving the
  consumer does not silently replace it

### Requirement: Rate-limit and quota configuration UI (REQ-CON-RL-005)

The Consumer editor in the *Consumers* section MUST let an operator configure a consumer's
`rateLimit` (requests per window + window seconds) and `quota` (limit + period) alongside the
authentication configuration it already renders. Leaving the inputs empty MUST persist an
unlimited (absent) configuration.

Each block's two halves SHALL be authored as typed fields — two positive integers for
`rateLimit`, a positive integer and a period for `quota` — not as a raw JSON object. A block
with only one half supplied MUST persist as unlimited rather than as a partial block, since
neither half alone defines a limiter. Clearing a previously configured block MUST persist an
explicit null, which is what removes it on update.

#### Scenario: an operator sets a rate limit on a consumer

- **GIVEN** the Consumer editor for an existing consumer
- **WHEN** the operator enters a requests-per-window and window-seconds value and saves
- **THEN** the consumer's `rateLimit` SHALL be persisted and enforced on subsequent inbound calls

#### Scenario: a half-filled rate limit persists as unlimited

- GIVEN the Consumer editor with a requests-per-window value but no window-seconds value
- WHEN the operator saves
- THEN `rateLimit` SHALL persist as null, and no request SHALL be throttled on rate-limit grounds
- @e2e exclude asserted on the persisted shape — covered by vitest

#### Scenario: clearing a configured limiter removes it

- GIVEN a consumer with a configured `rateLimit`
- WHEN the operator empties both inputs and saves
- THEN `rateLimit` SHALL persist as null and the consumer SHALL become unlimited again
- @e2e exclude asserted on the persisted shape — covered by vitest

## ADDED Requirements

### Requirement: The consumer credential is write-only in the editor (REQ-CON-UI-002)

The Consumer editor MUST open the credential field empty on edit and MUST NOT treat that empty
field as an instruction to clear the stored credential.

`authorizationConfiguration` is declared `writeOnly: true`, so OpenRegister strips it from every
API response — admin included — and the editor has no value to pre-fill. An untouched credential
MUST therefore be omitted from the update payload, so OpenRegister's save-side preserve rule
carries the stored value forward. Clearing MUST require a deliberate act that sends an explicit
null.

The credential field SHALL be shown only for an authorization type that carries a credential.
Selecting a type that carries none MUST persist a null credential, so a decommissioned key is
retired rather than left unreachable at rest.

@e2e exclude the round-trip assertion is on the payload shape and on OpenRegister's preserve rule — covered by vitest and by OpenRegister's own suite

#### Scenario: editing an unrelated field keeps the stored credential

- GIVEN a consumer with a stored `authorizationConfiguration.apiKey`
- WHEN the operator edits only its description and saves
- THEN the payload OMITS `authorizationConfiguration`
- AND the stored key still authenticates inbound calls afterwards

#### Scenario: the credential field opens empty on edit

- GIVEN a consumer with a stored credential
- WHEN the operator opens it for editing
- THEN the credential editor is empty, and its help text states that this is the write-only
  boundary rather than a missing value

#### Scenario: clearing the credential is explicit

- GIVEN a consumer with a stored credential
- WHEN the operator uses the clear control and saves
- THEN the payload carries `authorizationConfiguration: null` and the stored credential is removed

#### Scenario: switching to a credential-less type retires the credential

- GIVEN a consumer with `authorizationType: apiKey` and a stored key
- WHEN the operator changes the type to `none` and saves
- THEN the credential editor is no longer shown
- AND the payload carries `authorizationConfiguration: null`, so the key is not left stored and
  unreachable

#### Scenario: invalid credential JSON blocks the save

- GIVEN the credential editor containing text that is not a JSON object
- WHEN the operator attempts to save
- THEN the save is refused with an inline parse error, rather than persisting a value the engine
  cannot read

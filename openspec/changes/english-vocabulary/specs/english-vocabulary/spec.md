## ADDED Requirements

### Requirement: Wire vocabulary SHALL be preserved at the protocol boundary

openconnector SHALL preserve the field names, resource names and enumerated values of
the external APIs it adapts. A field that mirrors an external contract SHALL NOT be
internationalised, because renaming it makes the payload stop matching — silently, since
the adapter continues to run and simply produces nothing.

#### Scenario: A field belonging to an external standard is left unchanged

- **WHEN** a property mirrors a published external contract, such as StUF's `berichttype`
  or `verwerkingssoort`, ZGW Notificaties' `kanalen[].naam`, or DSO/STAM's `bronorganisatie`
- **THEN** the property name SHALL be preserved
- **AND** the schema SHALL carry an explicit marker recording which standard it mirrors

#### Scenario: The classification is evidenced from the schema's own description

- **WHEN** a schema is classified as wire rather than ours
- **THEN** the evidence SHALL be the schema's own `description` naming the external API
- **AND** the classification SHALL NOT rest on the property name looking foreign

#### Scenario: An external product name is preserved in an identifier

- **WHEN** a class names an external product, such as `BerichtenboxClient`,
  `KlantinteractiesClient` or `ZaakTranslator`
- **THEN** the identifier SHALL be preserved
- **AND** renaming it SHALL be treated as misnaming what the class actually handles

### Requirement: Vocabulary that is ours SHALL be renamed to English

Properties and identifiers describing openconnector's own bookkeeping SHALL use English
names. Where a property already carries an English `title`, that `title` SHALL be the
source of the English name.

#### Scenario: Our own sync record is renamed

- **WHEN** `ris_sync_record` tracks activity between OpenConnector and a remote RIS,
  rather than mirroring a remote payload
- **THEN** `risVergaderingId` SHALL be renamed to `risMeetingId`
- **AND** `besluitStatus` SHALL be renamed to `decisionStatus`
- **AND** each new name SHALL match that property's existing `title`

#### Scenario: An ambiguous statutory word is resolved from its consumer

- **WHEN** `besluitStatus` could carry either the decision-instrument sense or the
  decision-letter sense
- **AND** its value is read from an iBabs response and mapped onto a `Decision.outcome`
- **THEN** it SHALL be treated as the decision-instrument sense and named `decisionStatus`

#### Scenario: A Dutch method name describing our own logic is renamed

- **WHEN** a method name describes openconnector's own behaviour rather than a protocol
  operation
- **THEN** it SHALL be renamed to English
- **AND** the classification SHALL be made per identifier, never by a bulk or scripted rename

### Requirement: Enumerated wire values SHALL survive a property rename

Renaming a property SHALL NOT translate the values stored in it when those values
originate from an external system. The key is ours; the value is the wire.

#### Scenario: A renamed property keeps its source values

- **WHEN** `besluitStatus` is renamed to `decisionStatus`
- **THEN** its enum SHALL still contain `aangenomen`, `verworpen`, `aangehouden` and
  `doorgeschoven`
- **AND** a stored record SHALL still reproduce exactly what the source system reported

#### Scenario: A mapping that compares against a wire value keeps working

- **WHEN** a mapping lowercases the stored value and compares it against `aangenomen`
- **THEN** that comparison SHALL continue to match after the rename
- **AND** the change SHALL verify the mapping rather than assume it

### Requirement: Cross-app keys SHALL NOT be renamed unilaterally

`zaakId` is a foreign key into procest, held by openconnector and docudesk. It SHALL be
renamed only in a coordinated window with the owning app, because a unilateral rename
desynchronises the apps without failing any of their tests.

#### Scenario: The owning app renames first

- **WHEN** `zaakId` is renamed to `caseId`
- **THEN** procest's rename SHALL have landed first
- **AND** openconnector and docudesk SHALL follow within the same window

#### Scenario: A unilateral rename is rejected

- **WHEN** a change renames `zaakId` in openconnector alone
- **THEN** the change SHALL be rejected
- **AND** the reason SHALL be recorded as silent cross-app breakage, not as a style objection

#### Scenario: Test suites are not treated as sufficient evidence

- **WHEN** openconnector's own suite passes after renaming `zaakId`
- **THEN** that result SHALL NOT be treated as evidence the rename is safe
- **AND** the consuming read sites in the other apps SHALL be diffed explicitly

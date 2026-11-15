# register-vocabulary Specification

## Purpose
Some register tables were created with Dutch column names and some with English ones. This spec makes the schema the authority: a repair step renames a column towards the name its schema declares and never against it, so stored data stays readable after an upgrade.

## Requirements

### Requirement: A column follows the name its schema declares (REQ-RV-001)

The vocabulary repair step MUST decide per shard table which way a Dutch and English column pair moves, from the property names that table's schema declares. When the schema declares only the English name, the Dutch column MUST move to the English one. When the schema declares only the Dutch name, the English column MUST move back to the Dutch one. In every other case, including a schema that cannot be read, the table MUST be left as it is. A move MUST rename the column when the target does not exist, and otherwise copy only into empty target cells. The step MUST NOT drop a column or overwrite a stored value, and a second run MUST change nothing. Approved by Ruben on 2026-10-05.

#### Scenario: A wire name the schema declares stays

- GIVEN `rod_message` declares `kenmerk` and its table has a `kenmerk` column
- WHEN the step runs
- THEN the column is still `kenmerk`
- @e2e exclude repair step: covered by PHPUnit and the live upgrade

#### Scenario: A column the old step renamed is restored

- GIVEN `rod_message` declares `kenmerk` and its table has only `reference`, holding the kenmerk of an outbound message
- WHEN the step runs on upgrade
- THEN the table has `kenmerk` with that value
- AND a signed ROD retour with that kenmerk is matched and stored
- @e2e exclude repair step: covered by PHPUnit and the live upgrade of a pre-fix install

#### Scenario: A schema that declares the English name still moves

- GIVEN `iwmo_ijw_message` declares `reference` and its table has `kenmerk`
- WHEN the step runs
- THEN the column is `reference` with its data
- @e2e exclude repair step: covered by PHPUnit

#### Scenario: A schema that cannot be read leaves the table alone

- GIVEN a shard table whose schema properties cannot be read
- WHEN the step runs
- THEN no statement is executed on that table
- @e2e exclude repair step: covered by PHPUnit

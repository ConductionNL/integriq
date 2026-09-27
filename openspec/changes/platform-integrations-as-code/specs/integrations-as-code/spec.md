# integrations-as-code Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- platform-integrations-as-code

## Purpose

An operator lists, runs and tests sources, synchronizations and jobs with
`occ`, and keeps an integriq configuration as files in a git repository that
another instance imports after a preview. Matrix rows `integriq:plt-cli` and
`integriq:plt-git`.

## ADDED Requirements

### Requirement: Sources, synchronizations and jobs can be listed, run and tested with occ (REQ-IAC-001)

Integriq MUST provide `integriq:source:list`, `integriq:source:test`,
`integriq:synchronization:list`, `integriq:synchronization:run`,
`integriq:job:list` and `integriq:job:run`. Each command that takes an object
MUST accept its uuid or its slug. Each MUST call the same service method the
matching screen action calls, MUST support `--output=json`, and MUST exit with
0 on success, 1 on a failed run or test, and 2 when the object is not found.
`integriq:synchronization:run --test` MUST make no writes.

#### Scenario: a nightly script tests a source
- GIVEN an operator's script running `occ integriq:source:test brp-haalcentraal --output=json`
- WHEN the source answers 200
- THEN the command prints the status and duration as JSON and exits with 0
- @e2e exclude a command-line interface; covered by PHPUnit on SourceTest with a mocked SourceTestService

#### Scenario: an unknown slug exits with 2
- GIVEN no synchronization with slug `does-not-exist`
- WHEN the operator runs `occ integriq:synchronization:run does-not-exist`
- THEN the command prints that nothing was found and exits with 2
- @e2e exclude a command-line interface; covered by PHPUnit on SynchronizationRun

#### Scenario: a test run writes nothing
- GIVEN a synchronization whose target is a register
- WHEN the operator runs it with `--test`
- THEN the command prints what would be created and updated and no object is written
- @e2e exclude a command-line interface; covered by PHPUnit relying on `synchronization-engine` REQ-011

### Requirement: A configuration exports to a directory of stable files (REQ-IAC-002)

`integriq:config:export <configuration> <directory>` MUST write one index file
and one JSON file per entity at `<type>/<slug>.json`, with sorted keys, slug
references, credentials as `credentialRef` placeholders or redacted, and no
volatile fields such as `uuid`, `created`, `updated` or `lastRun`. Exporting
the same configuration twice without changes MUST produce identical files.

#### Scenario: one mapping edit is one file changed
- GIVEN a configuration exported into a git working tree and committed
- WHEN an engineer edits one rule of mapping `woo-index-publication` on the screen and exports again
- THEN `git status` shows only `mappings/woo-index-publication.json` modified
- @e2e exclude a command-line interface; covered by an integration test on ConfigExport

#### Scenario: no secret reaches the files
- GIVEN a source with a password stored inline and another with a `credentialRef`
- WHEN the configuration is exported
- THEN the first source's password is redacted, the second keeps its `credentialRef`, and no secret value is written
- @e2e exclude a file content check; covered by PHPUnit on ConfigurationDirectoryCodec

### Requirement: A directory imports with a preview and only when confirmed (REQ-IAC-003)

`integriq:config:import <directory>` MUST print the preview of creates, updates
and collisions and the sources whose credentials need re-entry, and MUST NOT
write anything unless `--confirm` is given. With `--confirm` it MUST import
through the same path the screen's import uses.

#### Scenario: a reviewed change reaches acceptance
- GIVEN a directory checked out from git on the acceptance server
- WHEN the operator runs `occ integriq:config:import ./integriq-config` and then again with `--confirm`
- THEN the first run lists one update and writes nothing, and the second run applies it
- @e2e exclude a command-line interface; covered by an integration test on ConfigImport

### Requirement: Export and import round-trip without drift (REQ-IAC-004)

Exporting a configuration, importing the directory into an instance without it,
and exporting again from that instance MUST produce identical files.

#### Scenario: a round trip is byte for byte
- GIVEN a configuration with two sources, three mappings and one synchronization, all with slugs
- WHEN it is exported, imported into a clean instance and exported there
- THEN the two directories are identical
- @e2e exclude a two-instance round trip; covered by an integration test

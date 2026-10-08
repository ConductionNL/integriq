# configuration-export-import Specification

## Purpose

Share a connector set with another instance through the store, with no GitHub dependency and no credentials on the way.
Only decided behaviour is specified here. Matrix row `integriq:plt-share-config`.

## ADDED Requirements

### Requirement: A connector set is shared through OpenRegister's configuration sharing (REQ-CSHR-001)

Integriq MUST hand a connector set it shares to OpenRegister's configuration sharing.
Integriq MUST NOT open a sharing connection of its own to another instance, to GitHub or to a store registry.

#### Scenario: sharing hands the set to OpenRegister
- GIVEN an administrator who selects a connector set to share
- WHEN integriq prepares the share
- THEN it passes the set to OpenRegister's sharing service
- AND integriq's own HTTP client makes no outbound call for the share
- @e2e exclude backend wiring; covered by PHPUnit asserting the OpenRegister call and no CallService or Guzzle use

### Requirement: Sharing a connector set does not need GitHub (REQ-CSHR-002)

Sharing a connector set with another instance MUST work on an instance that has no GitHub token and no route to github.com.

#### Scenario: an instance without GitHub shares a set
- GIVEN an instance with no GitHub token configured
- WHEN an administrator shares a connector set with a peer instance
- THEN the share completes without any request to github.com or api.github.com
- @e2e exclude needs two federated instances; covered by an integration test with outbound GitHub hosts blocked

### Requirement: Credentials never travel with a shared set (REQ-CSHR-003)

A shared connector set MUST carry the same redactions as an export.
Secrets MUST be redacted, and a `credentialRef` MUST pass through unresolved.

#### Scenario: a source with a secret is shared
- GIVEN a source with a stored API key and a source with a `credentialRef`
- WHEN the connector set is shared
- THEN the shared payload holds no API key value
- AND the `credentialRef` is present unresolved
- @e2e exclude payload content; covered by PHPUnit on the shareable type's serialiser

#### Scenario: the receiver re-enters credentials
- GIVEN a shared set installed on another instance
- WHEN its administrator opens the imported sources
- THEN each source with a redacted credential is flagged for re-entry
- @e2e exclude reuses configuration-export-import REQ-009 coverage

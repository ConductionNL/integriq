# Design: platform-connector-set-sharing

## Where it fits

OpenRegister owns configuration sharing. Integriq owns the connector set: its sources, mappings, synchronizations, endpoints, rules and jobs.
Integriq's export already produces a slug-referenced OpenAPI document and redacts credentials (`configuration-export-import`).
A shared connector set is that export, handed to OpenRegister's sharing instead of written to a file.

## D1. Integriq builds no transport

ADR-022 says a leaf app consumes OpenRegister's abstractions and never rebuilds them.
Integriq therefore makes no OCM call, no GitHub call and no store-registry call for sharing.
It registers a shareable type with OpenRegister and lets OpenRegister move the set.
The research report sizes that type at about one week (section C, design 3, cost).

## D2. GitHub is not on the path

Decision 57 moves sharing away from GitHub. A shared connector set must reach a peer on an instance with no GitHub token.
Whether GitHub stays as an optional adapter is open question 3. This change does not depend on the answer.

## D3. Credentials stay home

A shared set is an export, so `configuration-export-import` REQ-005 (redaction) and REQ-010 (`credentialRef` passes through unresolved) apply unchanged.
The receiving administrator fills in credentials locally, as after any import (REQ-009).

## D4. Course marketplace selection

The selection of courses lives in each marketplace synchronization's `conditions`, not on the source (`connectors-course-marketplace` design, "Selection and retirement").
The research report argues `conditions` should be receiver-local when a set is shared. Ruben has not decided this (open question 7).
Until he does, nothing here specifies how `conditions` behave on import, and the Task 6 form half is not built.

## What waits for Ruben

The transport design (report designs 1 to 3), the signing and trust model, the directory, and public versus trusted-only items.
Each is an OpenRegister concern. Integriq's tasks 2 and 3 stay blocked until OpenRegister has a chosen design to register against.

## Risks

- Building ahead of the decision would bind integriq to a transport Ruben may reject. Mitigation: tasks 2 and 3 are blocked.
- A shared set could overwrite a receiver's own selection. Mitigation: deferred to question 7, and import keeps its preview and confirmation (REQ-007, REQ-008).

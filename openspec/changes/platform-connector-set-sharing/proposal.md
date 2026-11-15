---
kind: code
depends_on: []
status: research-gated
---

# Proposal: platform-connector-set-sharing

## Summary

Share a connector set with another Nextcloud instance through the store, without GitHub.
This change specifies only what Ruben has decided. The transport design is still a research question.
Every task that depends on an open question stays blocked until Ruben answers it.

## Why

Ruben, 3 Oct 2026 (build-all decision 57): sharing configurations through the store is an important part of the ecosystem.
Courses from learniq and case types from dossiq are his examples, and connector sets belong to the same family.
Today the sharing paths lean on GitHub. Ruben wants them to move to Open Cloud Mesh (OCM), the protocol other Nextcloud federations use.

What travels today, per the research report (`for-ruben/config-sharing-over-ocm-research.md`, 3 Oct 2026):

- Integriq's own export and import take an uploaded document only, with no URL and no GitHub path.
- Environment promotion pushes to a peer's import endpoint through `CallService`.
- Integriq has no OCM code. Its "federation" hits are FSC.
- OpenRegister owns every store and sharing path: the AppHost store plane, the signed `federated-config-sharing` bundles, GitHub discovery, and OCM receipt for live objects.

Under ADR-022 integriq consumes OpenRegister's sharing. It does not build a transport of its own.

This change adds one matrix row: `integriq:plt-share-config`, "Share a connector set with another instance through the store."

## What is decided

1. A connector set can be shared with another instance through the store (decision 57).
2. Sharing does not depend on GitHub. OCM is the direction for instance-to-instance transport (decision 57).
3. Integriq hands a shared set to OpenRegister's configuration sharing and opens no connection of its own (ADR-022).
4. Credentials never travel. A shared set carries broker references, as an export does today (`configuration-export-import` REQ-005 and REQ-010).
5. The course marketplace Task 6 form half (`connectors-course-marketplace`) is not built now.

## What is not decided

The research report recommends design 3: one signed bundle, with OCM as the default transport. Ruben has not chosen a design yet.
The open questions, from section D of the report:

1. Who signs the federation directory: Conduction for one federation, or each federation (VNG, SURF, a province) for its own?
2. May OpenRegister raise its minimum Nextcloud from 32 to 33, which the OCM catalogue needs?
3. Does GitHub stay as an optional transport for open publication, or is it retired entirely?
4. Are published store items public to anyone who can reach the server, or served to trusted servers only?
5. Does the publisher key belong to the instance, as now, or to the organisation?
6. Do we register the resource type and capability in the IETF OCM registries, or keep them Conduction-specific for now?
7. Course marketplace Task 6: do the selection fields go on the synchronization form and write `conditions`, and are `conditions` receiver-local when a connector set is shared?

## Scope

- In: the decided requirements above, written so a builder can test them once the transport exists.
- Out: the transport, the bundle format, the directory, trust anchors and every store page. OpenRegister owns those (`openregister/store-over-federated-config`, `openregister/platform-cloud-federation-provider`).
- Out: the course marketplace selection fields, until question 7 is answered.

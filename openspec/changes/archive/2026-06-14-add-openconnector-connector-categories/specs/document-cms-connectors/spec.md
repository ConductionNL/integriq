# Spec: document-cms-connectors

**Status:** proposed
**Scope:** openconnector
**Tier:** connector-categories
**Depends on:** data-infra-connectors (openconnector, for the shared registration contract REQ-DIC-001/002), prometheus-metrics (openconnector), docudesk (cross-app — owns the Conduction-side document store), hydra ADR-019 (integration-registry), hydra ADR-022 (apps consume OR abstractions), hydra ADR-024 (app manifest), hydra ADR-031 (schema-declarative business logic)

## ADDED Requirements

### Requirement: Document/CMS connector adapters SHALL register through the integration registry per ADR-019 and target EXTERNAL document systems only (REQ-DCC-001)

Document/CMS connector adapters MUST register through the integration
registry per ADR-019 and target external document systems only.
Every adapter for an external document-management or
content-management system — Microsoft SharePoint Online,
SharePoint Server, Microsoft OneDrive (business + consumer),
Google Drive (workspace + consumer), Alfresco, OpenText
Documentum, M-Files, Box, Hyland OnBase, Nuxeo, and the
NL government surfaces VIP-files (per VNG GEMMA) and NLX
brokered document services — MUST be implemented as an
`IntegrationProvider` registered through OR's integration
registry per ADR-019. Adapter classes MUST live under
`lib/Service/Adapter/DocumentCms/`.

This spec scopes EXTERNAL DMS connectors only. Any document
storage on the Conduction side MUST be consumed from docudesk
per ADR-022; openconnector MUST NOT re-implement file storage,
metadata indexing, or retention. A document fetched from
SharePoint and persisted on the Conduction side lands as a
docudesk attachment via the existing docudesk file API; the
DCC adapter is the transport, docudesk is the home.

#### Scenario: Reviewer confirms no Conduction-side document store in openconnector

- **GIVEN** the openconnector codebase under
  `lib/Service/Adapter/DocumentCms/`
- **WHEN** scanned for persistence calls into
  `OCP\Files\IRootFolder`, `OCA\OpenRegister\Db\FileMapper`,
  or any locally authored `Document*Mapper` class
- **THEN** no such calls SHALL exist; documents that need to
  land on the Conduction side MUST go through docudesk's file
  API (per ADR-022).

#### Scenario: Reviewer confirms no per-app DMS HTTP client in sibling apps

- **GIVEN** any sibling app's `lib/Service/` tree
- **WHEN** scanned for `Microsoft\Graph\\*`, `Google\Service\Drive`,
  `Box\\*`, `Alfresco\\*`, or any SharePoint REST client
- **THEN** no such imports SHALL exist; DMS access MUST route
  through openconnector by integration slot slug.

### Requirement: Each DCC adapter manifest entry SHALL declare the four canonical capabilities — file-crud, metadata-sync, search-federation, acl-bridging — with explicit support flags (REQ-DCC-002)

Every DCC adapter MUST publish a manifest entry per ADR-024
(extending the REQ-DIC-002 shape) with `category: document-cms`
and a `capabilities[]` array drawn from the canonical set:

| Capability | Meaning | Typical underlying API |
|---|---|---|
| `file-crud` | Create, read, update, delete files in the remote DMS | SharePoint Files API, Drive Files API, Alfresco CMIS |
| `file-versioning` | Read version history; read previous versions; create new versions | DMS-native version endpoints |
| `metadata-sync` | Read + write document properties (title, author, tags, content type, custom fields) | DMS property/property-bag APIs |
| `search-federation` | Issue federated search queries against the remote DMS and return normalised hits | DMS search APIs (KQL, Drive query, CMIS Query Language) |
| `acl-bridging` | Read remote ACLs and surface them as OR RBAC subjects/groups; OPTIONALLY translate Conduction-side RBAC writes into remote ACL changes when the operator opts in | DMS permission APIs |
| `event-subscribe` | Subscribe to remote change events (file added/modified/deleted/permission-changed) | Graph change-notifications, Drive push, CMIS browser events |
| `checkin-checkout` | Acquire/release editing locks on remote files | DMS lock/unlock endpoints |

Capabilities a given DMS does not support MUST be omitted —
adapters MUST NOT claim a capability they cannot fulfil. The
manifest validator MUST reject any unknown capability literal.

#### Scenario: A SharePoint adapter declares the full canonical capability set

- **GIVEN** the SharePoint adapter manifest entry
- **WHEN** inspected
- **THEN** `capabilities[]` SHALL include `file-crud`,
  `file-versioning`, `metadata-sync`, `search-federation`,
  `acl-bridging`, `event-subscribe`, `checkin-checkout`.

#### Scenario: An NLX-brokered adapter omits capabilities it cannot fulfil

- **GIVEN** the NLX adapter manifest entry, which fronts a
  read-only brokered service
- **WHEN** inspected
- **THEN** `capabilities[]` SHALL include `file-crud` (read
  only — verified by the adapter's REQ-DCC-005 read-only flag),
  `metadata-sync` (read only), `search-federation`; and MUST
  NOT include `acl-bridging` (NLX does not surface upstream
  ACLs) or `checkin-checkout`.

### Requirement: ACL bridging SHALL be read-by-default; write-side ACL propagation is opt-in per source (REQ-DCC-003)

ACL bridging MUST be read-by-default. Per ADR-005 (security) and ADR-022 (apps consume OR
abstractions), ACL bridging MUST default to read-only — the
adapter surfaces remote ACLs as OR RBAC subjects so a sibling
app can render "Jan has read access in SharePoint" without
modifying the remote DMS. Write-side propagation
(Conduction-side RBAC change → remote ACL change) is OPT-IN
per `Source` record via an `aclWriteBack: boolean` setting on
the source's configuration JSON (stored in openconnector, not
in any sibling app).

When `aclWriteBack: false` (the default), any caller invoking
the adapter's `setRemoteAcl()` method MUST receive a
`AclWriteDisabledException` and the call MUST NOT mutate
remote state. The adapter MUST log the rejected attempt
through openconnector's existing CallLog.

#### Scenario: Default source rejects ACL write-back

- **GIVEN** a freshly configured SharePoint source with no
  `aclWriteBack` override
- **WHEN** a sibling app invokes `setRemoteAcl(...)` via the
  adapter
- **THEN** the call MUST throw `AclWriteDisabledException`;
  **AND** an entry MUST land in the existing openconnector
  `CallLog` table with outcome `rejected-acl-write-disabled`.

#### Scenario: Operator opts in and ACL write-back succeeds

- **GIVEN** an operator sets `aclWriteBack: true` on the source
- **WHEN** the same `setRemoteAcl(...)` call runs
- **THEN** the remote DMS MUST receive the ACL change; **AND**
  the audit trail on the source MUST record the operator who
  enabled write-back and the date of enablement.

### Requirement: Search federation SHALL normalise hits into a shared result envelope so multiple DMS can be queried side-by-side (REQ-DCC-004)

Search federation MUST normalise hits into a shared result envelope.
A sibling app issuing a federated search against multiple DMS
sources at once MUST receive a uniform result shape regardless
of underlying API. Each hit MUST carry:

| Field | Type | Purpose |
|---|---|---|
| `sourceSlug` | string | Which openconnector source returned the hit |
| `remoteId` | string | The DMS's native identifier |
| `title` | string | Document title |
| `mimeType` | string | Normalised MIME type |
| `sizeBytes` | integer or null | File size when known |
| `modifiedAt` | date-time | Remote last-modified timestamp |
| `modifiedByLabel` | string or null | Best-available actor label (display name); MUST NOT contain a credential |
| `path` | string or null | Remote path/folder reference (best-effort) |
| `previewUrl` | string (URL) or null | URL into the remote DMS for preview |
| `snippet` | string or null | Search-engine-generated excerpt |
| `score` | number (0..1) | Normalised relevance score across sources |

Adapter authors MUST implement the per-DMS hit-to-envelope
mapping; consuming apps MUST consume only this normalised
shape. Per ADR-022, the normalisation logic lives in
openconnector (one place per DMS) — sibling apps MUST NOT
reach into DMS-native response shapes.

#### Scenario: Federated query across two DMS returns one merged envelope shape

- **GIVEN** an operator has configured both a SharePoint and an
  Alfresco source
- **WHEN** a sibling app issues a federated search for
  `"contract addendum"` across both
- **THEN** every returned hit MUST conform to the envelope
  above regardless of source; `sourceSlug` MUST distinguish
  the two; `score` MUST be normalised to `0..1` so the
  caller can merge-sort.

### Requirement: Each adapter SHALL declare a `readOnly` posture per source so reviewers can audit blast radius (REQ-DCC-005)

Per ADR-005, the adapter manifest entry MUST declare a
`defaultReadOnly: boolean` field. Per-source overrides live on
the openconnector source record's configuration JSON. When
`readOnly: true`, the adapter MUST reject every method whose
effect on the remote system is mutative
(`createFile`, `updateFile`, `deleteFile`, `setMetadata`,
`setRemoteAcl`, `checkOut`, `checkIn`, `subscribe` when the
subscribe creates remote state) with a
`ReadOnlySourceException`.

This gives operators a one-line audit answer: "what is the
blast radius of this source?" If `readOnly: true`, the answer
is "nothing — read-only by construction." If `readOnly: false`,
the answer is the list of mutative methods the adapter exposes,
which is itself derivable from the manifest's `capabilities[]`.

#### Scenario: A read-only NLX source rejects write attempts

- **GIVEN** an NLX source configured with `readOnly: true`
  (the default for NLX adapters)
- **WHEN** any caller invokes `createFile(...)` via the adapter
- **THEN** the call MUST throw `ReadOnlySourceException`; **AND**
  no remote-side state SHALL change; **AND** the rejection MUST
  land in CallLog.

### Requirement: Documents persisted on the Conduction side from a DCC adapter SHALL land as docudesk attachments referenced by URI, never as openconnector-owned files (REQ-DCC-006)

Documents persisted on the Conduction side MUST land as docudesk attachments.
When a sibling app's workflow needs to retain a document
fetched from an external DMS (rather than streaming on demand),
the persistence MUST go through docudesk's existing file API
per ADR-022. The DCC adapter's job ends at returning the file
contents + the normalised metadata envelope; persistence is the
caller's choice and the caller routes it through docudesk.

The OR object that retained the document MUST reference the
docudesk attachment by URI; the DCC adapter MUST NOT store the
bytes anywhere itself beyond the request-scoped transfer buffer.

#### Scenario: A workflow stores a SharePoint contract as a docudesk attachment

- **GIVEN** a workflow that fetches a contract PDF from
  SharePoint via the adapter
- **WHEN** the workflow persists the file
- **THEN** the file bytes MUST be POSTed to docudesk's file
  endpoint; the OR object MUST carry a docudesk URI; no file
  bytes SHALL be written under openconnector's app data or
  any openconnector-owned table.

### Requirement: Individual per-DMS adapters are explicitly out of scope for this spec — each adapter MUST ship in its own `add-openconnector-{slug}-adapter` change (REQ-DCC-007)

Individual per-DMS adapter implementations MUST be out of scope for this spec.
Per the same pattern as REQ-DIC-007, individual DMS adapter
implementations (e.g. SharePoint, Alfresco, NLX, VIP-files)
MUST land as separate openspec changes named
`add-openconnector-{slug}-adapter`, each consuming this spec
by REQ reference. Per-adapter changes MUST cite REQ-DCC-001
through REQ-DCC-006 in their proposal and MUST NOT re-derive
the category-level contract.

#### Scenario: A new SharePoint adapter change references this spec

- **GIVEN** a new change folder
  `openspec/changes/add-openconnector-sharepoint-adapter/`
- **WHEN** its proposal.md is inspected
- **THEN** the `Depends on` line MUST include
  `document-cms-connectors (openconnector)` and
  `docudesk` (for the persistence path); the proposal MUST
  cite REQ-DCC-002 (capabilities), REQ-DCC-004 (search
  envelope), and REQ-DCC-006 (docudesk persistence) by REQ id.

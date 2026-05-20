# Endpoint and virtual desktop workspace connectors

## Summary

This change adds two new workspace connector integrations to OpenConnector: a **Recast/Liquit** connector for managed application provisioning and a **VMware/Omnissa Horizon** connector for virtual desktop access. Both connectors are exposed as new Source types and `/api/workspace/*` proxy endpoints, allowing mydash to surface and launch workspace resources directly from the dashboard without requiring separate portal logins.

## Motivation

Dutch municipalities and government organisations increasingly rely on managed application delivery platforms (Recast/Liquit) and virtual desktop infrastructure (VMware Omnissa Horizon) as part of their digital workspace strategy. Without OpenConnector integration, users must navigate to separate portals, authenticate again, and context-switch away from their corporate dashboard.

Market intelligence from tender analysis:
- **Recast/Liquit integration** — demand score **55** (1 tender mention)
- **VMware/Omnissa Horizon integration** — demand score **34** (1 tender mention)

## Features

### Integration: Recast / Liquit (demand: 55)

Connects OpenConnector to a Recast/Liquit managed endpoint environment. IT admins register the Recast/Liquit API URL and credentials as a Source of type `recast-liquit`. Once the Source is active, OpenConnector exposes endpoints for:

- Retrieving the list of applications available to the current user
- Initiating an application launch or installation via the Recast/Liquit backend

mydash (or any consumer) calls these endpoints to populate and activate the Recast/Liquit tile grid in the user's dashboard.

**Acceptance criteria:**
1. GIVEN a Recast/Liquit environment is configured, WHEN an IT admin enters the connection credentials in settings, THEN the integration status shows as connected and available apps are retrieved.
2. GIVEN the integration is active, WHEN an end user views their mydash portal, THEN Recast/Liquit-managed applications appear as launchable tiles alongside other resources.
3. GIVEN a user clicks a Recast/Liquit app tile, WHEN the launch request is processed, THEN the application starts or installs via the Recast/Liquit backend without leaving the mydash interface.

### Integration: VMware / Omnissa Horizon (demand: 34)

Connects OpenConnector to a VMware Omnissa Horizon Connection Server. IT admins register the Horizon server URL, domain credentials, and optional SSL configuration as a Source of type `vmware-horizon`. Once active, OpenConnector exposes endpoints for:

- Retrieving the desktop pools entitled to the current user
- Initiating a Horizon session (HTML Access URL or native client launch token)

**Acceptance criteria:**
1. GIVEN a configured Horizon connection, WHEN an IT admin enters the Horizon server URL and credentials, THEN mydash connects and retrieves the available desktop pools.
2. GIVEN a connected Horizon environment, WHEN an end user opens mydash, THEN their entitled virtual desktops appear as launchable items in the dashboard.
3. GIVEN a Horizon session launch, WHEN an end user clicks a virtual desktop, THEN the Horizon client or HTML Access session initiates without leaving the mydash interface.

## Scope

- New `RecastLiquitService` (`lib/Service/RecastLiquitService.php`) — Recast/Liquit API client: app discovery, launch
- New `HorizonService` (`lib/Service/HorizonService.php`) — VMware Horizon REST API client: pool discovery, session initiation
- New `WorkspaceConnectorController` (`lib/Controller/WorkspaceConnectorController.php`) — exposes `/api/workspace/*` proxy endpoints
- Source type registration for `recast-liquit` and `vmware-horizon` in existing Source entity
- Connection test support for both source types (integrated with SourcesController test-connection flow)
- Admin settings entry points for connector configuration

## Out of Scope

- mydash UI changes (tiles, launch buttons) — mydash consumes the OpenConnector endpoints; its UI is a separate change
- Synchronisation of app/desktop catalogues into OpenRegister — live proxy calls only in this phase
- Single-sign-on or token federation with Recast/Liquit or Horizon (admin credentials only in phase 1)
- Mobile client launch flows (HTML Access only for Horizon in phase 1)

## Stakeholders

### IT Admin
Configures and manages Recast/Liquit and VMware Horizon Source objects in OpenConnector. Enters API credentials, validates connectivity via the test-connection action, and controls which app catalogues are visible to users.

### End User
Accesses managed applications and virtual desktops through the mydash portal. Expects entitled resources to appear as launchable tiles without separate portal logins.

### System Administrator
Monitors OpenConnector connector health, call logs, and rate limiting for workspace connector Sources.

## Customer Journeys

### Journey 1: IT admin activates Recast/Liquit for a municipality

**Trigger:** Organisation adopts Recast/Liquit for managed application delivery.
**Pain point:** Without integration, IT admins share app catalogue URLs manually; users must authenticate separately on the Recast/Liquit portal.

Steps:
1. IT admin opens OpenConnector → Sources → Add source
2. Selects type `recast-liquit`, enters API base URL and API key
3. Clicks "Test connection" — OpenConnector calls the Recast/Liquit health/catalogue endpoint
4. Connection succeeds; source shows app count and status `active`
5. mydash is configured to show Recast/Liquit tiles; end users see apps on next login

### Journey 2: End user launches a managed application

**Trigger:** End user logs in to mydash and sees their Recast/Liquit tiles.
**Pain point:** Without integration, users open a separate portal and re-authenticate.

Steps:
1. User views mydash — Recast/Liquit apps appear as tiles
2. User clicks tile (e.g., "Adobe Reader managed via Recast")
3. mydash calls `POST /api/workspace/recast/launch/{appId}` on OpenConnector
4. OpenConnector forwards launch request to Recast/Liquit backend
5. Application starts or installs in the user's managed environment

### Journey 3: IT admin activates VMware Horizon for remote workers

**Trigger:** Organisation deploys VMware Omnissa Horizon for VDI/remote work.
**Pain point:** VDI users must know and bookmark the Horizon URL separately.

Steps:
1. IT admin opens OpenConnector → Sources → Add source
2. Selects type `vmware-horizon`, enters Connection Server URL, service account credentials, domain
3. Clicks "Test connection" — OpenConnector retrieves the desktop pool list
4. Connection succeeds; source lists available pools
5. End users see Horizon desktops on their dashboard on next login

### Journey 4: End user starts a virtual desktop session

**Trigger:** End user clicks a Horizon desktop tile in mydash.
**Pain point:** Without integration, users navigate to Horizon URL, select pool, and wait for authentication — multiple steps outside the corporate portal.

Steps:
1. User clicks a Horizon desktop tile in mydash
2. mydash calls `POST /api/workspace/horizon/launch/{poolId}` on OpenConnector
3. OpenConnector requests a launch token from the Horizon REST API
4. Response contains an HTML Access URL or native client URI
5. User's browser opens the session — they are in their virtual desktop without leaving mydash context

## User Stories

### US-EWC-001: Configure Recast/Liquit connection

**As an IT admin**, I want to register a Recast/Liquit Source in OpenConnector, **so that** managed application discovery and launch are available to mydash.

**Acceptance criteria:**
1. GIVEN a Recast/Liquit environment is configured, WHEN an IT admin enters the API URL and key in Source settings, THEN the Source saves with status `active` and the available app count is returned.
2. GIVEN valid credentials are saved, WHEN the admin triggers test-connection, THEN OpenConnector returns the number of available applications and confirms the source is reachable.
3. GIVEN invalid credentials are entered, WHEN test-connection is triggered, THEN a generic error message is returned without exposing the underlying Recast/Liquit error.

### US-EWC-002: Browse and launch Recast/Liquit applications

**As an end user**, I want to retrieve my Recast/Liquit application catalogue via OpenConnector, **so that** mydash can display launchable app tiles.

**Acceptance criteria:**
1. GIVEN the Recast/Liquit integration is active, WHEN a GET request is made to `/api/workspace/recast/apps`, THEN the response lists all applications available to the calling user.
2. GIVEN a user POSTs to `/api/workspace/recast/launch/{appId}`, WHEN the request is processed, THEN OpenConnector forwards the launch request to the Recast/Liquit backend and returns a success or launch URL.
3. GIVEN the Recast/Liquit source is unreachable, WHEN the apps endpoint is called, THEN a 503 response is returned with a clear error message.

### US-EWC-003: Configure VMware Horizon connection

**As an IT admin**, I want to register a VMware Horizon Source in OpenConnector, **so that** entitled virtual desktops are retrievable and launchable through mydash.

**Acceptance criteria:**
1. GIVEN the Horizon Connection Server URL and credentials are entered, WHEN the admin saves the Source, THEN OpenConnector connects to the Horizon REST API and retrieves available desktop pools.
2. GIVEN valid Horizon credentials are saved, WHEN test-connection is triggered, THEN OpenConnector returns the list of pool names and marks the Source as `active`.
3. GIVEN a Horizon server URL is malformed, WHEN the admin saves the Source, THEN a validation error is shown before any API call is made.

### US-EWC-004: Browse and launch VMware Horizon virtual desktops

**As an end user**, I want to retrieve my entitled Horizon desktop pools via OpenConnector, **so that** mydash can display virtual desktop tiles.

**Acceptance criteria:**
1. GIVEN a connected Horizon environment, WHEN a GET request is made to `/api/workspace/horizon/pools`, THEN the response lists all desktop pools entitled to the calling user.
2. GIVEN a user POSTs to `/api/workspace/horizon/launch/{poolId}`, WHEN the request is processed, THEN OpenConnector returns an HTML Access URL or native client launch URI.
3. GIVEN the user has no entitled desktops, WHEN the pools endpoint is called, THEN the response returns an empty list with HTTP 200 (not an error).

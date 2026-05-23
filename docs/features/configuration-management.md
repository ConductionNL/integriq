# Configuration Management

## Overview

OpenConnector's configuration management features allow administrators to bundle related entities into named groups, import and export configurations as structured JSON, and reference entities with stable slug-based identifiers. This enables environment migration (dev → test → production), configuration sharing between organisations, and backup/restore workflows.

## Configuration Groups

A **Configuration** (also called a configuration group) bundles related Sources, Endpoints, Mappings, Rules, Jobs, and Synchronizations under a single name. Configurations can be exported as a single JSON file and re-imported on another OpenConnector instance.

| Field | Description |
|-------|-------------|
| `name` | Human-readable configuration name |
| `slug` | URL-friendly identifier |
| `description` | Purpose and contents description |
| `sources` | Included Source slugs |
| `endpoints` | Included Endpoint slugs |
| `mappings` | Included Mapping slugs |
| `rules` | Included Rule slugs |
| `jobs` | Included Job slugs |
| `synchronizations` | Included Synchronization slugs |

## Import

Configurations are imported as JSON via:

```
POST /index.php/apps/openconnector/api/import
Content-Type: application/json
```

The import process:

1. Parses the JSON structure
2. For each entity type, upserts entities by slug (create if missing, update if exists)
3. Resolves cross-references (e.g. a synchronization referencing a source by slug)
4. Reports created, updated, and skipped counts per entity type

Import is idempotent: re-importing the same configuration updates existing entities without duplicating them.

## Export

Export a configuration group or individual entity types via:

```
GET /index.php/apps/openconnector/api/export
GET /index.php/apps/openconnector/api/export?configurationId={id}
```

The export format is an OpenAPI-structured JSON document:

```json
{
  "openapi": "3.0.0",
  "info": {
    "title": "OpenConnector Configuration Export",
    "version": "1.0.0"
  },
  "components": {
    "x-sources": [ ... ],
    "x-endpoints": [ ... ],
    "x-mappings": [ ... ],
    "x-rules": [ ... ],
    "x-synchronizations": [ ... ],
    "x-jobs": [ ... ]
  }
}
```

## Slug-Based References

All OpenConnector entities have a `slug` field — a URL-friendly, human-readable identifier that is unique per entity type. Slugs are used in:

- Export/import for stable cross-environment references
- API paths for human-readable entity access
- Cross-entity references in configurations (e.g. a job referencing a synchronization by slug)

Slugs are automatically generated from the entity name on creation and can be manually set. They do not change when entities are updated.

## Configuration Handlers

Each entity type has a dedicated configuration handler that manages import/export serialization:

| Handler | Entity |
|---------|--------|
| `SourceHandler` | Sources |
| `EndpointHandler` | Endpoints |
| `MappingHandler` | Mappings |
| `RuleHandler` | Rules |
| `SynchronizationHandler` | Synchronizations |
| `JobHandler` | Jobs |

## Settings

Global application settings (retention periods, default behaviours) are managed via the Settings section in the OpenConnector UI and stored in Nextcloud's `IAppConfig`.

```
GET  /index.php/apps/openconnector/api/settings
PUT  /index.php/apps/openconnector/api/settings
```

## Implementation

- `lib/Service/ConfigurationService.php` — Configuration group management
- `lib/Service/ImportService.php` — Import orchestration
- `lib/Service/ExportService.php` — Export orchestration
- `lib/Service/ConfigurationHandlers/` — Per-entity-type handlers
- `lib/Controller/ImportController.php` — Import REST endpoint
- `lib/Controller/ExportController.php` — Export REST endpoint
- `lib/Controller/SettingsController.php` — Settings REST endpoint

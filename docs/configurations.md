# Configurations

Configurations in OpenConnector allow you to group related entities together for easier management and export. This document explains how configurations work and how to use them effectively.

## Configuration Structure

Each entity in OpenConnector can belong to multiple configurations. This is stored in a JSON array field called 'configurations' that contains the IDs of the configurations the entity belongs to.

Additionally, each entity has a 'slug' property, which is a URL-friendly identifier that can be used for referencing the entity in URLs and API calls.

## Supported Entity Types

The following entity types support configurations:

- Sources
- Endpoints
- Mappings
- Rules
- Jobs
- Synchronizations

## Working with Configurations

### Adding Entities to a Configuration

To add an entity to a configuration, you need to add the configuration ID to the entity's 'configurations' array. For example:

```php
$source->setConfigurations(['config-123']);
$sourceMapper->update($source);
```

### Exporting Configurations

When exporting a configuration, entities are organized by components following the OpenAPI Specification (OAS) structure. The export includes:

1. Info section with configuration metadata
2. Components section with entities organized by type and component
3. Paths section generated from endpoints

Example export structure:

```json
{
  "info": {
    "configurationId": "config-123",
    "exportDate": "2024-03-21T12:00:00+00:00",
    "version": "1.0.0"
  },
  "components": {
    "sources": {
      "nextcloud": [
        {
          "id": 1,
          "name": "My Nextcloud Source",
          "slug": "my-nextcloud-source",
          "configurations": ["config-123"]
        }
      ]
    },
    "endpoints": {
      "files": [
        {
          "id": 1,
          "name": "List Files",
          "slug": "list-files",
          "configurations": ["config-123"]
        }
      ]
    }
  },
  "paths": {
    "/files": {
      "get": {
        "summary": "List Files",
        "description": "Retrieve a list of files",
        "operationId": "list-files",
        "tags": ["files"],
        "responses": {
          "200": {
            "description": "Successful operation"
          }
        }
      }
    }
  }
}
```

## Best Practices

1. Use meaningful slugs for your entities to make them easily identifiable in URLs and API calls
2. Group related entities in the same configuration for easier management
3. Use component-based organization to keep related entities together
4. Keep configuration IDs consistent across your system
5. Use the export functionality to backup and share configurations

## API Reference

### ConfigurationService

The `ConfigurationService` provides methods for working with configurations:

- `getEntitiesByConfiguration(string $configurationId): array` - Get all entities associated with a configuration
- `exportConfiguration(string $configurationId): array` - Export a configuration with entities organized by components

### Entity Mappers

Each entity mapper includes a method to find entities by configuration:

- `findByConfiguration(string $configurationId): array` - Find all entities associated with a configuration

## Database Schema

The 'configurations' field is stored as a JSON array in the following tables:

- `oc_openconnector_sources`
- `oc_openconnector_endpoints`
- `oc_openconnector_mappings`
- `oc_openconnector_rules`
- `oc_openconnector_jobs`
- `oc_openconnector_synchronizations`

The 'slug' field is stored as a VARCHAR(255) in the same tables, with an index for efficient lookups.

Example SQL to add the fields:

```sql
ALTER TABLE `oc_openconnector_sources` ADD COLUMN `configurations` JSON DEFAULT '[]';
ALTER TABLE `oc_openconnector_sources` ADD COLUMN `slug` VARCHAR(255) DEFAULT NULL;
CREATE INDEX `idx_oc_openconnector_sources_slug` ON `oc_openconnector_sources` (`slug`); 
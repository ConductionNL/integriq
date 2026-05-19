---
sidebar_position: 2
---

# Configuring Sources

Sources are the entry points for data in OpenConnector. This guide will help you set up and manage your data sources.

## Understanding Sources

A source can be:
- An API endpoint
- A database
- A file system
- An external service

## Adding a New Source

1. Navigate to the Sources section
2. Click "Add New Source"
3. Select the source type
4. Configure the connection details

## Source Types

### API Endpoints
- REST APIs
- SOAP Services
- GraphQL Endpoints

### Databases
- MySQL/MariaDB
- PostgreSQL
- MongoDB

### File Systems
- Local files
- Network shares
- Cloud storage

### External Services
- Third-party applications
- Web services
- Custom integrations

## Configuration Parameters

Each source type has specific configuration parameters:

### API Configuration
- Base URL
- Authentication method
- Headers
- Request parameters
- Additional configuration

#### Additional configuration
You can set some additional configuration parameters for sources in the configuration parameters. The current list of additional configuration parameters is:

- logBody: This parameter (containing either a boolean or the numbers 0 and 1) sets if all response bodies have to be logged. If this parameter is true (1), all response bodies for this source will be logged. If it is false (0), or the parameter is not given, only the response bodies of errors (status codes between 400 and 600) will be logged.

### Database Configuration
- Host
- Port
- Database name
- Credentials
- Connection parameters

### File System Configuration
- Path
- Access permissions
- File patterns
- Monitoring settings

## Testing Your Source

Before finalizing your source configuration:

1. Use the test connection feature
2. Verify data access
3. Check authentication
4. Review sample data

## Next Steps

After configuring your source:

- [Set up data mappings](mappings)
- [Configure synchronization jobs](jobs)
- Define transformation rules 

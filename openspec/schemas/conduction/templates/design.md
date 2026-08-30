# Design: {{change_name}}

## Architecture Overview
<!-- How does this fit into the existing system? Diagram if helpful -->

## API Design
<!-- Endpoint definitions with method, path, request/response.
     Only include if this change introduces or modifies API endpoints. Remove this section if not applicable. -->

### `METHOD /api/path`
**Request:**
```json
{}
```
**Response:**
```json
{}
```

## Database Changes
<!-- New tables, columns, migrations. Use Nextcloud migration format.
     Only include if this change modifies the database schema. Remove this section if not applicable. -->

## Nextcloud Integration
<!-- Which OCP interfaces, DI services, annotations are used? -->
- Controllers:
- Services:
- Mappers/Entities:
- Events/Hooks:

## Security Considerations
<!-- Auth, CORS, input validation, CSRF. Always include — write "No security impact" if none. -->

## NL Design System
<!-- Which components, tokens, or patterns from NL Design System apply?
     Only include if this change touches the frontend. Remove this section if not applicable. -->

## File Structure
<!-- New or modified files -->
```
lib/
  Controller/
  Service/
  Db/
```

## Seed Data
<!-- Every feature MUST have realistic seed data so the app is testable on install.
     See company-wide ADR-016 for full requirements. Key points:
     - Define seed objects for EACH schema this change introduces or modifies
     - Use general organization data (works for municipality, consultancy, travel agency)
     - Include 3-5 objects per schema with varied, realistic field values
     - Describe related items (files, notes, tasks, contacts) that should link to seed objects
     - Specify the @self envelope (register, schema, slug) for each object
     - The apply agent will generate _registers.json entries from this section
     Only include if this change introduces new data schemas or entities. Remove this section if not applicable. -->

### Schema: `{schema-slug}`
| Field | Object 1 | Object 2 | Object 3 |
|-------|----------|----------|----------|
| slug  |          |          |          |
| ...   |          |          |          |

**Related items per object:**
- Files:
- Notes:
- Tasks:
- Contacts:

## Trade-offs
<!-- What alternatives were considered and why this approach was chosen -->

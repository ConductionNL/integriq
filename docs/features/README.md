# OpenConnector Features

OpenConnector is an API gateway and integration hub for Nextcloud. It brings enterprise service bus (ESB) capabilities natively into Nextcloud — define external API connections, expose your own endpoints, transform data with flexible mappings, and keep systems synchronized through scheduled or event-driven flows.

## Feature Index

| Feature | Description | Status |
|---------|-------------|--------|
| [Sources](sources.md) | External API connections with multi-protocol authentication | Implemented |
| [Endpoints](endpoints.md) | Expose reverse-proxy API paths with rule-based logic | Implemented |
| [Mappings](mappings.md) | Twig-powered data transformation between schemas | Implemented |
| [Synchronizations](synchronizations.md) | Scheduled and event-driven source-to-target sync | Implemented |
| [Rules](rules.md) | Authentication, file handling, locking, and audit trail rules | Implemented |
| [Jobs](jobs.md) | Cron-based scheduled task execution | Implemented |
| [Events & Webhooks](events.md) | CloudEvents emission, subscription, and consumer processing | Implemented |
| [Logging & Monitoring](logging.md) | Call logs, sync logs, and Prometheus metrics | Implemented |
| [Configuration Management](configuration-management.md) | Import/export, configuration groups, slug-based references | Implemented |
| [StUF Adapter](stuf-adapter.md) | REST/ZGW to StUF-BG/ZKN SOAP translation | Partial |
| [Prometheus Metrics](prometheus-metrics.md) | Prometheus exposition format metrics + health endpoint | Implemented |
| [DSO / Omgevingsloket Adapter](dso-omgevingsloket.md) | DSO-LV STAM koppelvlak integration | Implemented |
| [iBabs & NotuBiz Connector](ibabs-notubiz-connector.md) | RIS integration for bestuurlijke besluitvorming | Implemented |

## Architecture Overview

```
External Systems                OpenConnector                     Targets
─────────────────    ───────────────────────────────────    ──────────────────
REST APIs         →  Sources → CallService → Mappings   →  OpenRegister
SOAP Services     →  Endpoints (reverse proxy)           →  External REST APIs
Webhooks          →  Consumers → EventService            →  Other Sources
Cron              →  Jobs → SynchronizationService       →  Register/Schema
```

## Core Concepts

### Sources
A **Source** is a configured connection to an external system. It stores the base URL, authentication method, headers, certificates, and request defaults. Sources are reused across endpoints, synchronizations, and jobs.

### Endpoints
An **Endpoint** is a path exposed by OpenConnector that acts as a reverse proxy, OpenRegister gateway, or rule-execution surface. Endpoints have HTTP methods, target configuration, and an ordered list of Rules.

### Mappings
A **Mapping** defines a field-level transformation between source and target schemas. It uses direct assignments, Twig template expressions, dot-notation paths, and JSON Logic conditions to reshape data structures.

### Synchronizations
A **Synchronization** defines a full data flow: which Source to read from, which Mapping to apply, and which target (OpenRegister schema or another Source) to write to. The sync engine handles pagination, hash-based change detection, and per-object contract tracking.

### Rules
A **Rule** adds logic to an endpoint. Rules enforce authentication, trigger synchronizations, handle file uploads/downloads, control resource locking, expose audit trails, and can be conditionally applied via JSON Logic.

### Jobs
A **Job** schedules a synchronization or other task on a cron expression. Execution history is stored in job logs.

### Events and Consumers
OpenConnector emits and consumes **CloudEvents**. **Consumers** are configured handlers that process incoming webhook payloads. **EventSubscriptions** subscribe to specific event types and route them to handlers.

## Standards Compliance

| Standard | Role |
|----------|------|
| REST-API Design Rules (Logius) | API design for exposed endpoints |
| OpenAPI 3.0 | Configuration import/export format |
| NL GOV CloudEvents | Event emission and consumption |
| Digikoppeling | PKIoverheid mTLS for government connections |
| StUF-BG 3.10 / StUF-ZKN 3.10 | Legacy SOAP adapter (partial) |
| GEMMA Gemeentelijke servicebuscomponent | Primary architectural role |
| GEMMA Notificatierouteringcomponent | CloudEvents routing role |

## Data Model

| Entity | Purpose |
|--------|---------|
| Source | External API connection configuration |
| Endpoint | Exposed reverse-proxy route |
| Mapping | Field-level transformation definition |
| Synchronization | Source-to-target sync flow definition |
| SynchronizationContract | Per-object sync state (origin ID, target ID, hash) |
| Rule | Endpoint logic (auth, file, lock, audit) |
| Job | Scheduled task with cron expression |
| Consumer | Incoming webhook/event handler |
| Event | CloudEvent definition |
| EventSubscription | Event listener with handler config |
| CallLog | HTTP request/response audit log |
| SynchronizationLog | Per-sync run result log |

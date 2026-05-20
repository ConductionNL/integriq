# Infrastructure and domain data source connectors

## Summary

This change adds three infrastructure and domain-specific data source connectors to OpenConnector:

- **Vilans KICK** (demand 55, 1 tender mention): Care professional integration for bidirectional client data synchronisation between the Vilans KICK care management system and mydash.
- **InfluxDB** (demand 54, 1 tender mention): Time-series metrics data source for real-time dashboard visualisation using Flux or InfluxQL queries.
- **Dovecot Pro** (demand 54, 1 tender mention): Enterprise email storage and delivery management with automated mailbox provisioning and deprovisioning driven by Nextcloud user lifecycle events.

## Motivation

These three connectors appear in active Dutch tender requirements with a combined demand score of 163. Healthcare and IT infrastructure integrations are underserved in the current OpenConnector connector catalogue.

The **Vilans KICK** connector directly enables care professionals to access up-to-date client data from the KICK care management platform without manual re-entry. KICK is widely deployed across Dutch care organisations (thuiszorg, GGZ, gehandicaptenzorg) and its absence from the connector catalogue creates a manual data entry bottleneck for every organisation in that sector.

The **InfluxDB** connector is required for operational monitoring and real-time dashboarding. InfluxDB is the dominant time-series database in Dutch municipal and healthcare IT stacks (Prometheus + InfluxDB + Grafana is the standard observability stack). Without native query support, dashboard operators cannot surface live infrastructure metrics in mydash panels.

The **Dovecot Pro** connector eliminates manual mailbox management for organisations running Dovecot as their enterprise IMAP server. Provisioning and deprovisioning mailboxes currently requires direct Dovecot admin intervention; automating this via the existing Nextcloud user lifecycle eliminates a recurring operational overhead.

## Scope

- **KICKConnectorService**: Source configuration, authenticated client data polling, bidirectional sync via SynchronizationService, connection health monitoring, KickSyncLog audit records
- **InfluxDBConnectorService**: Source configuration, bucket/measurement discovery, Flux and InfluxQL query execution, structured error reporting, InfluxQueryConfig storage
- **DovecotConnectorService**: Source configuration, mailbox provisioning/suspension/deletion sync, idempotent reconciliation, DovecotMailboxSync records, connection health monitoring
- New OpenRegister schemas: `KickSyncLog`, `InfluxQueryConfig`, `DovecotMailboxSync`
- Seed data (3-5 objects per schema) in `openconnector_register.json`
- Background cron jobs: `KICKSyncJob`, `DovecotSyncJob`
- Unit tests for all three connector services
- Documentation for each connector in `docs/features/`

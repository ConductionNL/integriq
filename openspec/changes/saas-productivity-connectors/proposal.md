# SaaS Productivity and Work Management Connectors

## Summary

This change implements three integration features for OpenConnector: a Monday.com bidirectional sync connector, a list view integration for structured data display, and a DXP (Digital Experience Platform) integration-first connector. All features target the `openconnector` app and leverage existing OpenConnector infrastructure (Source, CallService, SynchronizationService, AuthenticationService, JobService).

## Motivation

Required by Dutch government and enterprise tenders demanding integration with external SaaS productivity tools. Monday.com integration scored highest in tender demand (55 mentions) and enables project managers to synchronise tasks and board items with OpenConnector-managed data without manual intervention. DXP integration (demand: 54) unifies enterprise content from external CMS/DXP platforms into a single dashboard. List view (demand: 54) complements existing card and dashboard views with an efficient tabular display for records comparison.

## Scope

- Monday.com API connector (bidirectional task/board sync via GraphQL API, OAuth2/API key auth, polling + webhook support)
- List view integration for OpenConnector dashboard pages (CnDataTable / useListView composable wiring)
- DXP endpoint connector (configurable sync interval, content retrieval via CallService, error notification)
- Configuration UI for Monday.com and DXP connections in OpenConnector admin settings
- Background jobs for polling-based sync (Monday.com board updates, DXP content changes)
- Token expiry detection and re-authentication notification for Monday.com

## Out of Scope

- Replacing OpenConnector's existing Pinia stores or mapping/rule engine (intentionally domain-specific per openconnector-adopt-or-abstractions)
- Custom dashboard layouts or chart components (use CnDashboardPage + CnChartWidget per ADR-001)
- Custom authentication flows beyond Monday.com OAuth2 and DXP API key/OAuth2 (use AuthenticationService)

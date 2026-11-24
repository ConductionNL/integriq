---
kind: code
---

# Proposal: connectors-endpoint-workspace-adapters

## Summary

Row `con-endpoint-workspace` promises that an administrator connects a device or workspace manager such as Intune, Jamf or Citrix. Integriq has the category contract (REQ-EWC-001 to REQ-EWC-006) and one reference adapter, Azure Virtual Desktop (`lib/Service/Adapter/EndpointWorkspace/AzureVirtualDesktopAdapter.php`). No route or page calls it, and no Intune, Jamf or Citrix adapter exists. REQ-EWC-007 asks for one change per adapter; this change carries the first three and the screen that lists sessions and devices.

## What changes

- Intune, Jamf Pro and Citrix DaaS adapters on `AbstractCategoryAdapterProvider`, each declaring its REQ-EWC-002 capabilities.
- A source detail tab that lists sessions or devices from a connected source.
- The AVD adapter becomes reachable through the same tab.

## Rows

| row | state |
|---|---|
| `con-endpoint-workspace` | specified |

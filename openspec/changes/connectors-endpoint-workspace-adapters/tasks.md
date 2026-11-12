# Tasks: connectors-endpoint-workspace-adapters

Kind: code. Size M. Row `integriq:con-endpoint-workspace`.

### Task 1: Intune, Jamf Pro and Citrix DaaS adapters
- **spec_ref**: `openspec/changes/connectors-endpoint-workspace-adapters/specs/endpoint-workspace-connectors/spec.md#requirement-an-administrator-sees-the-sessions-or-devices-of-a-connected-workspace-manager-req-ewc-008`
- **files**: `lib/Service/Adapter/EndpointWorkspace/IntuneAdapter.php`, `lib/Service/Adapter/EndpointWorkspace/JamfProAdapter.php`, `lib/Service/Adapter/EndpointWorkspace/CitrixDaasAdapter.php`, `lib/Service/ServiceBindings.php`, `lib/Service/CatalogRegistryService.php`
- [ ] Implement
- [ ] Test (PHPUnit against recorded vendor answers)

### Task 2: Sessions and devices tab on a source
- **spec_ref**: `openspec/changes/connectors-endpoint-workspace-adapters/specs/endpoint-workspace-connectors/spec.md#requirement-an-administrator-sees-the-sessions-or-devices-of-a-connected-workspace-manager-req-ewc-008`
- **files**: `lib/Controller/EndpointWorkspaceController.php`, `appinfo/routes.php`, `src/manifest.json` (source detail tab), `l10n/nl.json`, `l10n/en.json`
- [ ] Implement
- [ ] Test (PHPUnit for the route; Playwright for the tab)

## Verification
- [ ] `openspec validate connectors-endpoint-workspace-adapters --type change --strict` passes

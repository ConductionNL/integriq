# Tasks: connectors-endpoint-workspace-adapters

Kind: code. Size M. Row `integriq:con-endpoint-workspace`.

### Task 1: Intune, Jamf Pro and Citrix DaaS adapters
- **spec_ref**: `openspec/changes/archive/2026-11-24-connectors-endpoint-workspace-adapters/specs/endpoint-workspace-connectors/spec.md#requirement-an-administrator-sees-the-sessions-or-devices-of-a-connected-workspace-manager-req-ewc-008`
- **files**: `lib/Service/Adapter/EndpointWorkspace/IntuneAdapter.php`, `lib/Service/Adapter/EndpointWorkspace/JamfProAdapter.php`, `lib/Service/Adapter/EndpointWorkspace/CitrixDaasAdapter.php`, `lib/Service/ServiceBindings.php`, `lib/Service/CatalogRegistryService.php`
- [x] Implement
- [x] Test (PHPUnit against recorded vendor answers)

### Task 2: Sessions and devices tab on a source
- **spec_ref**: `openspec/changes/archive/2026-11-24-connectors-endpoint-workspace-adapters/specs/endpoint-workspace-connectors/spec.md#requirement-an-administrator-sees-the-sessions-or-devices-of-a-connected-workspace-manager-req-ewc-008`
- **files**: `lib/Controller/EndpointWorkspaceController.php`, `appinfo/routes.php`, `src/manifest.json` (source detail tab), `l10n/nl.json`, `l10n/en.json`
- [x] Implement
- [x] Test (PHPUnit for the route; Playwright for the tab)
  - PHPUnit for the route: `tests/Unit/Controller/EndpointWorkspaceControllerTest.php`. Playwright for the widgets: `tests/e2e/endpoint-workspace.spec.ts`, written 2026-11-24, runs in the nightly; not run here (no live instance), so the widgets are not verified yet. The two scenarios that list real devices and sessions need a vendor answering and are covered by PHPUnit against recorded vendor answers.

## Verification
- [x] `openspec validate connectors-endpoint-workspace-adapters --type change --strict` passes
  - OpenSpec 2.1.1, 2026-11-21: the change validates ("is valid") under `--strict`.

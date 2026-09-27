# Tasks: gateway-federated-api-discovery

Kind: code. Size M. Row `integriq:gw-federated`.

## Implementation tasks

### Task 1: The external_api schema and the inventory page
- **spec_ref**: `openspec/changes/gateway-federated-api-discovery/specs/federated-api-inventory/spec.md#requirement-apis-on-other-gateways-are-listed-with-integriqs-own-req-fedg-001`
- **files**: `lib/Settings/register.d/external-api-inventory.json`, `src/manifest.json` (page `ApiInventory`), `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN seeded products and two external APIs WHEN an administrator opens the inventory THEN all are listed with their gateway
- [ ] Implement
- [ ] Test (Playwright)

### Task 2: The Amazon connector waits for the broker's aws-sigv4 scheme
- **spec_ref**: `openspec/changes/gateway-federated-api-discovery/specs/federated-api-inventory/spec.md#requirement-each-vendor-is-read-on-a-schedule-req-fedg-002`
- **files**: `lib/Settings/register.d/aws-apigateway-discovery.json`, `lib/Service/SynchronizationService.php` (the refusal before a call), the inventory page status
- **acceptance_criteria**:
  - GIVEN a broker without aws-sigv4 WHEN the Amazon synchronization runs THEN it makes no call, logs that the broker cannot sign yet, and the inventory shows the connector as waiting on OpenRegister
- [ ] Implement
- [ ] Test (PHPUnit on the refusal; the OpenRegister follow-up for authScheme aws-sigv4 named in the PR)

### Task 3: Kong, Azure and AWS discovery fragments
- **spec_ref**: `openspec/changes/gateway-federated-api-discovery/specs/federated-api-inventory/spec.md#requirement-each-vendor-is-read-on-a-schedule-req-fedg-002`
- **files**: `lib/Settings/register.d/kong-gateway-discovery.json`, `azure-apim-discovery.json`, `aws-apigateway-discovery.json`
- **acceptance_criteria**:
  - GIVEN a recorded fixture per vendor WHEN its synchronization runs in test mode THEN the expected external_api objects result, and an API missing on the next run is marked no longer seen
- [ ] Implement
- [ ] Test (synchronization test runs against fixtures; one live run against a Kong container in the dev compose)

### Task 4: OpenAPI export and bring behind integriq
- **spec_ref**: `openspec/changes/gateway-federated-api-discovery/specs/federated-api-inventory/spec.md#requirement-a-discovered-api-can-be-brought-behind-integriq-req-fedg-003`
- **files**: the three fragments (export synchronizations), the inventory row action
- **acceptance_criteria**:
  - GIVEN a discovered API with a stored description WHEN the administrator chooses bring behind integriq THEN the import preview opens with its operations
- [ ] Implement
- [ ] Test (Playwright from the inventory row to the import preview)

## Verification
- [ ] `openspec validate gateway-federated-api-discovery --type change --strict` passes
- [ ] PHPUnit and Playwright run, exit codes read

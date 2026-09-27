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

### Task 2: SigV4 as a source authentication type
- **spec_ref**: `openspec/changes/gateway-federated-api-discovery/specs/federated-api-inventory/spec.md#requirement-each-vendor-is-read-on-a-schedule-req-fedg-002`
- **files**: `lib/Service/Auth/AwsSigV4Signer.php`, `lib/Service/Adapter/DataInfra/S3Adapter.php`, `lib/Service/CallService.php`
- **acceptance_criteria**:
  - GIVEN the AWS SigV4 test suite vectors WHEN signed THEN each signature matches, and S3Adapter's tests pass unchanged
- [ ] Implement
- [ ] Test (PHPUnit with the published AWS test vectors)

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

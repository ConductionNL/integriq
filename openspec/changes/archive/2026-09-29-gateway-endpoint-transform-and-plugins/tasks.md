# Tasks: gateway-endpoint-transform-and-plugins

Rows: `integriq:gw-transform`, `integriq:gw-plugins`.

## Implementation tasks

### Task 1: Apply the output mapping to the answer
- **spec_ref**: `openspec/changes/archive/2026-09-29-gateway-endpoint-transform-and-plugins/specs/endpoint-runtime/spec.md#requirement-an-endpoints-output-mapping-reshapes-its-answer-req-gtp-001`
- **files**: `lib/Service/EndpointService.php`
- [x] Implement (single object and list, after the `after` rules)
- [x] Test (red first: an endpoint with an output mapping answers the unmapped body today)

### Task 2: The rule plug-in point
- **spec_ref**: `openspec/changes/archive/2026-09-29-gateway-endpoint-transform-and-plugins/specs/rule-pipeline/spec.md#requirement-a-custom-rule-runs-a-registered-plug-in-req-gtp-002`
- **files**: `lib/Rule/Plugin/EndpointRulePluginInterface.php`, `lib/Rule/Plugin/EndpointRulePluginRegistry.php`, `lib/Service/RuleService.php`, `lib/AppInfo/Application.php`
- [x] Implement (`connectRelations` as the first plug-in; an unknown id named in the error)
- [x] Test (a registered test plug-in runs; an unknown id is refused; `connectRelations` unchanged)

### Task 3: A JavaScript rule is refused, not ignored
- **spec_ref**: `openspec/changes/archive/2026-09-29-gateway-endpoint-transform-and-plugins/specs/rule-pipeline/spec.md#requirement-a-javascript-rule-is-refused-req-gtp-003`
- **files**: `lib/Service/EndpointService.php`, `src/views/Rule/ruleDraft.js`, the rule schema's `type` enum, Dutch and English strings
- [x] Implement
- [x] Test (runtime refusal; the editor no longer offers it; a save through the objects API is refused by the schema)

## Verification

- [x] `openspec validate gateway-endpoint-transform-and-plugins --strict`
- [x] PHPUnit and vitest, exit codes read

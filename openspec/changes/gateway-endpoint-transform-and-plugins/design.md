# Design: gateway-endpoint-transform-and-plugins

## Context

`EndpointService::handleSchemaRequest()` resolves the endpoint, applies `inputMapping`, dispatches `before` rules, performs the register read or write, dispatches `after` rules on `$data['body']`, and builds the response. `outputMapping` is declared on the `endpoint` schema and carried through configuration export and import, but no line of the runtime reads it; `EndpointsController::isSimpleEndpoint()` only checks it is empty.

## D1. Output mapping runs last on the body

After the `after` rules, when `outputMapping` is set, the body is passed through `MappingService::executeMapping()` with the resolved mapping. Running it last means a rule sees the register's own shape, and the consumer sees the mapped one, which is what "the answer the consumer gets" means. A list answer maps each item and keeps the pagination envelope.

## D2. A plug-in is a PHP class a sibling app registers

`EndpointRulePluginInterface` has `id(): string` and `process(array $rule, array $data): array`. Implementations are registered through an `EndpointRulePluginRegistry` that collects tagged services at boot (the same pattern `ExpressionValueSourceRegistry` uses). A `custom` rule's `configuration.plugin` names the id; an unknown id throws "No rule plug-in 'x' is installed", naming the id. `connectRelations` moves behind the interface unchanged, so existing rules keep working.

## D3. JavaScript is refused, not stubbed

The rule editor stops offering `javascript`. The runtime throws for a `javascript` rule instead of returning the data unchanged, and saving one through the API is refused by a register-level `enum` on the rule's `type` that leaves `javascript` out. An existing JavaScript rule on an instance fails loudly the first time it runs, which is the correct news: it never did anything. This is a behaviour change for any instance carrying such a rule; the release note says so.

## Open question for the product owner

Whether integriq should ever run tenant scripts. This change assumes not (D3). If the answer changes, D3 is the only part to revisit.

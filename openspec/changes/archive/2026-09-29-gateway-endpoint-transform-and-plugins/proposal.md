# Proposal: gateway-endpoint-transform-and-plugins

## Summary

An endpoint's edit form offers an output mapping, and the rule pipeline offers a JavaScript rule. Neither does anything: the output mapping is never applied to an answer, and the JavaScript rule returns its input unchanged. This change applies the output mapping, and replaces the silent JavaScript stub with a plug-in point a sibling app can fill with PHP, while a JavaScript rule is refused at save instead of doing nothing.

## Why

Two matrix rows share the endpoint request pipeline (`lib/Service/EndpointService.php`) and the endpoint edit screen, so they are one change:

- `integriq:gw-transform`, "Reshape an endpoint's incoming request and outgoing answer with a mapping", rated `partial`, state `building`. Decided `build` (29 Sep 2026): five competitors `yes`, core area `gateway`.
- `integriq:gw-plugins`, "Add your own logic to the request pipeline with a plug-in or a script", rated `no`, state `building`. Decided `build`: all six competitors `yes`, core area `gateway`.

Competitor cells from the matrix:

- tyk `yes` (gw-transform): "apidef/oas/operation.go:42 transformRequestBody and :46 transformResponseBody run Go templates".
- mulesoft `yes` (gw-transform): "DataWeave Body Transformation policy 'Transforms the body of request or response traffic with a DataWeave script'".
- n8n `yes` (gw-plugins): "packages/nodes-base/nodes/Code/Code.node.ts:153 'language' JavaScript or Python lets any step run the builder's own script".
- tyk `yes` (gw-plugins): "plugin drivers otto (JavaScript), python, lua, grpc and goplugin".
- mulesoft `yes` (gw-plugins): "JavaScript Scripting 'Runs a user-supplied JavaScript module to inspect and modify requests and responses'".

## What integriq already has

- `handleSchemaRequest()` applies `inputMapping` to the request parameters (`EndpointService.php:1889`).
- A rule of type `mapping` at timing `after` can reshape `$data['body']` (`processMappingRule`, around :3104), so an output mapping is reachable today only as a separate rule.
- `processJavaScriptRule()` (:3905) is a stub: "@todo: Here we need to implement the JavaScript execution logic. For now, just return the data unchanged".
- `RuleService::processCustomRule()` knows one hard-coded custom type, `connectRelations`, and throws on anything else.

## What this change builds

1. `outputMapping` on an endpoint is applied to the answer body, after the `after` rules, with the same mapping engine `inputMapping` uses.
2. A plug-in point: a `custom` rule names a plug-in id, and integriq runs the `EndpointRulePluginInterface` implementation a sibling app registered under that id. Integriq ships no plug-in of its own beyond `connectRelations`, which becomes the first registered one.
3. A JavaScript rule is refused when it is saved and when it runs, with a message that says integriq runs no scripts and points at plug-ins and flows. A rule that silently does nothing is worse than no rule.

## Out of scope

A script engine inside integriq. Running tenant-written JavaScript or Python inside a Nextcloud PHP process needs a sandbox integriq does not have; flows (`flow-orchestration`) are the no-code route, and a plug-in is the code route.

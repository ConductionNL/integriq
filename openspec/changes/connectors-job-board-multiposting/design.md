# Design: connectors-job-board-multiposting

Kind: code. Size M. Read at integriq development `966d6458` on 2026-09-28.

## Context

- **Nodes.** `SourceCallNode::execute()` validates the config, resolves the flow owner and then the source once (`:410-413`) before `callForEachItem()`. `ApplyMappingNode::mapEachItem()` reads `$config['mapping']` once (`:310`). Per item, only endpoint and body are rendered (`SourceCallNode.php:563`, `:809`). Failed items become error items under `onError: continue` (`:498-502`).
- **The humaniq flow.** humaniq `hiring-multiposting` D1: `openregister.trigger-object` on `publiceren`, `openregister.iterate` over `channels`, per board `openconnector.apply-mapping` and `openconnector.source-call`, then `openregister.object-write` of the `VacancyPosting`. D2: the board code names the integriq source and mapping. D3: one `VacancyPosting` per vacancy and board.
- **Endpoints.** Configured endpoints are served on `/api/endpoint/{_path}` through `EndpointService::handleRequest()`.

## D1. Per-item references

`source` and `mapping` accept a template in the node's existing template syntax. When the value contains a template marker, the node renders it per item, resolves each distinct result once (a small per-run cache), and fails only that item with "no source configured: <name>" (or mapping) when it does not resolve. A plain value keeps today's behaviour exactly, so existing flows do not change.

Alternative considered: one step per board behind a switch node. Rejected: a new board would need a flow edit in humaniq, which its D2 rules out.

## D2. Three boards, push or feed

| Template | Direction | Credential |
|---|---|---|
| `jobboard-werk-nl` | push to the UWV employer vacancy service | UWV credential by `credentialRef` |
| `jobboard-linkedin` | push through LinkedIn's job posting API for partners | OAuth client by `credentialRef` |
| `jobboard-indeed` | feed pulled by Indeed | none; the feed URL is registered with Indeed |

A push template answers with the board's id and link, which the humaniq flow writes on the posting. The Indeed template is a feed: its `source-call` answers "published in feed" with the feed item's link, so the flow still records `geplaatst`.

## D3. The feed

An endpoint `/api/endpoint/jobfeeds/<board>.xml` lists the vacancies in humaniq's register whose `channels` include the board and whose status is published, rendered through the board's mapping in its feed format. It is anonymous (a public list of public vacancies, ADR-091 decision 5), throttled, and holds no applicant data.

## Declarative versus imperative

| Behaviour | Path | Rationale |
|---|---|---|
| Per-item reference | Imperative, in the nodes | Node behaviour. |
| Boards and mappings | Declarative seed | Connector configuration. |
| Feed | Declarative endpoint configuration | Served by the endpoint runtime. |

## Seed data

The three dormant templates and mappings; a recorded LinkedIn job posting answer and an Indeed feed rendering of humaniq's seeded vacancy as test fixtures.

## Risks

- [Board terms restrict automated posting] each template's description names the board's partner programme the administrator must be accepted into before going live.

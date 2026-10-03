---
kind: code
depends_on: []
---

# Proposal: connectors-job-board-multiposting

## Summary

A recruiter who publishes a vacancy in humaniq wants it on werk.nl, LinkedIn and Indeed at once. humaniq's flow for that is written and waits on integriq: there are no job board sources or mappings, and integriq's flow nodes pick one source and one mapping per step, while the flow iterates over boards. This change adds the three boards as templates with their mappings, lets the source call and apply mapping nodes resolve their source and mapping per item, and publishes a job feed for a board that pulls vacancies instead of taking a push.

## Why

The owner-moves pass of 2026-09-28 handed this half to integriq from humaniq `hiring-multiposting`, merged on humaniq `development`. It is `build` by the decision rule.

humaniq `hiring-multiposting`, Cross-app dependencies: "**integriq**: one source per job board (werk.nl, LinkedIn, Indeed to start), the credentials for each, and a mapping from a humaniq vacancy to each board's format. The flows call `openconnector.apply-mapping` and `openconnector.source-call`; humaniq holds no board URL, token or HTTP client." Its design D2: "The flow resolves each code to the integriq source and mapping of the same name." Its Open Questions: "Some boards pull an XML feed instead of accepting a push ... should integriq publish one?"

Row in the humaniq matrix: `hir-multiposting`, "Post a vacancy to several job boards in one go." Three competitors rate yes:

- Visma Raet: "publish vacancies from one platform to social media, jobboards and the werken-bij site" (https://youforce.nl/product/werving-en-selectie).
- HR2day: "post a vacancy on many job boards with one click", "connects with Indeed, LinkedIn and more" (https://www.hr2day.com/hire2day/sourcing/).
- Personio: multiposting through GoHiring (https://support.personio.de/hc/en-us/articles/115005416109-Promote-jobs-on-external-job-boards-via-multiposting).

## What integriq already has

- The flow nodes `openconnector.source-call` (`lib/Flow/SourceCallNode.php:97`) and `openconnector.apply-mapping` (`lib/Flow/ApplyMappingNode.php:88`), registered through `RegisterFlowNodesEvent` (`lib/AppInfo/Application.php:1051-1052`), from the open change `integriq-flow-nodes` (12 of 20 tasks).
- Both resolve their reference once per step: `resolveSource(trim($config['source']))` (`SourceCallNode.php:413`) and `$config['mapping']` (`ApplyMappingNode.php:310`); only the endpoint and body are templated per item.
- An `onError: continue` policy that emits an error item per failed item (`SourceCallNode.php:442`, `:498-502`).
- Endpoints served from configuration (`/api/endpoint/{_path}`, `appinfo/routes.php:430`).
- No job board source: a search for werk.nl, LinkedIn job posting and Indeed over `lib/` finds nothing.

## What this change builds

1. A per-item reference for both nodes: `source` and `mapping` may be a template over the item (for example `jobboard-{{ json.channel }}`), resolved and cached per distinct value.
2. Templates `jobboard-werk-nl`, `jobboard-linkedin` and `jobboard-indeed` with credentials by broker reference, and mappings `jobboard-werk-nl`, `jobboard-linkedin`, `jobboard-indeed` from humaniq's `Vacancy`.
3. A published vacancy feed per board that pulls, listing the vacancies whose `channels` include that board, for boards that take a feed instead of a push.

## Out of scope

- The flows, `VacancyPosting` and withdrawal on close (humaniq).
- Importing applications from boards, sponsored posts and budgets (humaniq's non-goals).

## Impact

- Changed: `lib/Flow/SourceCallNode.php`, `lib/Flow/ApplyMappingNode.php`, their node schemas and tests.
- New: three seed fragments, three mappings, a feed endpoint configuration per board.

## Cross-project dependencies

- humaniq `hiring-multiposting` uses the board codes `werk-nl`, `linkedin` and `indeed` as the per-item reference.

## Risks

- A templated reference that names a source the flow owner may not use. Resolution runs under the flow owner (`flowOwner->runAs`), so a source outside the owner's reach fails that item with a reason and the other boards still post.

# flow-nodes Specification

**Status**: planned
**Scope**: openconnector
**OpenSpec changes**:
- `openconnector-flow-nodes`

## Purpose

OpenConnector contributes node types to OpenRegister's flow engine so a flow can
make a **governed** outbound API call. The fleet has one flow engine (ADR-065);
apps do not run graphs, they contribute nodes to it. Today OpenConnector
contributes none, and none of OpenRegister's nine built-in nodes makes an HTTP
call — so "API calls go through OpenConnector nodes" describes a capability that
does not exist. This capability creates it: `openconnector.source-call` performs
one request per item through a configured Source and `CallService`, and
`openconnector.synchronization-run` runs a configured Synchronization as a step.
Governance is not re-implemented — it is inherited by delegating to
`CallService`, which already enforces source enablement, host, rate limiting and
brokered credentials. Per ADR-031 the node itself is the listed
external-integration exception to declarative-first; everything consuming it
stays a flow document.

@e2e exclude backend flow-node contribution and outbound call execution — no OpenConnector browser UI is added; palette visibility is rendered by OpenRegister's flow editor and covered there. Covered by PHPUnit plus a live flow run against the seeded demo Source.

## ADDED Requirements

### Requirement: OpenConnector contributes flow nodes to OpenRegister's flow engine

OpenConnector MUST register its node types on OpenRegister's
`RegisterFlowNodesEvent`, implementing
`OCA\OpenRegister\Service\Flow\IFlowNode` in full: `getId()`,
`getDisplayName()`, `getDescription()`, `getIcon()`, `isAvailableForScope()`,
`validateConfig()` and `execute()`.

Node type identifiers MUST be namespaced with the owning app —
`openconnector.source-call` and `openconnector.synchronization-run` — so the
registry's collision refusal is a boot-time error rather than a silent
displacement by load order.

Registration MUST be guarded so that OpenConnector still boots when
OpenRegister's flow engine classes are absent (an older OpenRegister, or one
installed without the flow engine). The guard MUST NOT be a caught-and-ignored
error at call time; it MUST prevent the compile-time reference from being
resolved at all.

Node metadata (`getDisplayName()`, `getDescription()`) MUST be translated
through `IL10N`, so Dutch and English are both available (ADR-007).

#### Scenario: Nodes appear in the flow palette

- GIVEN OpenRegister with its flow engine and OpenConnector are both installed and enabled
- WHEN OpenRegister builds its node palette by dispatching `RegisterFlowNodesEvent`
- THEN the palette contains a node with id `openconnector.source-call`
- AND the palette contains a node with id `openconnector.synchronization-run`
- AND each carries a non-empty display name, description and icon URL

#### Scenario: OpenConnector boots without OpenRegister's flow engine

- GIVEN an instance where `OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent` does not exist
- WHEN OpenConnector's `Application::register()` runs
- THEN OpenConnector boots without error
- AND no flow-node listener is registered
- AND no fatal error is written to the log

#### Scenario: Duplicate node id is refused at registration

- GIVEN another app has already registered a node whose id is `openconnector.source-call`
- WHEN OpenConnector registers its node on the registry
- THEN registration is refused with an error naming the colliding id
- AND neither node is silently replaced by the other

### Requirement: The source-call node targets a configured Source, never a raw URL

The `openconnector.source-call` node's configuration MUST identify its target by
a **Source reference** (`config.source`, accepting a Source UUID, slug or
`reference`) plus an **endpoint path relative to that Source's `location`**
(`config.endpoint`). The configuration MUST NOT accept an absolute URL, a host,
a scheme, or a port.

The node MUST resolve the Source as an OpenRegister object in register
`openconnector`, schema `source`, and MUST pass the resolved `ObjectEntity` to
`CallService::call()` so the Source's own preconditions — `isEnabled`, location
guard and rate limiting — are enforced by the existing call engine rather than
re-implemented.

The node MUST NOT create a Source. A `config.source` that resolves to no Source
is an error, never a find-or-create.

The effective endpoint MUST remain within the Source's `location`. An endpoint
that is absolute (`https://…`), scheme-relative (`//host`), or that escapes via
path traversal (`../`) MUST be rejected. Because an endpoint may contain
`{{dotted.path}}` placeholders, this check MUST be applied to the **rendered**
endpoint at execute time as well as to the literal value at validation time.

#### Scenario: Node calls the configured Source

- GIVEN a Source `demo-echo-api` with `location` `https://echo.example.org` and `isEnabled` true
- AND a step configured with `source: "demo-echo-api"`, `endpoint: "/get"`, `method: "GET"`
- WHEN the node executes with one input item
- THEN `CallService::call()` is invoked with the resolved Source object, endpoint `/get` and method `GET`
- AND no HTTP client is invoked directly by the node

#### Scenario: Disabled Source is not called

- GIVEN a Source `demo-forge-api` whose `isEnabled` is false
- AND a step configured against that Source
- WHEN the node executes
- THEN the call engine's precondition guard refuses the call
- AND the node surfaces the refusal as an error, not as a successful empty result

#### Scenario: Unknown Source reference is an error

- GIVEN a step configured with `source: "no-such-source"`
- WHEN the node executes
- THEN the node raises an error naming the unresolvable Source reference
- AND no Source is created
- AND no outbound request is made

#### Scenario: Endpoint cannot escape the Source location

- GIVEN a step configured with `source: "demo-echo-api"` and `endpoint: "https://evil.example.org/steal"`
- WHEN the configuration is validated
- THEN validation fails with an error stating the endpoint must be relative to the Source
- AND GIVEN instead `endpoint: "/issues/{{issue.ref}}"` where the item renders `issue.ref` to `../../evil`
- WHEN the node executes
- THEN the rendered endpoint is rejected before any request is made

### Requirement: The node executes once per item and returns well-formed items

The node MUST treat the flow data channel as a **list of items** per
`OCA\OpenRegister\Service\Flow\FlowItems`: it performs one call per input item
and returns one output item per input item, each shaped
`{json, binary, pairedItem}` with `pairedItem` identifying the input item that
produced it.

Request configuration values — `endpoint`, `query`, `body`, `headers` — MUST
support `{{dotted.path}}` placeholders resolved against the current item's
`json`. A placeholder whose path is absent MUST NOT silently render as the
literal placeholder text; it renders as empty or raises, and which of the two
MUST be deterministic and documented.

The response MUST be written onto the output item under author-named keys
(`config.output` and/or `config.responseMapping`). The node MUST NOT overwrite
the reserved `FlowItems` keys (`pairedItem`, `binary`, `output`) with response
content, so a remote server cannot alter an item's provenance or routing.

An empty input list MUST produce an empty output list and MUST NOT make a call.

#### Scenario: Three items produce three calls and three items

- GIVEN a step configured against a reachable Source
- AND an input list of three items
- WHEN the node executes
- THEN three calls are made, one per item
- AND three output items are returned
- AND each output item's `pairedItem` refers to the input item it came from

#### Scenario: Templated values are resolved from the item

- GIVEN an input item whose `json` is `{"issue": {"number": 42}}`
- AND a step with `endpoint: "/issues/{{issue.number}}/labels"` and `body: {"labels": ["{{triage.proposedLabel}}"]}` where `json.triage.proposedLabel` is `needs-triage`
- WHEN the node executes
- THEN the request path is `/issues/42/labels`
- AND the request body is `{"labels": ["needs-triage"]}`

#### Scenario: Response lands under the author-named key only

- GIVEN a step with `output: "labelResult"`
- AND the Source returns `{"status": "ok", "pairedItem": "spoofed"}`
- WHEN the node executes
- THEN the output item's `json.labelResult` carries the response
- AND the item's `pairedItem` is the engine-assigned provenance, not `spoofed`

#### Scenario: Empty input makes no call

- GIVEN an empty input item list
- WHEN the node executes
- THEN no call is made
- AND an empty item list is returned

### Requirement: A failed call is explicit and never a silent empty success

The node MUST NOT swallow a call failure into an empty or default value. A
failure MUST either raise — so `FlowEngine` applies the step's `onError` policy
(`stop`, `continue`, `dead_letter`) — or, where the policy is `continue`, be
carried on the item as explicit error state that is structurally distinguishable
from a successful result.

Error state carried on an item MUST identify at minimum: that the step failed,
the HTTP status (when there was one), a human-readable message, and the Source
and endpoint involved.

A response whose HTTP status is outside the 2xx range MUST be treated as a
failure by default. An author MAY opt specific statuses in through
`config.acceptStatuses`; an accepted non-2xx response is a success whose status
is available to downstream steps.

A transport-level failure (DNS, TLS, timeout, connection refused) MUST be
treated identically to a failed call — never as an empty response body.

This requirement exists because the fleet's existing contributed node,
`HermiqAgentNode`, catches `Throwable` and substitutes an empty string, making a
failed turn indistinguishable from an empty answer and reporting the run as
successful. That behaviour MUST NOT be reproduced, and a regression test MUST
assert it is not.

#### Scenario: A 500 response stops the run under the default policy

- GIVEN a step with no explicit `onError` and a Source returning HTTP 500
- WHEN the node executes
- THEN the node raises
- AND the run's status is `stopped`
- AND the run log records the status code, message, Source and endpoint

#### Scenario: A failure under `continue` produces distinguishable error state

- GIVEN a step with `onError: "continue"` and a Source returning HTTP 500
- WHEN the node executes with two items, the first failing and the second succeeding
- THEN the run continues
- AND the first output item carries explicit error state naming the status, Source and endpoint
- AND the first output item is NOT shaped like a successful result
- AND the second output item carries the successful response

#### Scenario: A non-2xx status opted in by the author is a success

- GIVEN a step with `acceptStatuses: [200, 404]` and a Source returning HTTP 404
- WHEN the node executes
- THEN the node does not raise
- AND the output item records the 404 status for a downstream step to branch on

#### Scenario: A timeout is not an empty response

- GIVEN a Source whose host does not respond within the configured timeout
- WHEN the node executes
- THEN the node reports a transport failure
- AND the output does not contain an empty-but-successful response body

### Requirement: Calls are attributed to the flow run owner and fail closed

Every call MUST execute in the identity context of the flow run's owner, taken
from the run context (`context['triggeredBy']`).

When the run has no resolvable owner — the context value is absent or empty, or
it names a user that cannot be resolved — the node MUST refuse to execute and
MUST raise an error stating that the run is unattributed. It MUST NOT fall back
to an administrator, to the Source's creator, to the last known user, or to no
user at all.

Node configuration MUST NOT provide an owner override. Allowing a flow author to
name the identity a call runs as would be an authoring-time privilege
escalation.

Agent-dispatched flow runs are currently unattributed upstream
(ConductionNL/openregister#2158). This requirement makes that gap manifest as a
loud, diagnosable refusal rather than an anonymous authenticated outbound call.

#### Scenario: An attributed run calls as its owner

- GIVEN a flow run whose context carries `triggeredBy` naming an existing user
- WHEN the node executes
- THEN the call is performed in that user's context
- AND the call log records that user

#### Scenario: An unattributed run refuses to call

- GIVEN a flow run whose context has no `triggeredBy` value
- WHEN the node executes
- THEN the node raises an error stating the run has no resolvable owner
- AND no outbound request is made
- AND no fallback identity is used

#### Scenario: An unknown user refuses to call

- GIVEN a flow run whose context carries `triggeredBy` naming a user that no longer exists
- WHEN the node executes
- THEN the node raises an error naming the unresolvable user
- AND no outbound request is made

#### Scenario: Owner cannot be overridden from node config

- GIVEN a step whose configuration attempts to set an owner or user field
- WHEN the configuration is validated
- THEN the field is rejected or ignored
- AND execution still uses the run's `triggeredBy` owner

### Requirement: Credentials come only from the Source's broker reference

The node MUST NOT accept, store, render, log or otherwise handle a secret.
Its configuration MUST have no token, password, API-key, bearer, or
header-authentication field.

Authentication for a call MUST come solely from the Source's own configuration —
including `authentication.credentialRef` resolved through the OpenRegister
credential broker by the existing brokered-call path. The node MUST NOT bypass,
duplicate, or provide a fallback for that resolution.

When brokered credential resolution fails, the node MUST surface the failure per
the error requirement above. It MUST NOT fall back to an unauthenticated call.

Node configuration is stored in a flow document, which is readable by every flow
author; a Source and its credential are administrator-controlled. Placing a
secret in node configuration would therefore widen its exposure by construction.

#### Scenario: Brokered credential is used without the node seeing it

- GIVEN a Source with `authentication.credentialRef` naming a broker credential
- WHEN the node executes a step against that Source
- THEN the call is authenticated through the broker-resolved credential
- AND no secret value appears in the node's configuration, its log output, or the item

#### Scenario: A secret in node config is rejected

- GIVEN a step whose configuration contains a token, password or API key field
- WHEN the configuration is validated
- THEN validation fails with an error stating credentials belong on the Source

#### Scenario: Unresolvable credential does not become an anonymous call

- GIVEN a Source whose `credentialRef` cannot be resolved by the broker
- WHEN the node executes
- THEN the node surfaces the credential failure as an error
- AND no unauthenticated request is made

### Requirement: Configuration is validated when the flow is saved

`validateConfig()` MUST reject a configuration the author cannot have meant, at
flow-save time rather than at run time, by throwing
`\UnexpectedValueException` with a message naming the offending field.

It MUST reject at minimum: a missing or blank `source`; a missing or blank
`endpoint`; an HTTP method outside the supported set; an `acceptStatuses` that
is not a list of integers; an absolute or scheme-relative `endpoint`; and any
credential-bearing field.

Validation messages MUST be translated through `IL10N`.

`isAvailableForScope()` MUST answer using Nextcloud's own
`OCP\WorkflowEngine\IManager::SCOPE_ADMIN` / `SCOPE_USER` constants, per
OpenRegister's convention, and MUST return false for any other value.

#### Scenario: A step naming no Source is rejected at save

- GIVEN a flow containing a `openconnector.source-call` step with no `source`
- WHEN the flow is saved
- THEN saving fails with an `UnexpectedValueException` naming the `source` field
- AND the flow is not persisted in that state

#### Scenario: A step naming no endpoint is rejected at save

- GIVEN a flow containing a step with a valid `source` but a blank `endpoint`
- WHEN the flow is saved
- THEN saving fails with an error naming the `endpoint` field

#### Scenario: Scope is answered with Nextcloud's constants

- GIVEN the node
- WHEN `isAvailableForScope()` is asked for `IManager::SCOPE_ADMIN` and for `IManager::SCOPE_USER`
- THEN it answers per the node's declared availability for each
- AND it returns false for an unrecognised scope value

### Requirement: A synchronization-run node runs a configured synchronization as a step

OpenConnector MUST also contribute `openconnector.synchronization-run`, a node
that runs an already-configured Synchronization as one flow step, so a flow
author does not re-implement pagination, mapping or contract tracking as a chain
of individual call steps.

Its configuration MUST identify the Synchronization by reference
(`config.synchronization`), MUST NOT accept an inline synchronization
definition, and MUST NOT create a Synchronization.

It MUST obey the same attribution, error and validation requirements as
`openconnector.source-call`: fail-closed owner resolution, explicit failure that
honours `onError`, no credential fields, and save-time validation.

Its output shape is normative and is stated as its own requirement below: one
item per synchronised object, never a summary collapse.

#### Scenario: The node runs a configured synchronization

- GIVEN a configured Synchronization and a step naming it
- AND a run whose owner resolves
- WHEN the node executes
- THEN the synchronization is run through the existing synchronization service
- AND the objects it processed are emitted as flow items per the fan-out requirement below

#### Scenario: A failed synchronization honours onError

- GIVEN a step with `onError: "dead_letter"` naming a synchronization that fails
- WHEN the node executes
- THEN the node raises
- AND the run is dead-lettered rather than reported successful

#### Scenario: An unattributed run refuses to synchronize

- GIVEN a flow run with no resolvable `triggeredBy` owner
- WHEN the node executes
- THEN the node raises an unattributed-run error
- AND no synchronization is started

### Requirement: The synchronization-run node emits one item per synchronised object

`openconnector.synchronization-run` MUST emit one output item per object the
synchronisation processed — a full fan-out — and MUST NOT collapse a run into a
single summary item. Each emitted item MUST carry, under the author-named
`config.output` key, that object's payload and its per-object outcome (at
minimum the object identifier and whether it was created, updated, unchanged or
failed), and MUST carry a `pairedItem` referring to the input item whose
execution produced the run.

The run summary MUST remain available rather than being lost to the fan-out:
every emitted item MUST carry the run's counts under `<output>.summary`, so a
downstream step can branch on totals without a second lookup.

A synchronisation that processed zero objects MUST emit exactly one
summary-only item, marked as such (`<output>.summaryOnly` true) and carrying
zero counts. Emitting nothing would make "the synchronisation ran and found
nothing" indistinguishable from "the step never ran" — the green-but-dead
failure mode this specification forbids elsewhere.

Fan-out MUST be bounded by an explicit ceiling, `config.maxItems`, defaulting to
1000. When a run processed more objects than the ceiling, the node MUST raise —
naming the actual object count, the ceiling, the step id and the synchronization
— so `FlowEngine`'s `onError` policy decides what happens. It MUST NOT truncate,
sample, page or emit a partial list under any circumstance: a silently truncated
list looks complete to every downstream step and is the worst available
outcome. An author who wants a larger fan-out raises `maxItems` explicitly, so
the number is written in the flow document and is reviewable.

The ceiling bounds the **flow fan-out only**. The synchronisation itself has
already run and its objects are already written by the time the ceiling is
tested, so the error message MUST state that the objects were synchronised and
only their emission as flow items was refused.

When the emitted item count exceeds a documented warning threshold — 250 items,
below the default ceiling — the node MUST log a warning naming the count, the
step id and the synchronization, so a run growing toward the ceiling is visible
before it begins failing.

#### Scenario: A synchronisation of three objects produces three items

- GIVEN a Synchronization that processes three objects
- AND a step naming it with `output: "syncResult"` and one input item
- WHEN the node executes
- THEN three output items are returned, one per synchronised object
- AND each carries that object's payload and per-object outcome under `json.syncResult`
- AND each carries the run counts under `json.syncResult.summary`
- AND each output item's `pairedItem` refers to the single input item

#### Scenario: A synchronisation of zero objects still emits a visible result

- GIVEN a Synchronization that processes zero objects
- WHEN the node executes
- THEN exactly one output item is returned
- AND it is marked `summaryOnly` with zero counts
- AND it is not shaped like a synchronised object

#### Scenario: Exceeding the fan-out ceiling fails loudly and never truncates

- GIVEN a step with the default `maxItems` of 1000
- AND a Synchronization that processes 10000 objects
- WHEN the node executes
- THEN the node raises, naming the count 10000, the ceiling 1000, the step id and the synchronization
- AND no truncated or sampled subset of items is returned
- AND the error states that the objects were synchronised and only their emission was refused

#### Scenario: A raised ceiling is explicit in the flow document

- GIVEN a step with `maxItems: 20000`
- AND a Synchronization that processes 10000 objects
- WHEN the node executes
- THEN 10000 output items are returned
- AND a warning naming the item count, step id and synchronization was logged

### Requirement: A rate-limited synchronization suspends the run instead of ending it

When the source refuses the synchronization with `TooManyRequestsHttpException`, the
synchronization-run node MUST raise a `FlowSuspension` rather than let the run finish. The
engine does not advance the marking for a suspended step, so on resume this node runs again
while the steps that already completed do not — which is what makes starvation impossible
rather than merely less likely.

This is the OpenConnector-side use of OpenRegister's "a node MUST be able to resume from where
it stopped" engine requirement (`openregister/openspec/specs/flow-engine/spec.md`). That
requirement lives in OpenRegister's repository and cannot be named by a repository-relative
`@spec` path from here; this requirement is its consumer-side counterpart and is what
OpenConnector's own code is annotated against.

The mechanism matters more than it looks. `checkRateLimit()` throws BEFORE the first request
of a synchronisation, so a refused shard never starts and has no page to resume from — the
engine's per-synchronisation `currentPage` was never the missing piece. What was missing is
that the run ENDED. Measured 2026-08-13 on a twelve-shard publiccode crawl: the first three
shards spent the whole `code_search` budget, the remaining nine were refused at entry, and the
run reported success. Re-running did not catch up; the completed shards ran again, spent the
budget again, and the same nine starved. Three runs 65 s apart each returned the same 641
repositories.

The suspension's `resumeAt` MUST come from the source's own `X-RateLimit-Reset`, which
`CallService::sourceRateLimit()` already reads and stores, and MUST be clamped at both ends
against a header OpenConnector does not control: a reset already in the past MUST be floored to
a real wait rather than making the run due immediately and spinning against a source that is
still refusing it, and an absurd reset — the shape an epoch/milliseconds mix-up takes — MUST be
capped rather than parking the run effectively forever. A source that reports no usable reset
MUST still get a wake-up time: a suspension with no `resumeAt` waits for a signal nothing sends.

The suspension's reason MUST name both the rate limit and the synchronization. "Suspended" with
no cause is the thing an operator cannot act on, and a crawl that is rate limited looks
identical to one that is merely slow.

#### Scenario: The source's own reset time is honoured

- GIVEN a refusal carrying `X-RateLimit-Reset` some minutes out
- WHEN the node turns it into a suspension
- THEN `resumeAt` is that reset time
- AND the run is not ended

#### Scenario: A reset in the past is floored rather than spun on

- GIVEN a refusal whose `X-RateLimit-Reset` is already in the past
- WHEN the node turns it into a suspension
- THEN `resumeAt` is at least the minimum wait, not "now"

#### Scenario: A source with no reset still gets a wake-up time

- GIVEN a refusal carrying no usable `X-RateLimit-Reset`
- WHEN the node turns it into a suspension
- THEN `resumeAt` is the minimum wait rather than absent

#### Scenario: An absurd reset is capped

- GIVEN a refusal whose reset reads tens of thousands of years out
- WHEN the node turns it into a suspension
- THEN `resumeAt` is no further out than the maximum wait

#### Scenario: The suspension says why it is waiting

- GIVEN any rate-limit refusal
- WHEN the node turns it into a suspension
- THEN the suspension's reason names the rate limit and the synchronization reference

## Non-Functional Requirements

- **Performance:** the node adds no measurable overhead beyond the outbound call
  itself — resolution, templating and mapping per item MUST stay under 5 ms for
  an item of ordinary size, so end-to-end step time is dominated by the remote
  host. One call per item is the accepted cost of the item model; throughput is
  bounded by the Source's own rate limiting and by `FlowEngine`'s transition
  ceiling, and neither is bypassed. `openconnector.synchronization-run`'s
  fan-out is bounded by `config.maxItems` (default 1000): memory held by the
  emitted list is therefore bounded by an author-visible number rather than by
  the remote system's page count.
- **Accessibility:** no OpenConnector UI is added by this change, so there is no
  new interactive surface to audit. The node's display name, description and
  icon MUST be meaningful when rendered in OpenRegister's flow palette; the icon
  MUST NOT be the sole carrier of the node's meaning (WCAG 2.1 AA 1.1.1), which
  the required non-empty display name and description satisfy.
- **Internationalization:** Dutch and English MUST be supported (hydra ADR-007).
  Display names, descriptions and validation messages MUST go through `IL10N`
  and MUST NOT be hardcoded English strings.
- **Security:** no secret may appear in node configuration, in run logs, or on a
  flow item. No outbound call may be made without a resolved owner. No call may
  target a host that is not a configured Source's `location`.
- **Observability:** every call made by a node MUST produce the same CallLog the
  call engine already writes, so a flow-originated call is auditable by exactly
  the means an ordinary OpenConnector call is.

## Acceptance Criteria

- [ ] `openconnector.source-call` and `openconnector.synchronization-run` appear in OpenRegister's flow palette when both apps are enabled
- [ ] OpenConnector boots cleanly on an instance whose OpenRegister has no flow engine
- [ ] A seeded demo flow runs end to end and puts a real response on the item
- [ ] The node calls a configured Source and passes the resolved Source object to `CallService::call()`
- [ ] A configuration containing an absolute URL as `endpoint` is rejected at save
- [ ] A rendered endpoint that escapes the Source location is rejected before any request
- [ ] Three input items produce three calls, three output items, and correct `pairedItem` on each
- [ ] `{{dotted.path}}` placeholders in endpoint, query, body and headers resolve from the item's `json`
- [ ] A 500 response with the default policy stops the run and logs status, Source and endpoint
- [ ] A 500 response under `onError: continue` yields an item carrying explicit error state, structurally distinct from a success
- [ ] A regression test asserts a failed call never produces a success-shaped empty result (the HermiqAgentNode flaw)
- [ ] A status listed in `acceptStatuses` is treated as a success
- [ ] A transport timeout is reported as a failure, not an empty body
- [ ] A run with no resolvable `triggeredBy` refuses to execute and makes no request
- [ ] A run naming a non-existent user refuses to execute
- [ ] No owner override is accepted from node configuration
- [ ] A Source with `credentialRef` authenticates through the broker with no secret in node config, logs, or item
- [ ] A credential-bearing field in node config is rejected at save
- [ ] `validateConfig()` rejects missing `source`, missing `endpoint`, bad method, and malformed `acceptStatuses`
- [ ] `isAvailableForScope()` uses `IManager::SCOPE_ADMIN` / `SCOPE_USER` and rejects other values
- [ ] Every flow-originated call writes a CallLog
- [ ] Display names, descriptions and validation messages are translated (NL + EN)
- [ ] Registering a colliding node id fails loudly rather than displacing a node
- [ ] `openconnector.synchronization-run` emits one item per synchronised object, with per-object outcome and the run summary on each
- [ ] A zero-object synchronisation emits exactly one summary-only item rather than nothing
- [ ] A run exceeding `config.maxItems` raises naming count and ceiling, returns no truncated list, and says the objects were nevertheless synchronised
- [ ] A fan-out above the 250-item warning threshold logs a warning naming count, step id and synchronization
- [ ] `contract.md` is accepted by hermiq (`hydra-console-agent-leaves`) before implementation starts

## Notes

- **Reference implementation:** `hermiq/lib/Flow/HermiqAgentNode.php` and
  `HermiqFlowNodeListener.php` are the shape to follow for registration and
  metadata — but explicitly **not** for error handling or owner resolution.
  Its `catch (Throwable) { $answer = ''; }` and its
  `$config['owner'] ?? $context['triggeredBy']` fallback are both the failure
  modes this spec forbids.
- **Upstream contract:** `openregister/lib/Service/Flow/` —
  `IFlowNode`, `RegisterFlowNodesEvent`, `FlowNodeRegistry`, `FlowItems`,
  `FlowEngine` (`ON_ERROR_STOP` / `ON_ERROR_CONTINUE` / `ON_ERROR_DEAD_LETTER`),
  `FlowRunService`.
- **Upstream dependency:** ConductionNL/openregister#2158 — agent-dispatched
  runs are unattributed. Until it lands, agent-triggered flows using these nodes
  fail closed by design.
- **Downstream bound on a fanned-out run:** the sibling change
  `or-flow-object-write-node` (openregister repo) carries a PO-mandated per-step
  write cap. That cap — not this node — is what bounds what a large fan-out can
  *do* once it reaches a write step. This node bounds how many items it hands
  onward (`config.maxItems`); the write node bounds how many writes one step
  performs. The two limits are independent and both are required: without the
  write cap a raised `maxItems` becomes a write amplifier, and without
  `maxItems` a synchronisation's page count decides a flow's size.
- **Cross-project interface:** the node contract consumed by other apps — node
  ids, per-node config schema, item in/out shapes, error semantics, versioning —
  is written in `contract.md` in this change directory. hermiq
  (`hydra-console-agent-leaves`) is the named first consumer.
- **ADR-011:** HTTP transport, Twig rendering of source config, retry,
  rate-limit handling, credential resolution and call logging are **not**
  re-implemented; they are reached through `CallService` / `BrokeredCallService`.
- **ADR-022:** Sources, Synchronizations and CallLogs remain OpenRegister
  objects; no app-local persistence is introduced.
- **ADR-031:** the nodes are the listed external-integration exception to
  declarative-first; consumers of the nodes stay declarative flow documents.
  Rationale in `design.md`.
- **Checkout drift:** the working copy is `0.2.19` while the installed app
  reports `0.3.3`. Every requirement above is stated against a contract, not a
  line number; implementers MUST re-verify signatures against the deployed tree.

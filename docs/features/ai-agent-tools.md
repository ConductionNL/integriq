<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

# AI agent tools

A Hermiq agent can help you triage a failed night of synchronizations. It reads the logs, lists what got stuck, and proposes a fix. Nothing that writes runs until a person approves it in Hermiq.

This page is for the administrator who decides what an agent may do in Integriq.

## What an agent can do

An agent runs what you configured. It never changes what you configured.

| Tool | Scope | Reach | Gate | Action it checks |
|---|---|---|---|---|
| `runSynchronization` | update | external | approval | `synchronization.run` |
| `testSynchronization` | read | external | confirm | `synchronization.test` |
| `testSource` | read | external | confirm | `source.test` |
| `replayDeadLetters` | update | external | approval, per batch | `sync-dead-letter.replay` |
| `discardDeadLetters` | delete | instance | approval, per batch | `sync-dead-letter.discard` |
| `listDeadLetters` | read | instance | none | none: it reads under your own register rights |

- **Reach `external`** means the tool calls a system outside your Nextcloud. Running a synchronization or testing a source always does.
- **Reach `instance`** means the tool only touches records in this Nextcloud.
- **The action** is a row in the action authorization matrix. Every action ships for the `admin` group only. Change it on the *Action authorization* page.

Two checks run on every call, and neither can open what the other closes:

1. The action authorization matrix in Integriq.
2. The agent's grant and the approval in Hermiq.

## How an approval works

The three tools that change something take two calls.

1. **The agent proposes.** Integriq checks the ids and the action, then stages the batch. Nothing runs yet. The proposal waits 24 hours.
2. **A person approves in Hermiq.** The approver must be someone other than the agent.
3. **The agent runs the batch.** Integriq asks Hermiq for a signed verdict on that exact batch. It runs only when the signature checks out, the verdict is fresh, and the approver is a person.

One approval runs one batch once. A batch holds at most 100 ids.

Hermiq absent, a verdict signed by another key, a verdict replayed for a second run: each one is a refusal, and nothing runs.

## What the agent never sees

The agent gets the facts about a dead letter, never its contents.

`listDeadLetters` answers per row: the id, the store (`sync` or `event`), the synchronization or subscription, the phase, the error (cut to 200 characters), the attempts, the status and the dates. It never returns the payload. Replay and discard take ids only.

The payload is upstream data. Text in it could steer the agent. So the person who approves reviews the payloads on the *Dead letters* page, and the agent never does.

## What an agent may not do

Refused, because each one changes what you configured:

| Refused | Why |
|---|---|
| Create, edit or delete a source, mapping, synchronization, endpoint or job | One injected prompt would rewire your integrations. |
| Switch a job on or off | It changes your schedule from then on. Every allowed tool runs configuration once and leaves it as it was. |
| Pass `forceDeletion` to a run | The deletion guard stops a broken fetch from removing your data. No agent bypasses it. |
| Read a payload | See the section above. |

Deferred. Each one needs its own risk argument before an agent gets it:

| Deferred | Why it waits |
|---|---|
| Reset a synchronization's cursor | The next run fetches everything again. |
| Trip or reset a circuit breaker | The breaker protects the store on the other side. |
| Activate, deactivate or run a contract | It changes which records a synchronization owns. |
| Manage event subscriptions | It changes who receives your events. |
| Promote an environment or import a configuration | It replaces configuration wholesale. |

## Three conversations to try

**Last night's sync failed.** Ask: *"Why did last night's sync fail? Replay the dead letters."* The agent reads the synchronization and call logs, lists the pending dead letters, and explains the pattern, for example a run of 429 answers. It proposes a replay of the stuck ids. You review the payloads on the *Dead letters* page and approve in Hermiq. The replay runs, and the trail names both the agent and you.

**The supplier says it is fixed.** Ask: *"The supplier says they fixed their API. Check it."* The agent runs `testSource` and reports the status and the timing. No configuration changes.

**Drop the junk.** Ask: *"These three dead letters are malformed spam. Drop them."* The agent proposes a discard of the three ids. You approve. They move to their final discarded state, and the discard is in the audit trail.

## Where to find the trail

Every agent call writes one `agent_action` record: the tool, the agent, the user who granted it, the outcome, the reason and the ids. For a gated call it also names the batch, the approval and the approver. A replay you start from the *Dead letters* page writes none, so every entry means an agent was involved.

Next: give an agent its first grant in Hermiq, and keep `runSynchronization`, `replayDeadLetters` and `discardDeadLetters` on approval.

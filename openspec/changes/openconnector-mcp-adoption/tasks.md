# Tasks: openconnector-mcp-adoption (kind: config)

Scope note: **config only**. No PHP, no provider, no `#[McpTool]`, no seed data, no write verb.
The single deliverable is a `configuration["x-openregister-mcp"]` block on 8 schemas in
`lib/Settings/openconnector_register.json`. The curation table (design.md D1) and the OFF list
(design.md D2) are normative — do not add a schema that is not in the table, and never add one
from the credential-store row.

## 1. Declare the read-only dialect

- [ ] 1.1 Add `configuration["x-openregister-mcp"]` to the **8** schemas in design.md D1
      (`endpoint`, `mapping`, `synchronization`, `synchronization_contract`, `call_log`, `job`,
      `job_log`, `synchronization_log`) in `lib/Settings/openconnector_register.json`. Union the
      key into any existing `configuration` block; do not replace it.
- [ ] 1.2 For each: `enabled: true`; `tools` containing **only** `search` and `get`; each verb
      with `scope: "read"`, `readOnlyHint: true`, and the agent-facing `description` prose from
      design.md D1 (say what it returns and when to reach for it — this is what the LLM reads).
- [ ] 1.3 Add `search.filters` exactly as listed in the design.md D1 table. Every entry MUST be a
      real property of that schema — an unknown filter fails the whole register import.
- [ ] 1.4 Confirm **no** `create`/`update`/`delete` key, no `destructiveHint`, and no write
      `scope` appears anywhere in the file (REQ-MCP-103).
- [ ] 1.5 Confirm **no** dialect block was added to any credential-bearing schema — `source`,
      `consumer`, `rule`, `event_subscription`, `lti_platform`, `lti_tool`, `eudi_credential_offer`,
      `eudi_issuance_session`, `eudi_status_list` (REQ-MCP-104). This is the highest-severity check
      in the change.

## 2. Verify

- [ ] 2.1 `python3 -m json.tool lib/Settings/openconnector_register.json` — valid JSON, and diff
      to confirm the only additions are the 8 `configuration` keys.
- [ ] 2.2 Assert every declared filter is a real property: for each of the 8 schemas, diff the
      `filters` list against that schema's `properties` keys. Zero unknown filters.
- [ ] 2.3 Re-import the register (`lib/Repair/InitializeRegister.php` / `occ`) and confirm it
      succeeds — `McpAnnotationValidator` gets its chance to reject a bad dialect.
- [ ] 2.4 Grep the register for `x-openregister-mcp` and confirm exactly 8 occurrences, on
      exactly the 8 curated slugs.

## 3. Close out

- [ ] 3.1 `openspec validate openconnector-mcp-adoption --type change --strict`.
- [ ] 3.2 CHANGELOG entry under Unreleased/Added: read-only MCP tool surface (ADR-063) — 8
      curated schemas, 16 read tools, zero write tools, all credential-bearing schemas excluded;
      reference ADR-063 in the commit/PR body.

## Acceptance criteria

- Exactly 8 schemas carry `x-openregister-mcp`; each declares only `search` + `get`, `scope: read`, `readOnlyHint: true`.
- Every declared `search` filter is a verified real property of its schema; the register imports cleanly.
- Zero write verbs anywhere; zero tools for any credential-bearing schema (incl. `get`).
- No PHP added; openconnector still ships no MCP provider.

## Quality reminders (plain-text, not checkboxes)

- Re-validate the JSON after every edit — one malformed descriptor blocks the entire register import.
- Never add a verb to `source`/`consumer`/`rule`/`event_subscription`/`lti_*`/`eudi_*`; controller-side redaction does not protect a derived tool.
- Tests (ADR-009): N/A — no PHP, no API surface, no UI. The gate is the register import + the filter cross-check in 2.2.
- Docs (ADR-010): N/A — no user-facing feature; the agent-facing prose lives in the dialect itself.
</content>

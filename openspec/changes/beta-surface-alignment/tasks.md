## 1. Verify claims against `lib/` at HEAD

- [x] 1.1 Confirm Source `type` enum (`docs/schema/Source.json`) and trace
      `CallService::callSource()` branching (soap vs. HTTP-via-Guzzle) —
      no GraphQL, no FTP/SFTP client, no DB-connector source code.
- [x] 1.2 Grep `lib/` for `mistral|ollama|openai|claude|anthropic` — zero hits;
      confirm no LLM adapter code exists.
- [x] 1.3 Grep `lib/` for `windmill|n8n` and `OCA\Mail|OCA\Files` — zero real
      hits; confirm no dedicated workflow-engine bridge or Mail/Files sidebar code.
- [x] 1.4 Confirm the four aspirational connector-category specs
      (`data-infra-connectors`, `document-cms-connectors`,
      `saas-productivity-connectors`, `endpoint-workspace-connectors`) have no
      corresponding `lib/Service/Adapter/*` implementation and no registered
      `IntegrationProvider` beyond `SynchronizationContractProvider`.
- [x] 1.5 Confirm real adapters: PDOK, StUF-ZKN/BG, DSO/Omgevingsloket,
      Berichtenbox, iBabs/NotuBiz — each has a corresponding `lib/` service and
      a retrofit `openspec/specs/*` entry.
- [x] 1.6 Confirm `composer.json` `php: ^8.3` vs. `info.xml` `php min-version="8.0"`
      mismatch.

## 2. Fix `appinfo/info.xml`

- [x] 2.1 Split `<summary>` into `lang="en"` / `lang="nl"` with real Dutch copy.
- [x] 2.2 Rewrite `<description>` (EN + NL) to name shipped capabilities.
- [x] 2.3 Add `<app>openregister</app>` to `<dependencies>`.
- [x] 2.4 Correct `php min-version` to `8.3`.
- [x] 2.5 Confirm `img/app.svg` matches the white-fill/24×24 convention (no change needed).

## 3. Fix product page (EN + NL)

- [x] 3.1 Bump `version` to `v0.2.16` (info.xml is the source of truth).
- [x] 3.2 Rewrite hero tagline/intro to drop GraphQL/queues/DB-connector claims.
- [x] 3.3 Rewrite "six protocols" FeatureItem → REST/SOAP only, with real auth methods.
- [x] 3.4 Soften "tamper-evident … WOO and BIO compliance evidence" to a factual
      audit-trail claim.
- [x] 3.5 Rewrite the "any source" RotatingCard to name the real gov-standard adapters.
- [x] 3.6 Replace the entire Showcase block (Mail/Files, Windmill/n8n, LLMs) with
      three verified capabilities: gov-standard adapters, dead-letter replay,
      endpoint rule pipeline. Remove the now-unused `AgentTrace` import.
- [x] 3.7 Fix NL page's dead `docs.conduction.nl/openconnector` link →
      `openconnector.conduction.nl`.

## 4. Fix docs

- [x] 4.1 Correct `docs/intro.md` frontmatter description and Sources bullet list.
- [x] 4.2 Fix the "# Open Register Documentation" copy-paste leftover heading.
- [x] 4.3 Correct `docs/docusaurus.config.js` tagline.

## 5. Record the change

- [x] 5.1 Write `proposal.md` documenting the canonical feature list and every
      reconciliation (verified vs. removed claims).
- [x] 5.2 Write this `tasks.md`.
- [x] 5.3 Write `specs/beta-alignment/spec.md` delta.

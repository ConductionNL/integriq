---
kind: code
depends_on: []
---

# Proposal: platform-integrations-as-code

## Summary

Integriq's `occ` commands are migration and cleanup tools. An operator cannot
list sources, run a synchronization, test a source or run a job from the
command line, and cannot export a configuration to files, keep them in git and
import them on another instance without clicking through the screens. This
change adds `occ` commands to list, run and test sources, synchronizations and
jobs, and to export a configuration to a directory of stable, diffable files
and import it back with a preview. Git itself stays the operator's tool.

## Why

Two rows, neither with a demand row.

`integriq:plt-cli`, "Manage integrations from the command line." Integriq rates
it `partial` with `built.state` `built`. Matrix note: "Every command is
registered and works, but all are migration, audit or cleanup tools. There is
no occ command to list, run or test a source, synchronization or job, or to
export and import a configuration." Competitors rated `yes`:

- n8n (`n8n`), source read at n8n@2.40.7: "packages/cli/src/commands holds
  execute.ts, execute-batch.ts, export/ (workflow, credentials, entities,
  nodes), import/ (workflow, credentials, entities), list/workflow.ts". No
  evidence URL is recorded for this cell.
- MuleSoft Anypoint (`mulesoft`), evidence
  https://docs.mulesoft.com/anypoint-cli/latest/index.md: "Anypoint Platform
  CLI provides scripting and command-line capabilities ... Use the CLI to
  automate platform operations".

`integriq:plt-git`, "Keep the integration setup under version control in
git." Integriq rates it `no` with `built.state` `none`. Matrix note: "No
git-backed storage or sync of the integration setup exists. The nearest
substitute is exporting a configuration JSON file and committing it by hand."
Competitors rated `yes`:

- MuleSoft Anypoint (`mulesoft`), evidence
  https://docs.mulesoft.com/design-center/design-ghs-about-github-sync.md,
  "GitHub Synchronization ... enable two-way synchronization between API
  Designer" and GitHub, and
  https://docs.mulesoft.com/mulesoft-terraform-provider/index.md for platform
  setup in Terraform files.
- Frank!Framework (`frank`), source read at v10.2.0: "the whole setup is plain
  files ... loaded from a directory or jar by
  core/src/main/java/org/frankframework/configuration/classloaders/DirectoryClassLoader.java,
  so it lives in a git repository as is". No evidence URL is recorded for this
  cell.

This change covers `integriq:plt-cli` and `integriq:plt-git`.

## What integriq already has

- Eight commands registered at `appinfo/info.xml:456` to `:506`, all migration
  or cleanup: `MigrateToOpenRegister`, `MigrateInlineSecrets`,
  `AuthenticationConfig`, `SynchronizationToFlow`, `DedupeContracts`,
  `JobToFlow`, `RuleToFlow`, `FlowStepsToGraph`. `lib/Command/JobToFlow.php:54`
  shows the shape: a Symfony `Command` with a name, arguments and options.
- The services a command needs: `lib/Service/SourceTestService.php:92`
  (`run()`), `lib/Service/SynchronizationService.php:3147` (`synchronize()`,
  with `isTest`), `lib/Service/JobService.php:345` (`executeJob()`).
- Configuration export and import:
  `lib/Service/ConfigurationService.php:340` (`exportConfiguration()`),
  `:1037` (`importConfiguration()`), and the preview at
  `lib/Service/ConfigurationImportPreviewService.php:122`. The export is one
  OpenAPI-shaped document with slug references (`configuration-export-import`
  REQ-001, REQ-004), redacted source credentials (REQ-005) and `credentialRef`
  placeholders passed through (REQ-010).
- `environments-and-promotion` listed "Git-backed configuration storage /
  GitOps workflows" as an out-of-scope follow-up
  (`openspec/changes/archive/2026-07-15-environments-and-promotion/proposal.md:77`).

## What this change builds

1. `occ integriq:source:list`, `integriq:source:test`,
   `integriq:synchronization:list`, `integriq:synchronization:run` (with
   `--test`), `integriq:job:list` and `integriq:job:run`, each taking a uuid or
   a slug, with `--output=json` and exit codes a script can branch on.
2. `occ integriq:config:export <configuration> <directory>`, writing one file
   per entity with sorted keys and slug references, so a change to one mapping
   is a one-file diff.
3. `occ integriq:config:import <directory>`, which prints the preview and
   writes only with `--confirm`, the same confirmation rule the screen has.
4. A round trip that is stable: exporting, importing into a clean instance and
   exporting again produces identical files.

## Out of scope

- A git client inside integriq that clones, pulls or pushes a remote. The
  operator's pipeline runs git; integriq reads and writes files.
- Applying a repository automatically on a push. An import stays a confirmed
  act, as `environments-and-promotion` decided.
- A command for endpoints, rules and events beyond what export and import
  carry.

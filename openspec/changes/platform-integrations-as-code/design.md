# Design: platform-integrations-as-code

Kind: code. Thin commands over the services the controllers already call, and
a directory form of the existing configuration export.

## Where it fits

- Commands: new classes in `lib/Command/` in the shape of
  `lib/Command/JobToFlow.php:54` (name at `:76`, `execute()` at `:104`),
  registered in `appinfo/info.xml` after the existing eight at `:456` to `:506`:
  - `SourceList` and `SourceTest`, calling
    `lib/Service/SourceTestService.php:92` (`run()`), the service
    `sources#test` uses (`lib/Controller/SourcesController.php:249`).
  - `SynchronizationList` and `SynchronizationRun`, calling
    `lib/Service/SynchronizationService.php:3147` (`synchronize()`) with
    `isTest` from `--test` and `force` from `--force`, as
    `lib/Controller/SynchronizationsController.php:288` does.
  - `JobList` and `JobRun`, calling `lib/Service/JobService.php:345`
    (`executeJob()`) with `forceRun` from `--force`.
  - `ConfigExport` and `ConfigImport`, over
    `lib/Service/ConfigurationService.php:340` and `:1037` and the preview at
    `lib/Service/ConfigurationImportPreviewService.php:122`.
- Directory layout: a new `lib/Service/ConfigurationDirectoryCodec.php` splits
  the exported document into `integriq-configuration.json` (the configuration
  itself and a file index) plus one file per entity at
  `<type>/<slug>.json`, and joins a directory back into the document
  `importConfiguration()` takes. Keys are sorted, JSON is pretty-printed with a
  trailing newline, and volatile fields (`uuid`, `created`, `updated`,
  `dateModified`, `lastRun`, `nextRun`) are left out.
- Resolution by uuid or slug reuses `findMappingByIdentifier()`'s pattern
  (`lib/Service/MappingService.php:239`): the uuid first, then `slug`.

## D1. Commands are thin, the services are the ones the screens use

Each command resolves its argument, calls the same service method the
controller calls and prints the result. The alternative was to call integriq's
own HTTP routes from the command. Rejected: it needs a user and a token, and
adds a network hop to a process that is already inside the server. `occ` runs
as the server's own user, so the action matrix does not apply; who may run
`occ` is the server administrator's decision.

## D2. Files per entity, because git diffs files

One large document makes every change a diff of the whole configuration, and
reordering keys makes noise. One file per entity with sorted keys makes an edit
to a mapping a change to `mappings/<slug>.json` and nothing else. The
alternative was the single document from the screen's export. Rejected for this
use; the single document stays for the screen and for promotion.

## D3. Integriq reads and writes files; git is the operator's

A built-in git client would need a git binary or library on the server,
credentials for the remote, outbound access to it under ADR-067, and a way to
review before applying. The operator's pipeline already has all four. The
alternative was a MuleSoft-style two-way sync with a remote. Rejected for this
change; the directory format is what such a sync would write, so it can be
added later without changing the files.

## D4. Import previews, and writes only when confirmed

`integriq:config:import` prints the creates, updates and collisions from the
preview service and exits without writing unless `--confirm` is given, the
command-line form of `configuration-export-import` REQ-008. Credentials never
travel in the files: sources carry `credentialRef` placeholders (REQ-010) and
redacted fields are flagged for re-entry (REQ-009), and the import prints them.

## Declarative versus imperative

The configuration files are declarative; they describe the objects. Import is
imperative through `importConfiguration()`, unchanged. The commands add no
behaviour of their own.

## Risks

- An export is only as stable as its slugs. An entity without a slug is written
  under its uuid (REQ-001's fallback), so its file name changes on another
  instance. The export command warns and lists entities without a slug.
- `integriq:synchronization:run` on a large synchronization runs for a long
  time in the foreground. It prints progress from the run record as the
  synchronization writes it, and a scheduled run stays the job's work, not the
  command's.

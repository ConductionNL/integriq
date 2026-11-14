# Keeping a configuration in git

An integriq configuration (its sources, mappings, rules, endpoints, synchronizations and jobs)
can be kept as files in a git repository. You review a change as a pull request, and another
instance imports the reviewed files after a preview. Integriq reads and writes the files; git,
the remote and the review stay in your own pipeline.

## The directory

`occ integriq:config:export` writes one index file and one JSON file per entity:

```text
integriq-configuration.json
sources/brp-haalcentraal.json
mappings/woo-index-publication.json
synchronizations/woo-publications.json
```

Each file has sorted keys and ends with a newline. Fields that differ per instance or per run
(`id`, `uuid`, `created`, `updated`, `dateModified`, `lastRun`, `nextRun`) are left out, so
exporting an unchanged configuration twice writes the same bytes, and editing one mapping
changes one file.

No secret is written. Passwords, keys and tokens are replaced by `***REDACTED***`. A source that
uses a `credentialRef` keeps the reference, not the credential. Entities are named by their slug;
one without a slug is written under its uuid and the export warns about it, because that file
name changes on another instance. Give it a slug.

## Export, commit, review, import

1. Export into a git working tree:

   ```bash
   occ integriq:config:export <configuration-id> ./integriq-config
   ```

   The command prints how many files changed and which.

2. Commit and push the change, and open a pull request:

   ```bash
   cd integriq-config
   git add -A
   git commit -m "Map the Woo publication subject"
   git push
   ```

3. Review the pull request. Each changed file is one entity.

4. On the receiving instance, check out the reviewed files and preview the import:

   ```bash
   occ integriq:config:import ./integriq-config
   ```

   The preview lists what would be created and updated, collisions, references that do not
   resolve, and sources whose credentials have to be entered again. Nothing is written.

5. Import when the preview is what you expect:

   ```bash
   occ integriq:config:import ./integriq-config --confirm
   ```

   The import goes through the same path as the import on the configuration screen. Enter the
   credentials the preview listed afterwards.

Import accepts `--output=json` for scripts. Export exits with 0 when it wrote the
directory, 1 when it failed and 2 when the configuration holds nothing; import exits with 0 after
a preview or an import and 1 when the directory cannot be read or the import failed.

## Running and testing from the shell

The same objects can be listed, run and tested with `occ`, by uuid or slug:

| Command | Does |
| --- | --- |
| `integriq:source:list`, `integriq:source:test <source>` | List sources, test one the way the Test connection button does |
| `integriq:synchronization:list`, `integriq:synchronization:run <synchronization> [--test]` | List synchronizations, run one; `--test` writes nothing |
| `integriq:job:list`, `integriq:job:run <job>` | List jobs, run one |

Each exits with 0 on success, 1 on a failed run or test and 2 when the object is not found.

# Migration: openconnector-services-direct-or-usage

## Why There Is No DB Migration

Chain C is a **pure code-side refactor**. No database schema changes are
introduced by this change:

- The `oc_openregister_objects` table (introduced by chain A's descriptor and
  populated by chain B's `LegacyToRegisterMigrator`) already contains every
  openconnector domain object as OR-native rows. Chain C does not move, add, or
  transform any rows.
- The `oc_openconnector_*` legacy tables (e.g. `oc_openconnector_sources`,
  `oc_openconnector_jobs`) remain on disk, untouched. They become fully inert
  after chain C ships — no in-process code path reads from or writes to them
  after this change. Their removal is chain B's cleanup follow-up ([#820](https://github.com/ConductionNL/openconnector/issues/820)),
  not chain C's responsibility.
- No new Nextcloud migration class (`lib/Migration/Version*.php`) is shipped
  with this change. The existing chain B migration class is unchanged.

The only "migration" in this change is a code migration: deleting 31 PHP files
and rewriting their callers. That is documented below.

## Pre-flight Requirement

This change MUST NOT be deployed to an environment where chain B has not
completed successfully. The deployment gate is:

```
IAppConfig::getValue('openconnector', 'storage_migrated', 'false') === 'true'
```

`Application::register()` asserts this at app boot (design.md D3). If the flag
is absent or `'false'`, the app raises `\LogicException` and refuses to boot,
instructing the operator to run:

```
occ openconnector:migrate-storage
```

Only after that command has set `storage_migrated = 'true'` may chain C be
deployed.

## Data Risks During Cutover

**The cutover is atomic at the Composer autoload regeneration level.**

When chain C is deployed via standard Nextcloud update tooling:

1. Composer autoload is regenerated (old mapper/entity classes are removed from
   the classmap; new DTO classes are added).
2. The app code is atomically replaced (all-or-nothing file replacement under
   `custom_apps/openconnector/`).
3. Nextcloud's OPcache is invalidated (standard deployment step).

There is NO window where the old mapper code is in memory while the new OR-direct
code is on disk, because Nextcloud's PHP process serves the request using the
file contents at request time (no in-process partial reload).

**Risk: in-flight background jobs.** If a cron job is mid-execution when the
deployment swap happens (e.g. `JobTask` is running), it will finish with the
pre-cutover code or fail at the PHP class-load boundary. Mitigation:

- Deploy chain C during a low-traffic window.
- Ensure `cron.php` / the Nextcloud background-job queue drains before
  swapping code (or accept that a mid-flight job may fail and retry on the next
  cron cycle with the new code).
- The rewritten cron tasks are functionally identical from the job perspective;
  a retry after cutover succeeds.

**Risk: Rollback is destructive if chain B cleanup has run.** Chain C itself is
fully reversible (`git revert` restores the 31 files). However, if chain B's
cleanup change ([#820](https://github.com/ConductionNL/openconnector/issues/820), which drops `oc_openconnector_*` tables) has
already been applied when a chain C rollback is needed, the legacy tables will
be gone and the reverted code will fail to read from them. Release notes for
chain B's cleanup change MUST document this irreversibility gate.

## Per-File Deletion Checklist

31 files must be removed from the repository. All are in the `lib/` tree.
An apply agent checks each box when the file is deleted and the caller has been
rewritten.

### Mapper files (15)

- [ ] `lib/Db/CallLogMapper.php`
- [ ] `lib/Db/ConsumerMapper.php`
- [ ] `lib/Db/EndpointMapper.php`
- [ ] `lib/Db/EventMapper.php`
- [ ] `lib/Db/EventMessageMapper.php`
- [ ] `lib/Db/EventSubscriptionMapper.php`
- [ ] `lib/Db/JobMapper.php`
- [ ] `lib/Db/JobLogMapper.php`
- [ ] `lib/Db/MappingMapper.php`
- [ ] `lib/Db/RuleMapper.php`
- [ ] `lib/Db/SourceMapper.php`
- [ ] `lib/Db/SynchronizationContractLogMapper.php`
- [ ] `lib/Db/SynchronizationContractMapper.php`
- [ ] `lib/Db/SynchronizationLogMapper.php`
- [ ] `lib/Db/SynchronizationMapper.php`

### Entity files (15)

- [ ] `lib/Db/CallLog.php`
- [ ] `lib/Db/Consumer.php`
- [ ] `lib/Db/Endpoint.php`
- [ ] `lib/Db/Event.php`
- [ ] `lib/Db/EventMessage.php`
- [ ] `lib/Db/EventSubscription.php`
- [ ] `lib/Db/Job.php`
- [ ] `lib/Db/JobLog.php`
- [ ] `lib/Db/Mapping.php`
- [ ] `lib/Db/Rule.php`
- [ ] `lib/Db/Source.php`
- [ ] `lib/Db/Synchronization.php`
- [ ] `lib/Db/SynchronizationContract.php`
- [ ] `lib/Db/SynchronizationContractLog.php`
- [ ] `lib/Db/SynchronizationLog.php`

### Facade (1)

- [ ] `lib/Service/Storage/ObjectMapperFacade.php`

**Total: 31 files.**

After all boxes are checked, the apply agent runs:

```bash
composer check:strict
```

The build MUST be green before opening a PR for the deletion commit.

## Validation After Deletion

### Functional validation

```bash
# 1. No deleted type survives in lib/ or tests/
grep -rn "OCA\\\\OpenConnector\\\\Db\\\\Source\b\|OCA\\\\OpenConnector\\\\Db\\\\Job\b\|ObjectMapperFacade" lib/ tests/
# Expected: zero matches

# 2. No mapper file remains
find lib/Db -maxdepth 1 -name '*Mapper.php'
# Expected: zero results (StUFFieldMapper is under lib/Service/, not lib/Db/)

# 3. No entity file remains (except Dto/ subdirectory)
find lib/Db -maxdepth 1 -name '*.php' ! -path '*/Dto/*'
# Expected: zero results

# 4. Quality gate passes
composer check:strict
# Expected: exit 0

# 5. Unit tests pass
composer phpunit
# Expected: exit 0, ≥80% line coverage on rewritten services

# 6. App boots on a storage_migrated=true environment
docker exec nextcloud php occ app:enable openconnector 2>&1
# Expected: no LogicException, app enabled successfully
```

### Newman regression validation

```bash
# Run the full Newman collection against the deployed chain C environment
newman run tests/Http/openconnector.postman_collection.json \
  --environment tests/Http/local.postman_environment.json
# Expected: all tests pass (0 failures)
```

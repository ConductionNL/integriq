
---

# Follow-up work (second pass)

## The third leg of the data migration: stored class names

`MigrateAppConfigKeys` and `MigrateUserPreferences` cover `oc_appconfig` and
`oc_preferences`. There is a third store the rename cuts loose, one layer out:
**a job object stores its action as a PHP class NAME**, and `JobService`
resolves that stored string through the container at run time.

```php
$action = $this->containerInterface->get($jobData['jobClass']);
```

After the rename every stored job asks for `OCA\OpenConnector\Action\*`, a class
that no longer exists. **It fails silently, at two independent layers:**

- in `JobService::executeJob()` the `get()` call sits **outside** the `try` that
  writes a `job_log`, so no job_log row is written for this failure;
- in `JobService::run()` (the cron entry point) the per-job catch is
  `unset($e); continue;` — it **discards the exception without logging**, on the
  assumption that `executeJob()` has already logged. For this failure that
  assumption does not hold.

Net effect: every synchronization stops running, cron reports success, and
nothing records why. A wrong assumption in a catch block is how a total failure
becomes a silent one.

`MigrateStoredJobClasses` fixes it — registered in **both** `<install>` and
`<post-migration>` after `InitializeRegister`:

- rewrites **only** the exact `OCA\OpenConnector\` prefix; anything unrecognised
  is left as-is rather than guessed at;
- updates in place by uuid; idempotent; per-object isolation with every read AND
  write inside the `try`, because under `<install>` a throwing repair step means
  the app never enables at all;
- **enumeration is explicitly paged.** `ObjectService::findAll()` is
  server-paged; taking its default page would migrate the first 100 jobs, leave
  the rest broken, and report success. A test asserts the second page is
  requested.

`class_alias` was deliberately **not** used: it would make the old name work
forever, the stored rows would never be fixed, and the debt would be permanent
and invisible.

**Scope was measured, not assumed.** `jobClass` is the only persisted class
reference. `adapterClass` in `lib/sources.seed.json` looks like a second one and
is not: that file has **zero PHP readers** (a fact this repo documents in
`lib/Settings/register.d/environments-and-promotion.json`) and `adapterClass` is
on no schema.

**Tests.** `MigrateAppConfigKeys` and `MigrateUserPreferences` shipped
**untested**. All three steps now have unit tests (16 tests, 27 assertions),
including the `RESERVED_KEYS` guard — copying `enabled` would permanently break
the next `app:enable` with an `AppConfigTypeConflictException`.

## KNOWN RESIDUAL — stale `oc_jobs` rows (unverified, deliberately not fixed)

Nextcloud's `oc_jobs` also stores class names, for this app's
`<background-jobs>`. After the rename the old rows still name
`OCA\OpenConnector\Cron\*`.

**Not touched on purpose:** `oc_jobs` is the *server's* table. An app writing to
it is the same category of mistake as renaming another app's id.

**Unverified, and why.** Nextcloud's `JobList` is believed to remove rows whose
class cannot be built, which would make this self-healing. That could not be
confirmed here: only `nextcloud/ocp` (interfaces) is vendored, and the only
Nextcloud server source on the machine was outside the permitted working set.
Recorded as unverified rather than guessed.

**Worst case if `JobList` does NOT prune unbuildable rows.** The rows persist and
Nextcloud tries to instantiate a missing class every cron tick. The app's own
jobs are registered fresh under the new names and run normally, so the expected
symptom is **repeated log noise rather than data loss** — though a persistently
unbuildable row can hold a slot in the job-queue rotation.

**Watch for** autoload / `Could not create job instance` errors naming
`OCA\OpenConnector\Cron\*` in `nextcloud.log`.
**Remedy:** `occ background-job:delete <id>` — server-side, not an app change.

## Env-var rename — both spellings honoured

`Application.php` had moved to `INTEGRIQ_SKIP_STORAGE_MIGRATED_ASSERT` while its
test still set `OPENCONNECTOR_SKIP_STORAGE_MIGRATED_ASSERT`: the test set a
variable nothing read, so the guard was effectively untested.

A renamed env var is the same silent-default trap as a renamed config key. This
bypass was documented under the old name (CHANGELOG 0.2.x and the
`direct-or-usage` spec) — documentation an operator may have acted on months ago.
Reading only the new name would quietly stop honouring theirs and start emitting
the warning they had deliberately silenced.

Both spellings are accepted, the canonical one named in a comment with the
condition for dropping the old one, and **a test pins the legacy path** so the
shim cannot be tidied away unnoticed. Same for `SKIP_NETWORK_TESTS`.

`OPENCONNECTOR_NEWMAN_LOCKED` was renamed outright with no shim: it is set and
read by the same script as a flock re-exec guard, so there is no external
consumer to fall out of step with. Same-looking strings, different verdicts,
decided by **who else answers to the name**.

## Additional frozen literals found during the full sweep

The table above (#1–#12) still holds. These were found in the second pass. The
column that matters is the consequence, not the location.

| Literal | Consequence of renaming it |
| --- | --- |
| `openspec/specs/openconnector-*` — nine **capability ids** | `@spec` paths resolve against them and gate-46 dereferences those paths, **including from the frozen archive**, which cannot be updated. Renaming breaks references that cannot be fixed. |
| `BrokeredCallService::APP_ID`, `InlineSecretMigrationPlanner/Executor::APP_ID`, `AbstractCategoryAdapterProvider` `appId` | **Not this app's own id.** It is the identity OpenRegister's credential broker matches against a credential's `allowedApps` via a strict `in_array()`. Every minted credential carries `allowedApps: ["openconnector"]`, so renaming makes every brokered credential **fail closed at call time** — as an authorisation refusal, not a rename bug. |
| Flow node type ids `openconnector.{source-call,source-paginate,apply-mapping,contract-commit,contract-sweep,synchronization-run,fetch-file}` | These `type` values are written **into stored flow documents**. Renaming leaves every existing flow referencing a node type nothing answers to. |
| `X-OpenConnector-Signature`, `X-OpenConnector-Event-Id`, and the webhook `scheme` value | Fails closed in **both** directions: subscribers verify outbound by header name, and the same names are the `??` default for verifying inbound webhooks from payment providers, NotifyNL and StUF brokers. Nothing on our side reports why. |
| `StUFXMLBuilder` `zenderApplicatie`, `StufZknSyncService::FALLBACK_ORGANISATIE` | This app's identity as a municipal StUF zaaksysteem knows it. Renaming here does not rename it there; messages are rejected until the municipality re-provisions. |
| EUDI `credential_issuer`, `EudiStatusListService` JWT `iss` | Issuer identifiers already held by issued wallet credentials and offers. |
| `THROTTLE_ACTION = 'openconnector_eudi_wallet_credential'` | Stored in `oc_bruteforce_attempts`. Renaming **resets every in-flight throttle counter** — an attacker being throttled gets a clean slate, silently. |
| `SharePointOnlineAdapter::TARGET_FOLDER` | A **remote** SharePoint folder. Renaming points the adapter at a folder that does not exist on the tenant. |
| `createDistributed('openconnector')`, `createDistributed('openconnector.eudi.offercode')` | The plain caches would merely cold-start (harmless), but the offercode namespace holds **in-flight OpenID4VCI offer codes** — renaming invalidates every credential offer a user is mid-flow on. Frozen together; a follow-up candidate for a maintenance window. |
| `app: openconnector` on flow objects, `configuredVia: openconnector` on seed sources | Stored field **values** on live objects. Anything filtering on them matches the old string; renaming the doc without migrating the rows describes behaviour the code does not have. |
| The verbatim occ error `"There are no commands defined in the openconnector namespace"` | A **quoted observation**. Rewriting it falsifies the record. Left exactly as-is, with a sentence added noting the namespace has since moved. |
| `codeberg.org/Conduction/openconnector` issue URLs | A different host and org; stale independent of this rename (project policy is GitHub-only). Moves when that host does, if ever. |

### NOT a miss — do not "fix" these

`MigrateAppConfigKeys::OLD_APP_ID`, `MigrateUserPreferences::OLD_APP_ID` and
`MigrateStoredJobClasses::OLD_CLASS_PREFIX` all still say `openconnector` /
`OCA\OpenConnector\`. **This is the rename machinery naming the SOURCE it reads
from.** They are the one place in the app that is supposed to still say the old
name. A future "did we miss any?" grep will flag them as a miss; that grep is
wrong.

**Do not "fix" them.** Pointing these at `integriq` makes each step read from
the namespace it is supposed to be writing TO, so it would find nothing to
migrate and copy zero rows — **and report success while doing it**. Every
setting silently reverts to its default, which is the exact failure these steps
exist to prevent, in the one direction nothing would notice. The unit tests
added here pin the old values, so the behaviour cannot regress unnoticed.

### GitHub issue references

Standardised on the **new** name (`ConductionNL/integriq#NNN`). The old-name
redirect dies the moment anything is created under the old name, and the issue
numbers are unchanged, so there is no ambiguity.

### MCP tool ids — checked, and safe

The `openconnector.{schema}.{verb}` -> `integriq.{schema}.{verb}` rename in
`openconnector-mcp-tool-surface/spec.md` was flagged as risky, because the tool
ids might derive from the frozen **register slug** rather than the app id — the
`x-openregister-mcp` block declares no explicit tool name.

Verified against OpenRegister: `x-openregister-mcp` **does not exist anywhere in
that repository**, so the ADR-063 derived-tool engine is not implemented and
nothing emits any tool id today. There is no executable grammar to diverge from,
so the spec rename is safe.

**REQUIREMENT on the ADR-063 implementation.** When OpenRegister's derived-tool
engine is built, the tool-id prefix **MUST** derive from the **app id**
(`integriq`), **NOT** from the register slug (`openconnector`, which is frozen and
does not move with the app id). The two were the same string before this rename
and are not any more, so the choice is now load-bearing and cannot be left
implicit. If the engine derives from the register slug instead, this spec
becomes wrong the day it ships — and nothing would catch it, because gate-46
dereferences `@spec` PATHS, not runtime tool-id strings.

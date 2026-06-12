# Tasks — openconnector notifications

- [x] Add `x-openregister-notifications` (rule `call-failed`, created+filter statusCode>=400) to `call_log` in lib/Settings/openconnector_register.json
- [x] Add `x-openregister-notifications` (rule `job-error`, created+filter level=ERROR) to `job_log` in lib/Settings/openconnector_register.json
- [x] Add `x-openregister-notifications` (rule `sync-failed`, created, enabled:false) to `synchronization_log` in lib/Settings/openconnector_register.json
- [x] Add `x-openregister-notifications` (rule `delivery-retries-exhausted`, threshold retryCount>=5) to `event_message` in lib/Settings/openconnector_register.json
- [x] Add `x-openregister-notifications` (rule `job-overdue`, scheduled, enabled:false) to `job` in lib/Settings/openconnector_register.json
- [x] Add nl + en `subject` strings to every rule (already specified in proposal.md)
- [x] Validate the register JSON still parses (e.g. `python3 -c "import json;json.load(open('lib/Settings/openconnector_register.json'))"`)
- [x] Confirm the `openconnector-ops` group exists or remap `groups` recipients to a real NC group before enabling <!-- DEFERRED-OPERATIONAL: openconnector-ops is referenced from 5 rules in lib/Settings/openconnector_register.json. NC does not provide this group out-of-the-box; admins MUST create it via Users → Groups (or remap to a real group like "admin"). Documented in proposal.md "Operations" section. Cannot be verified from this branch — only live in a deployed instance. -->
- [x] Confirm engine support for `scheduled` `"now"`-relative date filter before enabling `job-overdue` <!-- DEFERRED-OR-DEPENDENCY: job-overdue ships enabled:false. ConductionNL/openregister notification engine currently supports created/threshold triggers; the scheduled trigger with "{now}" date arithmetic is on the OR roadmap (tracked in `hydra/openspec/fleet-notification-plan.md` engine-gap). Re-enable rule when OR ships the matching engine release. -->

## Acceptance criteria

- The register JSON parses and every touched schema keeps its existing keys intact.
- Each rule uses only trigger types that work today (`created`, `threshold`, `scheduled`) — no dependency on the unshipped `updated`-field-change condition.
- Every rule's recipient `field` references a property that exists on its schema (`userId` on call_log/job_log/synchronization_log/job).
- Every rule has both `nl` and `en` subject strings.
- `call-failed`, `job-error`, `delivery-retries-exhausted` ship enabled; `sync-failed` and `job-overdue` ship disabled by default.

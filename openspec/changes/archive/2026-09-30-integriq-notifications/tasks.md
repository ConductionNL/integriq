# Tasks — integriq notifications

- [x] Add `x-openregister-notifications` (rule `call-failed`, created+filter statusCode>=400) to `call_log` in lib/Settings/integriq_register.json
- [x] Add `x-openregister-notifications` (rule `job-error`, created+filter level=ERROR) to `job_log` in lib/Settings/integriq_register.json
- [x] Add `x-openregister-notifications` (rule `sync-failed`, created, enabled:false) to `synchronization_log` in lib/Settings/integriq_register.json
- [x] Add `x-openregister-notifications` (rule `delivery-retries-exhausted`, threshold retryCount>=5) to `event_message` in lib/Settings/integriq_register.json
- [x] Add `x-openregister-notifications` (rule `job-overdue`, scheduled, enabled:false) to `job` in lib/Settings/integriq_register.json
- [x] Add nl + en `subject` strings to every rule (already specified in proposal.md)
- [x] Validate the register JSON still parses (e.g. `python3 -c "import json;json.load(open('lib/Settings/integriq_register.json'))"`)
- [x] Confirm the `openconnector-ops` group exists or remap `groups` recipients to a real NC group before enabling. It exists on no instance; Ruben decided on 29 Sep 2026 that alerts go to `admin` unless an administrator names another group. Every rule (10 in `integriq_register.json` and its mock copy, 1 in `register.d/hitl-approval-rule-action.json`) now names the `ConnectionAlertRecipientResolver` expression recipient, which reads `connection_alert_group` and falls back to `admin` (tests/Unit/Settings/IntegrationAlertRecipientsTest.php).
- [x] Confirm engine support for `scheduled` `"now"`-relative date filter before enabling `job-overdue`. Confirmed against OpenRegister development a5832f2498: `ScheduledFilterGrammar` has `before`/`after`, `ScheduledFilterEvaluator::resolveInstant()` reads `now` as the scan pass's logical now (tests/Unit/Settings/JobOverdueRuleTest.php, #2388). The rule still ships disabled.

## Acceptance criteria

- The register JSON parses and every touched schema keeps its existing keys intact.
- Each rule uses only trigger types that work today (`created`, `threshold`, `scheduled`) — no dependency on the unshipped `updated`-field-change condition.
- Every rule's recipient `field` references a property that exists on its schema (`userId` on call_log/job_log/synchronization_log/job).
- Every rule has both `nl` and `en` subject strings.
- `call-failed`, `job-error`, `delivery-retries-exhausted` ship enabled; `sync-failed` and `job-overdue` ship disabled by default.

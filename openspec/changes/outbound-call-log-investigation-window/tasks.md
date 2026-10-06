# Tasks: outbound-call-log-investigation-window

Kind: code. Size M. Woo row 13.23, decision D5. Wave 1.

The tests named below fail on `development` today: the window fields, the
controller and the stripping do not exist, and a failure outside a window
stores its body.

## Implementation tasks

### Task 1: The window on the source
- **spec_ref**: `openspec/changes/outbound-call-log-investigation-window/specs/outbound-call-log/spec.md#requirement-an-administrator-opens-a-bounded-investigation-window-per-source-req-ocd-008`
- **files**: `lib/Settings/integriq_register.json` (source `bodyCaptureUntil` date-time, `bodyCaptureReason`, `bodyCaptureBy`; `call_log` `bodyCaptured`, `bodyExpiresAt`, `bodyExpiredAt`, `replayRequest`; register version bump), `lib/Controller/BodyCaptureController.php`, `appinfo/routes.php`, `lib/Service/SettingsService.php` (`call_log_body_capture_max_hours`, `call_log_body_retention_days`)
- **acceptance_criteria**:
  - GIVEN an administrator WHEN `POST /api/sources/{id}/body-capture` with `{hours: 24, reason}` THEN the three source fields are set and an audit entry names the principal and reason
  - GIVEN `hours` above the maximum, or an empty reason WHEN posted THEN 400 and nothing changes
  - GIVEN a principal without the admin permission WHEN posted THEN 403
  - GIVEN an open window WHEN `DELETE /api/sources/{id}/body-capture` THEN `bodyCaptureUntil` is now and an audit entry is written
- [ ] Implement. Every route carries its Nextcloud auth attribute; the controller checks admin itself (no `#[NoAdminRequired]` with a body that needs admin).
- [ ] Test: PHPUnit `tests/Unit/Controller/BodyCaptureControllerTest.php` (`testAnAdministratorOpensAWindowWithAReason`, `testMoreHoursThanTheMaximumIsRefused`, `testAReaderCannotOpenAWindow`, `testClosingEarlyIsAudited`)
- [ ] Wiring: a route test that resolves both routes in `appinfo/routes.php` to existing controller methods (gate route-reachability covers this; run it)

### Task 2: Store bodies only inside a window
- **spec_ref**: `openspec/changes/outbound-call-log-investigation-window/specs/outbound-call-log/spec.md#requirement-every-outbound-call-is-a-record-with-its-request-and-its-response-req-ocd-001`
- **files**: `lib/Service/CallService.php` (`buildAndPersistCallLog()`, `buildResponseData()`, the buffered path `flushCallLogs()`), `lib/Outbound/Call/CallRecorder.php` (`record()`, `appendAttempt()`), `lib/Outbound/Call/BodyCapturePolicy.php` (new: `isOpen(array $sourceData, DateTimeInterface $now): bool`, `strip(array $request, array $response): array`)
- **acceptance_criteria**:
  - GIVEN no window WHEN a call returns 200 or 500 THEN `request.body`, `request.json`, `request.form_params`, `request.multipart` and `response.body` are absent from the stored record, and `bodyCaptured` is false
  - GIVEN an open window WHEN a call returns THEN both bodies are stored, `bodyCaptured` is true and `bodyExpiresAt` is window end plus the body retention
  - GIVEN a window that ended a minute ago WHEN a call returns THEN no body is stored
  - GIVEN a settings read that throws WHEN a call returns THEN no body is stored
  - GIVEN the buffered path WHEN the batch is flushed THEN the same rule applied to every buffered record
  - GIVEN any call WHEN the caller reads the returned entity THEN it still carries the full response for processing, as today
- [ ] Implement
- [ ] Test: PHPUnit `tests/Unit/Service/CallServiceBodyCaptureTest.php` (`testAFailureOutsideAWindowStoresNoBody`, `testASuccessInsideAWindowStoresBothBodies`, `testACallAfterTheWindowEndsStoresNoBody`, `testABrokenSettingsReadStoresNoBody`, `testBufferedRecordsFollowTheSameRule`). Assert on the array handed to `ObjectService::saveObject()`. Double OpenRegister's `ObjectService` with `environmentAwareDouble`, after reading its real `saveObject()` signature; do not hand-roll a stub.
- [ ] The first test, `testAFailureOutsideAWindowStoresNoBody`, must be shown failing on `development` before the change. Paste the failing line in the PR body.

### Task 3: Keep a failed call replayable
- **spec_ref**: `openspec/changes/outbound-call-log-investigation-window/specs/outbound-call-log/spec.md#requirement-captured-bodies-age-out-and-the-record-stays-req-ocd-009`
- **files**: `lib/Service/CallService.php`, `lib/Outbound/Call/CallReplayService.php` (`requestOf()` reads `replayRequest` first), `lib/Controller/CallLogController.php` (omit `replayRequest` from the detail unless the reader holds the replay permission and the record was captured)
- **acceptance_criteria**:
  - GIVEN a failed call outside a window WHEN stored THEN `replayRequest` holds the sent request with secrets redacted
  - GIVEN that record WHEN a reader without the replay permission opens the detail THEN `replayRequest` is not in the response
  - GIVEN a replay that succeeds WHEN the attempt is appended THEN `replayRequest` is removed
- [ ] Implement
- [ ] Test: PHPUnit `CallReplayServiceTest::testASuccessfulReplayRemovesTheReplayRequest`, `CallLogControllerTest::testTheReplayRequestIsHiddenFromAReader`; the existing replay tests stay green

### Task 4: Age the bodies out
- **spec_ref**: `openspec/changes/outbound-call-log-investigation-window/specs/outbound-call-log/spec.md#requirement-captured-bodies-age-out-and-the-record-stays-req-ocd-009`
- **files**: `lib/BackgroundJob/LogCleanUpTask.php`
- **acceptance_criteria**:
  - GIVEN a record past `bodyExpiresAt` WHEN the task runs THEN both bodies and `replayRequest` are removed, `bodyCaptured` is false, `bodyExpiredAt` is set, and the record remains
  - GIVEN a record past its error retention WHEN the task runs THEN `replayRequest` is removed even when no window applied
- [ ] Implement
- [ ] Test: PHPUnit `tests/Unit/BackgroundJob/LogCleanUpTaskTest.php` (`testExpiredBodiesAreStrippedAndTheRecordKept`, `testReplayRequestEndsWithTheErrorRetention`)
- [ ] Wiring: assert the task is still registered in `appinfo/info.xml` `<background-jobs>` and runs the new step from `run()`

## Verification

The building agent follows `openspec/woo-build-rules.md`:

- [ ] Own clone, `git checkout --no-track -b <branch> origin/development`, `TMPDIR` a sibling outside the clone.
- [ ] PHPUnit judged by the `Tests:` line, or with `--no-coverage`; a green suite exits 1 without a coverage driver.
- [ ] `run-hydra-gates.sh --base origin/development`, counting the gates that ran.
- [ ] Once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, `npm run lint`, `npm run format`, `npm run check:l10n`, `npm run check:l10n-js`, `npm run check:manifest`, `npm run check:schema-l10n`.
- [ ] CI runs the gates on the full tree; the coverage guard needs a test for every added statement.
- [ ] `openspec validate outbound-call-log-investigation-window --type change --strict` passes.
- [ ] One PR, `--base development`; merge `development` in, never rebase; no `Co-Authored-By` trailer.
- [ ] Done means merged on `development` with CI green. Row 13.23 is `production` only once a store release carries it.

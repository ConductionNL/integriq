# Tasks: connectors-lti-platform-launch

Kind: code. Size M. Rows `learniq:cont-embed-external-lti-tool` and
`learniq:cont-lti-grades-come-back`.

### Task 1: The launch event and the signed hint
- **spec_ref**: openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-a-sibling-app-starts-a-platform-launch-with-a-typed-event-req-ltil-001
- **files**: `lib/Event/LtiLaunchRequestedEvent.php`, `lib/Listener/LtiLaunchRequestedListener.php`, `lib/Service/Lti/LtiPlatformHint.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN an approved tool and a signed-in user WHEN the event is raised THEN the result is a form to the tool's `oidcLoginUrl` with the six fields
  - GIVEN a suspended tool WHEN the event is raised THEN it is refused naming the status
  - GIVEN a hint older than five minutes WHEN it is verified THEN it is rejected as expired
- [x] Implement
  - Built by #2243 (4ad84c67, 28 Sep): `LtiLaunchRequestedEvent`, `lib/EventListener/LtiLaunchRequestedListener.php` (the task named `lib/Listener/`; the file sits in `lib/EventListener/` beside the other listeners), `LtiPlatformHint` (HMAC through `ICrypto`, five minutes, never stored), registered in `Application::register()`.
- [x] Test (PHPUnit on the listener and the hint, with a real event object)
  - `tests/Unit/Service/Lti/LtiPlatformLaunchTest.php` constructs the real event: `testLaunchEventReturnsLoginInitiationToToolOidcLoginUrl`, `testSuspendedToolIsRefusedNamingItsStatus`, `testLaunchForAnotherUserIsRefused`, `testUnknownDeploymentIsRefused`, `testHintOlderThanFiveMinutesIsRejectedAsExpired`, `testTamperedHintIsRejected`.

### Task 2: The authorization endpoint
- **spec_ref**: openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-the-platform-authorizes-the-tools-login-redirect-and-posts-the-launch-token-req-ltil-002
- **files**: `lib/Controller/LtiPlatformController.php`, `appinfo/routes.php`, `templates/lti-autopost.php`, `lib/Service/Lti/LtiLaunchService.php` (optional `nonce` on `initiatePlatformLaunch()`)
- **acceptance_criteria**:
  - GIVEN a valid redirect from the tool WHEN the endpoint runs THEN the answer is a form posting an id_token with the tool's nonce and its state to the redirect URI
  - GIVEN another user's hint, a bad redirect URI or a missing nonce WHEN the endpoint runs THEN an error page names the check and nothing is posted
- [x] Implement
  - Built by #2243: `LtiPlatformController::authorize()` on GET and POST `/api/lti/platform/authorize`, `LtiPlatformLoginService` (user, hint, client id, redirect URI, nonce and state checks), `templates/lti-autopost.php` and `templates/lti-error.php`, the optional nonce on `initiatePlatformLaunch()`.
  - Still owed from this task: the error page strings are not in `l10n/en.json` / `l10n/nl.json` yet.
- [ ] Test (PHPUnit per check; `tests/e2e/lti-platform-launch.spec.ts` against a reference tool fixture)
  - PHPUnit half done: `testAuthorizePostsIdTokenWithToolNonceAndState`, `testAuthorizeRefusesAnotherUsersHint`, `testAuthorizeRefusesUnregisteredRedirectUri`, `testAuthorizeRefusesMissingNonce`, `testAuthorizeRefusesAnotherClientId` in `LtiPlatformLaunchTest`. Open: the e2e spec and the reference tool fixture do not exist yet.

### Task 3: The launch claims and the grade service claim
- **spec_ref**: openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-a-launched-tool-can-send-a-grade-back-to-the-placement-req-ltil-003
- **files**: `lib/Service/Lti/LtiLaunchService.php` (claims builder), `lib/Service/Lti/LtiAgsService.php` (line item read for a placement id)
- **acceptance_criteria**:
  - GIVEN a resource link launch WHEN the id_token is built THEN it has `resource_link.id` the placement id, LIS roles, context, return URL and the grade service claim
  - GIVEN a score posted to that line item WHEN it is received THEN the CloudEvent names the placement as `lineItemId`
- [x] Implement
  - 2026-09-29: `LtiPlatformLoginService::agsEndpointClaim()` adds `https://purl.imsglobal.org/spec/lti-ags/claim/endpoint` with `lineitem` = this deployment's `lti#agsLineItem` URL for the placement id and `scope` = `lineitem.readonly` + `score` (`lineitems` is left out: no line item container route). `lti#token` now serves a conformant request without the former `deployment_id` parameter, scoping the token to the tool's only deployment (a tool with several still names one), and grants `lineitem.readonly`; `lti#agsLineItem` accepts it.
- [ ] Test (integration test launching the reference tool fixture and posting a score)
  - Not yet: the live run against a reference tool is pending. PHPUnit pins the pieces: `LtiPlatformLaunchTest::testAuthorizePostsIdTokenWithToolNonceAndState` (claim, URL resolved from routes.php, every scope grantable), `LtiAgsServiceTest::testConformantTokenRequestIsScopedToTheToolsOnlyDeployment` and `testAToolWithSeveralDeploymentsMustNameOne`, `LtiControllerInboundTest::testAConformantTokenRequestWithoutDeploymentIdIsServed`.

### Task 4: Redirect URIs and the platform details view
- **spec_ref**: openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-an-administrator-can-give-a-tool-the-platform-details-it-needs-req-ltil-004
- **files**: `lib/Settings/integriq_register.json` (`lti_tool` 1.3.0, `redirectUris`), `lib/Settings/integriq_mock_register.json`, the LTI tool detail view in the registration catalogue, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a tool with no redirect URIs WHEN a launch uses its `launchUrl` THEN it is accepted
  - GIVEN the tool's detail view WHEN an administrator opens it THEN the six values show with copy actions
- [ ] Implement
  - Half built: `LtiPlatformLoginService::isRegisteredRedirectUri()` reads `redirectUris` when present and otherwise allows only the `launchUrl` (`testToolWithoutRedirectUrisMayOnlyUseItsLaunchUrl`). Open: `lti_tool` is still 1.2.0 with no `redirectUris` property, so a value an administrator sets is not part of the schema; and the detail view with the six platform values and copy actions does not exist.
- [ ] Test (`tests/validate-register.js`; `tests/e2e/lti-platform-launch.spec.ts`)

### Task 5: Hand learniq its half
- **spec_ref**: openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-a-sibling-app-starts-a-platform-launch-with-a-typed-event-req-ltil-001
- **files**: an issue on ConductionNL/learniq naming the event, the form rendering, the poll job's `lineItemId` lookup and `lti_ags_subscription_id`
- **acceptance_criteria**:
  - GIVEN the merged integriq change WHEN the issue is opened THEN it quotes the event constructor and result shape and links this change
- [x] Implement
  - No issue was needed: learniq built its half on its own. Read on learniq `development` ac27f3f (2 Oct): `lib/Controller/LtiToolPlacementController.php` raises `OCA\Integriq\Event\LtiLaunchRequestedEvent` by name (`LAUNCH_EVENT`), reads the login initiation or the refusal back, and `lib/BackgroundJob/LtiAgsScorePollJob.php` resolves the placement from the score's `lineItemId` with `lti_ags_subscription_id`.
- [x] Test (the learniq issue exists and links back)
  - Replaced by the read above: the event name, the accessors learniq calls and the `lineItemId` lookup are on learniq's development branch.

## Verification

- `openspec validate connectors-lti-platform-launch --type change --strict`
- With learniq's half applied: launch the IMS LTI 1.3 reference tool from a
  lesson, post a score from it, and read the concept grade in learniq.
- `composer check:strict` and `npm run lint` once before push.

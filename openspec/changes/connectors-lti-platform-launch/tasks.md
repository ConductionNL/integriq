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
- [ ] Implement
- [ ] Test (PHPUnit on the listener and the hint, with a real event object)

### Task 2: The authorization endpoint
- **spec_ref**: openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-the-platform-authorizes-the-tools-login-redirect-and-posts-the-launch-token-req-ltil-002
- **files**: `lib/Controller/LtiPlatformController.php`, `appinfo/routes.php`, `templates/lti-autopost.php`, `lib/Service/Lti/LtiLaunchService.php` (optional `nonce` on `initiatePlatformLaunch()`)
- **acceptance_criteria**:
  - GIVEN a valid redirect from the tool WHEN the endpoint runs THEN the answer is a form posting an id_token with the tool's nonce and its state to the redirect URI
  - GIVEN another user's hint, a bad redirect URI or a missing nonce WHEN the endpoint runs THEN an error page names the check and nothing is posted
- [ ] Implement
- [ ] Test (PHPUnit per check; `tests/e2e/lti-platform-launch.spec.ts` against a reference tool fixture)

### Task 3: The launch claims and the grade service claim
- **spec_ref**: openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-a-launched-tool-can-send-a-grade-back-to-the-placement-req-ltil-003
- **files**: `lib/Service/Lti/LtiLaunchService.php` (claims builder), `lib/Service/Lti/LtiAgsService.php` (line item read for a placement id)
- **acceptance_criteria**:
  - GIVEN a resource link launch WHEN the id_token is built THEN it has `resource_link.id` the placement id, LIS roles, context, return URL and the grade service claim
  - GIVEN a score posted to that line item WHEN it is received THEN the CloudEvent names the placement as `lineItemId`
- [ ] Implement
- [ ] Test (integration test launching the reference tool fixture and posting a score)

### Task 4: Redirect URIs and the platform details view
- **spec_ref**: openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-an-administrator-can-give-a-tool-the-platform-details-it-needs-req-ltil-004
- **files**: `lib/Settings/integriq_register.json` (`lti_tool` 1.3.0, `redirectUris`), `lib/Settings/integriq_mock_register.json`, the LTI tool detail view in the registration catalogue, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a tool with no redirect URIs WHEN a launch uses its `launchUrl` THEN it is accepted
  - GIVEN the tool's detail view WHEN an administrator opens it THEN the six values show with copy actions
- [ ] Implement
- [ ] Test (`tests/validate-register.js`; `tests/e2e/lti-platform-launch.spec.ts`)

### Task 5: Hand learniq its half
- **spec_ref**: openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-a-sibling-app-starts-a-platform-launch-with-a-typed-event-req-ltil-001
- **files**: an issue on ConductionNL/learniq naming the event, the form rendering, the poll job's `lineItemId` lookup and `lti_ags_subscription_id`
- **acceptance_criteria**:
  - GIVEN the merged integriq change WHEN the issue is opened THEN it quotes the event constructor and result shape and links this change
- [ ] Implement
- [ ] Test (the learniq issue exists and links back)

## Verification

- `openspec validate connectors-lti-platform-launch --type change --strict`
- With learniq's half applied: launch the IMS LTI 1.3 reference tool from a
  lesson, post a score from it, and read the concept grade in learniq.
- `composer check:strict` and `npm run lint` once before push.

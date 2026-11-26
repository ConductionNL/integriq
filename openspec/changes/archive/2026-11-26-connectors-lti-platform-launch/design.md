# Design: connectors-lti-platform-launch

Kind: code. Size M. Read at integriq `development` 92f282bc and learniq
`development`.

## Where it fits

| Piece | File | Today |
|---|---|---|
| Tool-role routes | `appinfo/routes.php:283-285`, `lib/Controller/LtiController.php:163` `login()`, :197 `launch()` | receive launches from an outside platform |
| Platform launch | `lib/Service/Lti/LtiLaunchService.php:419` `initiatePlatformLaunch()` | no route, own nonce at :449, no state |
| Grades in | `appinfo/routes.php:286-287`, `lib/Service/Lti/LtiAgsService.php:282` `receiveScore()` | emits `nl.conduction.lti.ags.score.received` |
| Registrations | `lib/Settings/integriq_register.json` `lti_tool` 1.2.0 (`clientId`, `oidcLoginUrl`, `launchUrl`, `status`), `lti_deployment` 1.1.0 | no redirect URI list |
| Resolver | `lib/Service/Lti/LtiRegistrationResolverService.php:204` `findDeploymentByUuid()`, :240 `findRegistrationByUuid()` | reused |
| Event precedent | `lib/Event/DocumentRenderRequestedEvent.php`, `lib/AppInfo/Application.php:283-291` | the shape to copy |
| learniq caller | learniq `lib/Controller/LtiToolPlacementController.php:207`, :296 | posts to a route that never existed |

## D1. learniq reaches integriq by a typed event

learniq's `launch()` is itself an HTTP action serving the learner's browser.
Calling integriq from there over HTTP is a loopback to the same server with a
stored token, which ADR-041 rules out for cross-app commands. The event is
`OCA\Integriq\Event\LtiLaunchRequestedEvent`:

- Constructor: `sourceApp`, `placementId`, `deploymentUuid`, `userId` (the
  Nextcloud uid), `messageType` (`LtiResourceLinkRequest` or
  `LtiDeepLinkingRequest`), `role` (`Learner` or `Instructor`), `contextId`
  and `contextTitle` (the course), and `returnUrl` (where the tool sends the
  learner back).
- Result slot: `setLoginInitiation(array)` holding `{formActionUrl, method,
  fields}`, `refuse(code, reason)`, `getRefusal()`, `isHandled()`.

The listener refuses a deployment that does not exist, a tool whose `status`
is not `approved` (REQ-LTI-011), or a user who is not signed in as `userId`.

Rejected: a route in the shape learniq posts today. Its answer,
`{formActionUrl, idToken}`, is the shape of a launch that skips the login
step, so serving it would ship the defect of D2 under a new name.

## D2. The login step is not optional

`initiatePlatformLaunch()` signs an id_token with a nonce it makes up and
returns the tool's `launchUrl` to post it to. An LTI 1.3 tool starts every
launch at its own login endpoint, keeps a `state` and a `nonce`, and rejects
an id_token whose nonce it did not issue. REQ-LTI-006 already says the
platform MUST perform that redirect.

So the listener does not return an id_token. It returns a login initiation:
a form to the tool's `oidcLoginUrl` with `iss` (this platform's issuer),
`login_hint` and `lti_message_hint` (both opaque to the tool), the tool's
`target_link_uri` (its `launchUrl`), `client_id` and `lti_deployment_id`.

`login_hint` and `lti_message_hint` are one signed, five-minute token,
`LtiPlatformHint`, over the user id, placement, deployment, message type,
role and context, signed with the instance secret through `ICrypto`. It is
never stored.

## D3. The authorization endpoint

The tool answers by redirecting the browser to
`/api/lti/platform/authorize` with `scope=openid`, `response_type=id_token`,
`response_mode=form_post`, `prompt=none`, `client_id`, `redirect_uri`,
`login_hint`, `lti_message_hint`, `state` and `nonce`. A new
`LtiPlatformController::authorize()` (`#[NoAdminRequired]`,
`#[NoCSRFRequired]`, both verbs) checks, in order:

1. The browser has a Nextcloud session, and its user is the hint's user. A
   top-level navigation carries the session cookie, so this holds for a
   normal login.
2. The hint's signature and expiry.
3. `client_id` is the `lti_tool` the hint's deployment names, and the tool is
   `approved`.
4. `redirect_uri` is in the tool's registered redirect URIs.
5. `nonce` and `state` are present.

It then calls `initiatePlatformLaunch()` with the tool's `nonce` passed in
and the claims of D4, and answers with an auto-submitting HTML form posting
`id_token` and `state` to `redirect_uri`. Any failure answers with a plain
error page naming the check, never with a form.

`initiatePlatformLaunch()` gains an optional `nonce` parameter used instead of
the generated one. Its existing callers keep today's behaviour.

## D4. The claims a tool needs, including grades

For `LtiResourceLinkRequest` the id_token carries, besides what
`initiatePlatformLaunch()` already sets: `resource_link` with the placement
id as `id`, `roles` mapped from the role (Learner or Instructor in the LIS
vocabulary), `target_link_uri`, `context` with the course id and title,
`launch_presentation.return_url`, and the grade service claim with
`scope` (line item read and score) and `lineitem` set to this deployment's
`lti#agsLineItem` URL with the placement id as `lineItemId`.

The tool then gets a token at `lti#token` and posts scores to `lti#agsScore`,
which already emits the CloudEvent with `lineItemId`. learniq's poll job
reads that and writes the grade; that is its half.

## D5. Redirect URIs and the platform details

`lti_tool` gains `redirectUris`, an array of URIs, and moves to 1.3.0. An
empty list means `launchUrl` is the only allowed value, so existing tools
keep working. The registration's detail view shows what the tool's
administrator needs: issuer, client id, deployment id, the authorization URL
of D3, the token URL and the key set URL from `lti#jwks`.

## Declarative versus imperative

The launch is a protocol exchange and stays imperative. The redirect URI list
is configuration on the registration schema.

## Seed data

`lti_tool` moves to 1.3.0 with `redirectUris`. The mock register's demo
tool gets one redirect URI and `approved` status, and a demo deployment
references it, so the launch can be tried end to end against an LTI
reference tool.

## Risks

- A tool opened in a frame. Browsers that block cookies in third-party
  frames drop the Nextcloud session on the redirect to the authorization
  endpoint, and check 1 fails. The error page says to open the tool in a new
  tab, which learniq's `launchMode` already offers.
- Clock skew between instance and tool breaks the five-minute hint. The
  window and the id_token's `exp` are the same, and the error names expiry.
- Changing `initiatePlatformLaunch()` touches REQ-LTI-006 code. The nonce
  parameter is optional so the Deep Linking path is unchanged.

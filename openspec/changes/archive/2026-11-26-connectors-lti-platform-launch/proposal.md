---
kind: code
depends_on: []
---

# Proposal: connectors-lti-platform-launch

## Summary

A teacher places an external LTI 1.3 tool in a learniq lesson, and nothing
opens. learniq posts to `api/lti/deployments/{id}/launch`, which integriq
never served. Integriq's own `api/lti/{deployment}/launch` is the other end
of the protocol: it receives launches as a tool. And the platform-side
launch method it has would not open a standards-compliant tool even if
something called it, because it skips the OIDC login step and never gives the
tool back its own nonce. This change builds the platform launch properly: a
typed event learniq raises, a login initiation the browser posts to the tool,
an authorization endpoint the tool redirects back to, and an id_token that
carries the grade service claim, so a tool opens and its grade comes back.

## Why

This change covers two rows, both from learniq's matrix.

- `learniq:cont-embed-external-lti-tool`, "Drop an external tool into a
  lesson over LTI 1.3", rated no and none for learniq with integriq as owner.
  Five competitors rate it yes:
  - moodle: "public/mod/lti/version.php:53 (External tool activity) with LTI
    1.3 registration".
  - moodle-workplace: https://docs.moodle.org/502/en/External_tool supports
    "LTI Advantage (also called LTI 1.3)" with automated registration.
  - ilias: "components/ILIAS/LTIConsumer/classes/class.ilLTIConsumeProviderFormGUI.php:207-220
    (Version 1.3 option, deep linking)", driven on the lab instance.
  - totara: https://totara.help/docs/add-an-external-tool-at-site-level
    "Totara supports LTI 1.0, LTI 1.1, LTI 1.3, LTI 2.0 and the LTI Advantage
    extensions".
  - chamilo: "public/plugin/ImsLti/login.php:1", after installing the IMS/LTI
    client plugin.
- `learniq:cont-lti-grades-come-back`, "Let an external tool send its grade
  back into your gradebook". Four competitors rate it yes: moodle
  ("public/mod/lti/service/gradebookservices/version.php:30"),
  moodle-workplace (https://docs.moodle.org/502/en/External_tool "the
  connecting site will send back grades to Moodle's gradebook"), totara
  (https://totara.help/docs/external-tool-activity-settings "tool provider
  can add, update, read, and delete grades associated only with this external
  tool instance") and chamilo ("public/plugin/ImsLti/ags2.php:1").

No demand row. The decision: "learniq launches through
api/lti/deployments/{id}/launch and integriq serves /api/lti/{deployment}/launch,
so no tool opens." And: "Grades only come back from a tool that opened."

## What each side has

learniq, read on its `development` branch:

- `lib/Controller/LtiToolPlacementController.php:153`
  `OPENCONNECTOR_LAUNCH_PATH = '/apps/openconnector/api/lti/deployments/%s/launch'`.
  `launch()` (:207) resolves the `LtiToolPlacement`, and
  `callOpenConnectorLaunch()` (:296) posts `{"subject", "messageType"}` with
  a Bearer token and expects `{"formActionUrl", "idToken"}`, which it hands
  to the browser unparsed with its own `launchMode` added (:250).
- Its docblock (:115-150) records, twice verified against integriq, that the
  route never existed and that repointing it at integriq's
  `api/lti/{deployment}/launch` would swap a 404 for a 400.
- Grades: `lib/BackgroundJob/LtiAgsScorePollJob.php` pulls
  `nl.conduction.lti.ags.score.received` CloudEvents through
  `lib/Service/LtiAgsPullClient.php:68` (`api/events/subscriptions/%s/pull`,
  which integriq serves) and resolves the placement from the event's
  deployment (`resolvePlacementForMessage()`, :305).

integriq, at `development` 92f282bc:

- `appinfo/routes.php:283-285`: `lti#login` and `lti#launch` are the tool
  role. `LtiController::launch()` (`lib/Controller/LtiController.php:197`)
  is `#[PublicPage]`, reads `id_token` and a `state` cookie, and answers with
  a redirect. It is not a platform launch.
- `LtiLaunchService::initiatePlatformLaunch()`
  (`lib/Service/Lti/LtiLaunchService.php:419`) builds a signed id_token for a
  registered `lti_tool` and returns `{formActionUrl: launchUrl, idToken}`. It
  has no route. It mints its own nonce (:449) instead of the tool's, sends no
  `state`, and adds no resource link, roles or target link claims unless the
  caller passes them.
- `openspec/specs/lti-platform/spec.md` REQ-LTI-006 says the platform launch
  "MUST perform the Platform-side third-party-initiated login redirect to the
  tool's `oidc_login_url`". The code does not.
- The platform-role grade half exists: `lti#token` (:286), `lti#agsScore`
  (:287) and `LtiAgsService::receiveScore()`
  (`lib/Service/Lti/LtiAgsService.php:282`), which emits
  `nl.conduction.lti.ags.score.received`.

## Which path is canonical

Neither. learniq's path never existed, and integriq's path is the tool role
of the same protocol. The canonical platform launch is the one LTI 1.3
defines: the platform starts a third-party login at the tool, the tool
redirects the browser to the platform's authorization endpoint, and the
platform posts the id_token to the tool. This change builds integriq's half
of that, reached from learniq by a typed event (ADR-041), not by a route.

## What this change builds

1. `OCA\Integriq\Event\LtiLaunchRequestedEvent`: placement, deployment,
   user, message type and role, with a result slot holding a login
   initiation form: `{formActionUrl, method, fields}`.
2. The login initiation: the form targets the tool's `oidcLoginUrl` with
   `iss`, `login_hint`, `target_link_uri`, `lti_message_hint`, `client_id`
   and `lti_deployment_id`. The hints are short-lived and signed by integriq.
3. A platform authorization endpoint, `GET` and `POST
   /api/lti/platform/authorize`, that the tool redirects the browser to. It
   checks the signed hints against the signed-in user, the redirect URI
   against the tool's registration, and answers with an auto-submitting form
   posting the id_token and the tool's `state` to the tool.
4. An id_token with the tool's nonce and the claims a resource link launch
   needs, including the grade service endpoint claim pointing at integriq's
   existing AGS routes, with the placement as line item.
5. The platform details an administrator gives the tool, shown on the
   registration: issuer, client id, deployment id, authorization URL, token
   URL and key set URL.

## Out of scope

- Deep Linking content selection. `launchMode: deep-linking` goes through
  the same flow with the other message type, and handling the tool's deep
  linking response stays REQ-LTI-006's second half.
- Dynamic registration.
- learniq's gradebook write. It already exists behind its poll job.

## Sibling half

learniq's half, which this change does not build:

- In `LtiToolPlacementController::launch()`, replace
  `callOpenConnectorLaunch()` with a `class_exists` check on
  `OCA\Integriq\Event\LtiLaunchRequestedEvent`, `dispatchTyped()`, and a read
  of the result slot. Remove `OPENCONNECTOR_LAUNCH_PATH` and the token.
- In the lesson player, render `{formActionUrl, method, fields}` as a form it
  submits, in a new tab or a frame per `launchMode`, instead of posting an
  `id_token` it was handed.
- In `LtiAgsScorePollJob`, resolve the placement from the event's
  `lineItemId`, which this change sets to the placement id, before falling
  back to the deployment, because one deployment can serve many placements.
- Configure `lti_ags_subscription_id`, the event subscription the poll job
  reads, which it needs before any grade arrives.
- `lib/Settings/connections.json` entry `lti` loses `available: false`.

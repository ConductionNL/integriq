# lti-platform Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- lti-13-platform
- connectors-lti-platform-launch

## Purpose

A sibling app opens an external LTI 1.3 tool for a signed-in user through a
standards-compliant platform launch, and the tool can send a grade back.
Rows `learniq:cont-embed-external-lti-tool` and
`learniq:cont-lti-grades-come-back`.

## ADDED Requirements

### Requirement: A sibling app starts a platform launch with a typed event (REQ-LTIL-001)

Integriq MUST publish `OCA\Integriq\Event\LtiLaunchRequestedEvent` carrying
`sourceApp`, `placementId`, `deploymentUuid`, `userId`, `messageType`,
`role`, `contextId`, `contextTitle` and `returnUrl`, and MUST answer it with
a login initiation `{formActionUrl, method, fields}` that targets the tool's
`oidcLoginUrl` with `iss`, `login_hint`, `lti_message_hint`,
`target_link_uri`, `client_id` and `lti_deployment_id`. The hints MUST be one
signed token that expires within five minutes and is never stored. The
listener MUST refuse an unknown deployment, a tool that is not `approved`, or
a `userId` that is not the signed-in user.

#### Scenario: a learner opens an external tool from a lesson
- GIVEN a learner in learniq on a lesson with an LTI placement for an approved tool
- WHEN learniq raises the launch event for that placement and renders the returned form
- THEN the browser arrives at the tool's login endpoint carrying the platform issuer, the client id and the deployment id
- e2e: `tests/e2e/lti-platform-launch.spec.ts`

#### Scenario: a suspended tool does not open
- GIVEN a placement whose tool registration is `suspended`
- WHEN learniq raises the launch event
- THEN the event is refused naming the registration status, and no form is returned
- @e2e exclude an in-process event; covered by PHPUnit on the listener

### Requirement: The platform authorizes the tool's login redirect and posts the launch token (REQ-LTIL-002)

Integriq MUST serve `/api/lti/platform/authorize` for `GET` and `POST`. It
MUST check that the signed-in user is the hint's user, the hint is valid and
unexpired, `client_id` names the hint's approved tool, `redirect_uri` is one
of the tool's registered redirect URIs, and `nonce` and `state` are present.
On success it MUST answer with an auto-submitting form posting a signed
`id_token`, carrying the tool's `nonce`, and the tool's `state` to
`redirect_uri`. On any failure it MUST show an error page naming the failed
check and MUST NOT post anything.

#### Scenario: the tool receives an id_token it accepts
- GIVEN a learner whose browser the tool redirected to the authorization endpoint with its nonce and state
- WHEN the endpoint runs
- THEN the browser posts an id_token carrying that nonce, the placement as resource link and the learner role, with the tool's state, to the tool's redirect URI
- e2e: `tests/e2e/lti-platform-launch.spec.ts`

#### Scenario: another user's hint is refused
- GIVEN a hint issued for one learner
- WHEN a different signed-in user's browser presents it
- THEN the endpoint shows an error naming the user mismatch and posts nothing
- e2e: `tests/e2e/lti-platform-launch.spec.ts`

#### Scenario: an unregistered redirect URI is refused
- GIVEN a tool registration with one redirect URI
- WHEN the authorization request names another
- THEN the endpoint shows an error naming the redirect URI and posts nothing
- @e2e exclude a crafted request a real tool does not send; covered by PHPUnit on LtiPlatformController

### Requirement: A launched tool can send a grade back to the placement (REQ-LTIL-003)

A resource link id_token MUST carry the grade service claim with the line
item read and score scopes and a `lineitem` URL on this deployment's
existing line item route whose `lineItemId` is the placement id. A score the
tool then posts through the existing token and score routes MUST reach the
`nl.conduction.lti.ags.score.received` CloudEvent with that `lineItemId`.

#### Scenario: a quiz tool's score reaches the placement's event
- GIVEN a learner who completed a quiz in a tool launched from a placement
- WHEN the tool gets a token and posts a score to the line item from the id_token
- THEN a `nl.conduction.lti.ags.score.received` CloudEvent is emitted naming the placement as line item and the learner as user
- @e2e exclude a tool-to-platform service call; covered by an integration test against an LTI reference tool fixture

### Requirement: An administrator can give a tool the platform details it needs (REQ-LTIL-004)

The `lti_tool` registration MUST hold a list of redirect URIs, where an empty
list allows only the tool's `launchUrl`. The registration's detail view MUST
show the issuer, client id, deployment ids, authorization URL, token URL and
key set URL a tool's administrator enters on their side.

#### Scenario: an administrator registers a tool at the vendor
- GIVEN an administrator on an LTI tool registration
- WHEN they open its detail view
- THEN they read the six values to paste into the tool's platform registration, each with a copy action
- e2e: `tests/e2e/lti-platform-launch.spec.ts`

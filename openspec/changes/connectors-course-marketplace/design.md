# Design: connectors-course-marketplace

Kind: code. Size M. Read at integriq `development` 92f282bc and learniq
`development`.

## Where it fits

| Piece | File | Today |
|---|---|---|
| LTI schemas | `lib/Settings/integriq_register.json` `lti_tool` 1.2.0, `lti_deployment` 1.1.0 | one deployment per tool and platform |
| Launch | `lib/Service/Lti/LtiLaunchService.php:419` `initiatePlatformLaunch()` | no route; `connectors-lti-platform-launch` adds one |
| Engine | `synchronization-engine` REQ-003 (identity), REQ-010 (deletion guard), REQ-016 (incremental) | reused |
| Broker | `lib/Service/BrokeredCallService.php:442` `prepare()`, :494 `dispatch()` | reused |
| Target | learniq `lib/Settings/learniq_register.json`: `Course`, `Lesson`, `LtiToolPlacement` | written as draft |

## D1. One pattern, three providers

Each provider is a packaged set in `lib/Settings/configurations/`,
`course-marketplace-go1.json`, `course-marketplace-linkedin-learning.json`
and `course-marketplace-udemy-business.json`, in the shape of the ZGW sets:
a source template, a synchronization, mappings, `writesBack: false`, and the
learniq register as target.

The source carries the provider's catalogue API base URL and a
`credentialRef`. Every call goes through the broker. Each template ships in
mock mode with a canned catalogue page, so a demo install shows courses
without a contract with the provider.

The exact catalogue endpoints, paging and auth scheme per provider are
pinned in the task that builds each set, against the provider's published
API documentation, and cited in the set's `description`. This design does
not guess them.

Rejected: one generic "marketplace" source with provider switches in code.
The three APIs differ in paging, identity and auth; three sets on one
pattern keep each difference in configuration.

## D2. What one course becomes in learniq

A provider course maps onto three learniq objects, written in one
synchronization run through three mappings:

- `Course`: `code` from the provider's course id prefixed with the provider
  (`GO1-12345`), `name`, `description`, `language`, `level` from the
  provider's level when it maps and else the set's default, `author` as the
  provider, `license` `all-rights-reserved`, `lifecycle` `draft`.
- `Lesson`: one per course, `order` 1, `contentType` `lti`, `contentRef` the
  placement's UUID, as learniq's schema requires for `lti`.
- `LtiToolPlacement`: `courseId` and `lessonId`, `openconnectorDeploymentId`
  the provider's `lti_deployment`, `launchMode` `resource-link`, and the
  provider's course id carried as the resource link custom parameter the
  provider's tool reads.

The object identity is the provider course id (REQ-003), so a second run
updates rather than duplicates.

Rejected: a lesson whose `contentRef` is the provider's course URL. learniq's
own schema text says a static URL cannot carry a signed launch, and the
provider could not tell who the learner is.

## D3. An administrator imports a selection

A catalogue of thousands imported whole buries a school's own courses. Each
synchronization's `sourceConfig` carries a selection: topic or collection
ids, languages and a free text filter, applied in the provider's query where
its API allows and in the mapping otherwise. The source form shows these
fields for a course marketplace source.

## D4. A withdrawn course is retired, not deleted

When the provider stops offering a course, a hard delete would orphan
learniq's enrolments and completions. The synchronization's deletion step
(REQ-010) is configured to write `lifecycle: archived` on the `Course` and
`retired` on the `LtiToolPlacement`, and only when fetch completeness allows a
deletion at all.

## Declarative versus imperative

The sets, sources and mappings are configuration. The lifecycle values
integriq writes are values learniq's schemas declare, and learniq's lifecycle
engine governs them from there.

## Seed data

integriq's register gains no schema. Each set ships a dormant source in mock
mode, and the mock register gains one `lti_tool` and one `lti_deployment`
per provider, so a demo placement has a deployment to point at.

## Risks

- A provider's LTI registration is per customer. The `lti_deployment` is
  created when the customer registers with the provider, and the set refuses
  to run without one, naming the missing deployment.
- Writing into learniq's register from integriq depends on learniq's schema
  authorization allowing the synchronization's acting user to create
  `Course`, `Lesson` and `LtiToolPlacement`. The set's install check reads
  that and says so.
- Provider catalogue terms may forbid storing descriptions. Each set's
  mapping keeps to the fields the provider's terms allow, recorded in the set.

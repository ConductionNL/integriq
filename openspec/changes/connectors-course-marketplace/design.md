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

## Addendum (2 Oct 2026): what the code reading changed

Read at integriq `development` de9d49e5, learniq `development` ac27f3f and openregister `development` e71562c6
while building Tasks 1 to 4. Four places where the design above could not be
built as written, and what was built instead.

### D5. The sets live in a register.d fragment

`lib/Settings/configurations/` is read by `ZgwSetCatalogue` for the six ZGW
sets only, so a marketplace file there would never be installed. The three
sets are one fragment, `lib/Settings/register.d/course-marketplace-connectors.json`,
which OpenRegister imports on install like every other fragment: per provider
one source, three mappings and three synchronizations.

### D6. The three objects of one course name each other by derived ids

One synchronization writes one target object per source item, and a seeded
mapping cannot look up another synchronization's contract by slug (a contract
stores the synchronization's object id, which the seed does not know). So each
mapping sets the object's `id` itself, derived from the provider course id
with a new mapping function, `uuidFor()`, a UUID v5 under a fixed integriq
namespace: `uuidFor('course-marketplace:go1:course:' ~ id)`. OpenRegister
takes a supplied `id` on create. The course, lesson and placement can then name
each other before any of them exists, the order of the three synchronizations
does not matter, and a second run lands on the same objects. The placement
carries both `courseId` and `lessonId`, and the lesson's `contentRef` is the
placement's id.

### D7. What the seed does not guess

- `tenant_id` is learniq's single-tenant default,
  `00000000-0000-4000-8000-000000000000`; a multi-tenant install sets its own
  in the three mappings.
- `openconnectorDeploymentId` is empty. It is the `lti_deployment` this
  install registered with the provider, and learniq refuses a placement
  without it, so a run before it is set writes no placement.
- `lifecycle` is not written. learniq starts a new object in `draft` and says
  its lifecycle engine owns the value; writing `draft` on every run would put
  a published course back in draft.

### D8. The provider course id has no field on the placement

learniq's `LtiToolPlacement` 0.1.2 has no field for a resource link custom
parameter, so the provider course id is not stored there. It stays the origin
id of the placement synchronization's contract (the contract maps it to the
placement's id). Telling the provider's tool which course to open at launch is
a new task (Task 7): the launch claims read that contract for the placement
and send the provider course id as an LTI custom claim.

Built as a declaration, not a marketplace special case: a synchronization
that writes placements sets `targetConfig.ltiCustomOriginIdParameter` to the
custom parameter name its tool reads, and the launch looks up the contract
whose `targetId` is the placement. The three sets declare `course_id`. None
of the three providers' published LTI documentation pinned the name, so it is
a value an administrator changes on the synchronization, not a constant.

### Selection and retirement

The selection is the synchronization's `conditions` (JsonLogic on the
provider's course), so it is applied the same way for all three providers
whatever their query language allows. A course the provider stops offering
is retired, never deleted: each synchronization keeps the `keepAndFlag`
policy and declares `sourceConfig.disappearanceValues`, which the engine
writes onto the target when the policy runs (`{"lifecycle": "archived"}` on
the Course, `{"lifecycle": "retired"}` on the Lesson and the placement; those
are learniq's own enum values). The policy only runs behind the incremental,
fetch-completeness and ratio guards, so an incomplete fetch retires nothing.
A course that comes back is unflagged but stays `archived`: the mappings never
write `lifecycle`, so republishing it is the administrator's choice.

The design's mock mode is not built: a source's `mock` flag is not honoured by
the synchronization engine's list fetch. Demo data is Task 6's mock register
entries, and the tests run the mappings over canned catalogue pages in
`tests/fixtures/course-marketplace/`.

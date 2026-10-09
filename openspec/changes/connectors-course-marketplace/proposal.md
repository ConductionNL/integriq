---
kind: code
depends_on: [connectors-lti-platform-launch]
---

# Proposal: connectors-course-marketplace

## Summary

Two tenders ask for outside providers' courses inside the learning
environment, and Totara and iSpring offer course marketplaces from Go1,
LinkedIn Learning and Udemy. Integriq has an LTI 1.3 platform adapter and a
synchronization engine, and no connector that brings a provider's catalogue
in. This change adds course marketplace connectors: per provider, a source
template, a synchronization that writes the provider's catalogue into
learniq as draft courses, and the launch placement each course opens
through.

## Why

Row `learniq:cont-outside-provider-catalogue`, "Offer an outside training
provider's catalogue inside the platform", rated no and none for learniq
with integriq as owner. It comes from learniq's matrix. Demand: tender
https://www.tenderned.nl/aankondigingen/overzicht/411287. The matrix note:
"ProRail requirement 84955 and Grafisch Lyceum Rotterdam requirement 206638:
external providers' courses offered inside the LMS." Two competitors rate it
yes:

- totara: https://totara.help/docs/what-are-content-marketplaces: "The
  content marketplace allows you to access a range of free and paid-for
  learning content from trusted content partners, and integrate this
  SCORM-based content into your catalogue", with GO1 and LinkedIn Learning
  marketplaces.
- ispring-learn:
  https://ispringhelpdocs.com/ispring-learn/course-marketplaces-100862390.html:
  "Services > Course Marketplaces offers Go1 ("thousands of courses from over
  250 providers", https://ispringhelpdocs.com/ispring-learn/go1-92995627.html),
  LinkedIn Learning and Udemy".

The row's provider note: "Launching outside courses would ride integriq's LTI
adapter, which has no launch endpoint." That launch endpoint is
`connectors-lti-platform-launch`, which this change depends on.

## What integriq already has

- The LTI 1.3 schemas, `lti_platform`, `lti_tool` (1.2.0) and
  `lti_deployment` (1.1.0) in `lib/Settings/integriq_register.json`, and the
  platform-role launch as a service method,
  `LtiLaunchService::initiatePlatformLaunch()`
  (`lib/Service/Lti/LtiLaunchService.php:419`), with no route to it.
- The synchronization engine with incremental mode, deletion guards and file
  handling (`synchronization-engine` REQ-004, REQ-010, REQ-016).
- The credential broker for provider API keys (ADR-064,
  `lib/Service/BrokeredCallService.php`).
- No source template, mapping or synchronization for any course provider
  (`grep -ril "go1\|linkedin\|udemy" lib/Settings` finds none).

What learniq has, on its `development` branch: `Course` (0.4.1, required
`code`, `name`, `level`, `language`, `tenant_id`, `lifecycle` draft by
default), `Lesson` (0.4.2, `contentType` includes `lti`, `contentRef` for
`lti` "MUST be the UUID of an LtiToolPlacement object") and
`LtiToolPlacement` (0.1.0, `openconnectorDeploymentId`, `launchMode`).

## What this change builds

1. Source templates for three providers named by the competitor evidence:
   Go1, LinkedIn Learning and Udemy Business, each with a broker reference
   for its API credential and in mock mode by default.
2. A catalogue synchronization per provider that writes each offered course
   into learniq as a `Course` in `draft`, one `Lesson` of type `lti`, and one
   `LtiToolPlacement` pointing at the provider's `lti_deployment`.
3. A selection filter on each synchronization, so an administrator imports a
   chosen part of a catalogue of thousands, not all of it.
4. Removal handling: a course the provider withdraws is retired in learniq,
   never deleted, so enrolments and completions keep their course.

## Out of scope

- The launch itself. `connectors-lti-platform-launch` builds the route
  learniq calls.
- Licence and seat management with the provider.
- SCORM package download from a provider. LTI launch keeps the content and
  its licence check at the provider.

## Sibling half

learniq builds the learner side: showing the imported courses in its course
catalogue with the provider named, letting an administrator publish the ones
it offers, and enrolling a learner. Those are learniq's screens and learniq's
lifecycle. This change only writes draft objects learniq already defines.

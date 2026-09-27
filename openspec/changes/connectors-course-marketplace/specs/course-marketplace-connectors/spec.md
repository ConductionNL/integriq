# course-marketplace-connectors Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- connectors-course-marketplace

## Purpose

Integriq brings a chosen part of an outside training provider's catalogue
into learniq as draft courses that open through LTI, and keeps it current.
Row `learniq:cont-outside-provider-catalogue`.

## ADDED Requirements

### Requirement: A provider's catalogue arrives in learniq as draft courses that launch through LTI (REQ-CMKT-001)

For Go1, LinkedIn Learning and Udemy Business, integriq MUST ship a source
template and a synchronization that write each selected provider course into
learniq as one `Course` in `draft`, one `Lesson` with `contentType` `lti`
and one `LtiToolPlacement` pointing at the provider's `lti_deployment`. The
provider's course id MUST be the object identity. Every provider call MUST
go through the credential broker.

#### Scenario: an administrator imports a Go1 collection
- GIVEN a Go1 source with a brokered key and a registered Go1 `lti_deployment`
- WHEN the administrator runs the Go1 synchronization with one collection selected
- THEN each course in that collection exists in learniq as a draft course with one LTI lesson and a placement on the Go1 deployment
- e2e: `tests/e2e/course-marketplace.spec.ts`

#### Scenario: a second run updates instead of duplicating
- GIVEN courses imported from LinkedIn Learning
- WHEN the synchronization runs again and one title changed at the provider
- THEN that course's name changes and no second course appears
- @e2e exclude identity handling is an engine property; covered by PHPUnit on the mapping and REQ-003

#### Scenario: no deployment, no run
- GIVEN a Udemy Business source with no `lti_deployment`
- WHEN the synchronization runs
- THEN it writes nothing and its log names the missing deployment
- e2e: `tests/e2e/course-marketplace.spec.ts`

### Requirement: An administrator imports a selection, not the whole catalogue (REQ-CMKT-002)

A course marketplace source MUST accept a selection of collections or
topics, languages and a text filter, and the synchronization MUST import
only courses that match it.

#### Scenario: only Dutch courses on privacy arrive
- GIVEN a selection of language `nl` and the text filter `AVG`
- WHEN the synchronization runs
- THEN only Dutch courses matching `AVG` are written to learniq
- e2e: `tests/e2e/course-marketplace.spec.ts`

### Requirement: A withdrawn course is retired, never deleted (REQ-CMKT-003)

When a previously imported course is no longer offered, the synchronization
MUST set the `Course` to `archived` and its `LtiToolPlacement` to `retired`,
MUST NOT delete either object, and MUST only do so when fetch completeness
allows a deletion in that run.

#### Scenario: learners keep their completion when a course leaves Go1
- GIVEN an imported Go1 course with completions in learniq
- WHEN Go1 stops offering it and the next complete run finishes
- THEN the course is archived, its placement retired, and every completion still names it
- @e2e exclude a lifecycle transition checked on stored objects; covered by an integration test on the synchronization

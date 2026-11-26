---
kind: code
depends_on: []
---

# Proposal: connectors-hr-staff-sync

## Summary

A school group buying an HRM system wants it to keep the timetabling system
in step: who works here, on which contract, at which school, and who is on
leave. Integriq's roster sources carry lessons only, and nothing moves staff
data anywhere. This change pushes staff and approved leave from humaniq, the
fleet's HR app, into Untis through its documented teacher and teacher absence
APIs, keeps them in step on every change, and records per timetabling system
what else can and cannot be reached.

## Why

Row `planninq:tt-hr-sync`, "Keep staff, contracts, deployment and leave in
step between the HR system and the timetabling system", rated no and none,
with integriq as owner. It comes from planninq's matrix. Demand: tender
https://www.tenderned.nl/aankondigingen/overzicht/328706, Scholengroep Pontes,
"HRM systeem en beheer": "Het realiseren van de benodigde koppelingen met
andere systemen ... tenminste Foleta, de arbodienst, Zermelo, het UWV, de
Belastingdienst". No competitor rates yes. Four rate it partial, each with a
vendor-side HR import:

- zermelo: https://support.zermelo.nl/guides/formatiebeheerder/werken-met-afas-koppeling
  "Met de koppeling synchroniseert u werknemers, aanstellingen, uitbreidingen,
  inzet over scholen en verloven".
- untis: https://developer.untis.com/api-reference/webuntis-apis/ "Teacher
  Management  Teacher master-data and user accounts" (read and write), and
  https://developer.untis.com/api-reference/webuntis-apis/teacher-absence-management/
  "you can create, update & delete teacher absences". The matrix adds:
  "contracts and deployment are not in the API list".
- xedule: https://xedule.nl/integraties "Youforce Integreer moeiteloos jouw
  HR-gegevens met Xedule ... HR2day ... Afas".
- timeedit: https://timeedit.com/integrations says its API connects to HR,
  student management and building systems, and names no HR connector.

## What integriq already has

- Four roster sources in `lib/sources.seed.json` (`roster-zermelo` :103,
  `roster-untis-oneroster` :117, `roster-xedule` :131, `roster-timeedit`
  :145), read by `lib/Adapters/Roster/RosterImportClient.php`, whose
  `fetchLessons()` returns lessons only: subject, times, room, teacher and
  group references.
- Event-driven push: `synchronization-engine` REQ-001 runs every
  synchronization whose source is a register and schema when an object in it
  changes, intern to extern.
- The credential broker for the target API key (ADR-064).
- No HR source, and no synchronization whose source is staff data.

What the fleet has on humaniq's `development` branch: `Employee` (0.8.1),
`EmploymentContract` (0.7.0, with `hoursPerWeek`), `OrgAssignment` (0.2.0,
an employee's org unit with dates) and `LeaveRequest` (0.4.0, status
draft, submitted, approved or rejected).

## What this change builds

1. A packaged set, `staff-to-untis`, with three synchronizations whose sources
   are humaniq's `Employee`, `OrgAssignment` and `LeaveRequest`, pushing to
   Untis teachers and teacher absences on every change.
2. A field allow-list on the mapping, so nothing but what a timetable needs
   leaves the HR register: no BSN, salary, bank account or contract wage.
3. Approved leave only: a leave request becomes an Untis teacher absence when
   it is approved, and the absence is removed when the leave is withdrawn or
   rejected after approval.
4. A nightly reconciliation run that compares humaniq with Untis and repairs
   drift, because a missed event must not leave a teacher on the timetable who
   left in June.
5. A recorded outcome per other timetabling system, in the set's README.

## Out of scope

- Contracts and deployment to Untis: the documented Untis API has no place
  for them, as the matrix records.
- Zermelo, Xedule and TimeEdit as targets. Their documented HR paths are
  imports they run themselves from named HR systems (AFAS, Youforce, HR2Day,
  Visma.net). None reads humaniq, and none documents a write API for staff
  that this change could call. The README records each.
- An external HR system as source. A school on AFAS already has Zermelo's own
  AFAS coupling; this change serves a school whose HRM is humaniq.
- Foleta, the arbodienst, UWV and the Belastingdienst from the same tender.
  Those are humaniq's payroll and verzuim connections, not timetabling.

## Sibling half

planninq builds nothing: its matrix note says it has no timetabling object.
humaniq builds nothing new for the push; it must keep `LeaveRequest.status`
governed by its lifecycle, which the set reads.

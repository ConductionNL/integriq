# staff-to-untis: what each timetabling system can take from humaniq

Row `planninq:tt-hr-sync`, "Keep staff, contracts, deployment and leave in
step between the HR system and the timetabling system". Change
`openspec/changes/connectors-hr-staff-sync`, design D2 and D5.

The `staff-to-untis` set pushes humaniq's employees, their school
assignments and their approved leave into one timetabling system: Untis. This
file records, for each other timetabling system integriq reads lessons from
(`lib/sources.seed.json`: `roster-zermelo`, `roster-untis-oneroster`,
`roster-xedule`, `roster-timeedit`), what its own documentation says it
imports, from where, and what a school whose HR system is humaniq still lacks.

Status: the set ships as `staff-to-untis.json` beside this file, with its
endpoints, identifiers and auth pinned against developer.untis.com's OpenAPI
documents (Teacher Management v2, Teacher Absence Management v1).

School assignments (`OrgAssignment`): the teacher push runs again whenever
one of the employee's assignments is created or changed
(`sourceConfig.triggerFromRelatedObjects`, related by `employeeId`). The
teacher mapping reads the end dates of all the employee's assignments with
`lookupAll()` and nothing else from them. The teacher is active while the
employment has not ended and at least one assignment is open (no end date, or
an end date today or later). The exit date is the employment end date, or,
when every assignment has an end date, the last of them: an employee who
still teaches under another assignment stays on the timetable. An employee
with no assignment row follows the employment end date alone. More than 200
assignments for one employee refuse the push instead of deciding from a
partial list. Add `humaniq/OrgAssignment` to the mapping lookup allowlist
before enabling the source; until then every teacher push fails, naming the
schema. A deleted assignment does not trigger a push (the trigger listens to
creates and updates only); it takes effect on the employee's next save.

Nightly repair (design D4): `staff-to-untis-teachers` declares
`targetConfig.reconcile`, so `OutboundReconciliationJob` reads the Untis
teacher list once a day and pushes every employee Untis lacks. An Untis
teacher whose `externKey` or `shortName` matches an employee without a
contract is adopted instead of duplicated; any other Untis teacher is listed
in the run log and left alone. A read that stops halfway writes nothing, and
the run never makes a teacher inactive. The list read reuses the collection
path of Teacher Management v2; its answer shape (`teachers`, `id`) is
confirmed in the live pass. Absences stay event-driven: an absence has no key
to adopt it by, so a repair run could only duplicate one entered by hand.

No other system gets a push. Each documents an HR import it runs itself from
named HR systems, and none documents a write API for staff that integriq
could call. A facade that imitates one of those HR systems was rejected
(design D5): it would break on the vendor's next release without anyone being
told.

## Untis (the set's target)

- Documented: "Teacher Management  Teacher master-data and user accounts",
  read and write, https://developer.untis.com/api-reference/webuntis-apis/,
  and teacher absences, "you can create, update & delete teacher absences",
  https://developer.untis.com/api-reference/webuntis-apis/teacher-absence-management/
- What the set sends to Untis: an employee with an active assignment as a teacher
  (name, short code, e-mail, the Nextcloud user id as external key); an ended
  assignment as an inactive teacher, never a deletion; an approved leave
  request as a teacher absence with its dates and a neutral reason code.
- What does not: contracts and deployment over schools. The documented Untis
  API has no place for them.
- Never sent: BSN, salary, bank account, contract wage, identity document
  fields, leave type or leave note. The set's mappings are allow-lists
  (`passThrough: false`) and the set is marked `personalData: true`;
  `PersonalDataSetGuard` refuses an import of such a set that carries a
  pass-through mapping.

## Zermelo

- Documented import: Zermelo's own AFAS coupling, "Met de koppeling
  synchroniseert u werknemers, aanstellingen, uitbreidingen, inzet over
  scholen en verloven",
  https://support.zermelo.nl/guides/formatiebeheerder/werken-met-afas-koppeling
- Source it reads: AFAS only.
- What a humaniq school lacks: Zermelo does not read humaniq and documents no
  staff write API. It needs Zermelo to add a humaniq import or publish a staff
  API. Until then staff and leave are kept in Zermelo by hand, or by AFAS for a
  school that also runs AFAS.

## Xedule

- Documented import: "Youforce Integreer moeiteloos jouw HR-gegevens met
  Xedule", with HR2day and AFAS named beside it, https://xedule.nl/integraties
- Sources it reads: Youforce, HR2day, AFAS and Visma.net.
- What a humaniq school lacks: Xedule does not read humaniq and documents no
  staff write API. It needs Xedule to add a humaniq import or publish a staff
  API.

## TimeEdit

- Documented import: its API "connects to HR, student management and building
  systems", https://timeedit.com/integrations, without a named HR connector
  and without a published staff write endpoint.
- What a humaniq school lacks: a documented endpoint to write staff and
  absences to. With one, a set like this one can be written for it.

## Untis over OneRoster (`roster-untis-oneroster`)

That source reads lessons over OneRoster, which carries no leave. Staff and
absences go to Untis over the WebUntis APIs above, not over OneRoster.

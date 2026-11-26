# Design: connectors-hr-staff-sync

Kind: code. Size M. Read at integriq `development` 92f282bc, humaniq and
planninq `development`.

## Where it fits

| Piece | File | Today |
|---|---|---|
| Roster sources | `lib/sources.seed.json:103-145`, `lib/Adapters/Roster/RosterImportClient.php:59-74` | lessons in, nothing out |
| Push engine | `synchronization-engine` REQ-001, `synchronizeInternToExtern()` | runs on object events |
| Drift guard | `synchronization-engine` REQ-009, REQ-010 | fetch completeness and deletion ratio |
| Broker | `lib/Service/BrokeredCallService.php` | target credentials |
| Source data | humaniq `lib/Settings/register.d/hr-objects.json` (`Employee`, `EmploymentContract`), `hr-org.json` (`OrgAssignment`), `hr-leave.json` (`LeaveRequest`) | owned by humaniq |

## D1. humaniq is the HR side

The tender is for an HRM system, and the fleet's HRM is humaniq. Its objects
are OpenRegister objects, so they are a register and schema source for the
engine (REQ-001): a change to an `Employee` or an approved `LeaveRequest`
runs the synchronizations that name that schema, with no polling.

Rejected: a staff mirror in integriq's own register. It would copy HR data
into a second place with its own retention, and ADR-022 keeps the domain in
the app that owns it.

## D2. Untis first, because its write API is documented

The four timetabling systems the matrix read each import HR data. Only Untis
documents an API a third party writes teachers and teacher absences through,
at the two URLs the matrix cites. The set targets it:

- `Employee` with an active `OrgAssignment` becomes an Untis teacher: name,
  short code, e-mail and the Nextcloud user id as external key.
- An `OrgAssignment` that ends makes the teacher inactive in Untis on the
  end date, never deleted, because past lessons still name the teacher.
- `LeaveRequest` with status `approved` becomes an Untis teacher absence for
  its dates. A later change to `rejected` or a deletion removes the absence.

The exact Untis endpoints, identifiers and auth are pinned in the task that
builds the set, against developer.untis.com, and cited in the set's
description.

## D3. A field allow-list, not a mapping that forgets

`Employee` carries a BSN, salary, IBAN and identity document dates. A mapping
that copies a record and unsets a few fields leaks the next field humaniq
adds. The set's mappings are allow-lists: they name every target field and
read only those source fields, and the set's install check fails when a
mapping uses pass-through.

## D4. Nightly reconciliation

Event-driven push misses what happened while integriq was off or the Untis
API was down. A nightly job runs each synchronization in full: it reads
Untis's teachers and absences, compares them with humaniq, and writes the
difference. It honours REQ-009 and REQ-010, so an incomplete read of Untis
never makes teachers inactive.

## D5. What the other timetabling systems get

`lib/Settings/configurations/staff-to-untis.README.md` records, per system,
what its own documentation says it imports and from where: Zermelo from
AFAS, Xedule from Youforce, HR2Day, AFAS and Visma.net, TimeEdit through its
API without a named HR connector. It states that none reads humaniq, so a
Pontes-like school on Zermelo still needs Zermelo to add a humaniq import or
a documented staff API. That record is the outcome; a guessed facade that
imitates AFAS for Zermelo was rejected, because it would break on the next
Zermelo release without anyone being told.

## Declarative versus imperative

The set is configuration: sources, synchronizations with
`triggerOnlyOnEvents`, allow-list mappings. The nightly job is a background
job over the existing engine. No schema gains behaviour.

## Seed data

integriq's register gains no schema. The set ships an Untis target source in
mock mode with a canned teacher list, so a demo install shows a push and a
reconciliation without a school's Untis licence.

## Risks

- Untis licensing: the teacher and absence APIs may need a WebUntis licence
  level a school lacks. The source test action shows Untis's refusal.
- Identity: matching a humaniq employee to an existing Untis teacher on the
  first run needs a key both sides have. The set matches on e-mail, then short
  code, and lists unmatched teachers in the run log instead of creating
  duplicates.
- Leave is personal data. Only dates and a neutral reason code leave humaniq,
  never the leave type or a note.

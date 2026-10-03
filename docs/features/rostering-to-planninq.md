# Timetables from Zermelo, Untis, Xedule and TimeEdit into planninq

Integriq reads a school timetable from a rostering system and delivers it into planninq. Planninq keeps the timetable; learniq shows it to teachers, pupils and parents.

The four rostering sources ship switched off. Until a school has its own connection, a delivery sends a small example timetable, and the result says so (`flavour: mock`).

## What happens during a delivery

1. Integriq fetches the lessons from the rostering system.
2. The source's preset turns each lesson into planninq's timetable session: source id, subject, start and end, group, teacher and room.
3. The target configuration adds the learniq cohort and the teacher's Nextcloud account where the school's code is known.
4. Planninq adds new lessons, updates moved ones and leaves unchanged ones alone.

A lesson with a group code integriq cannot link to a cohort still lands. Planninq keeps the school's group code, and learniq can find the lesson by it.

## The four presets

| Source | Lesson id | Subject | Times | Group | Teacher | Room | Cancelled when |
|---|---|---|---|---|---|---|---|
| Zermelo (`roster-zermelo`) | `appointmentInstance` | `subjects` | `start`, `end` (Unix seconds) | `groups` | `teachers` | `locations` | `cancelled` is true |
| Untis (`roster-untis-oneroster`) | `id` | `faechId` | `startDateTime`, `endDateTime` | `klasseId` | `lehrerId` | `raumId` | `code` is `cancelled` |
| Xedule (`roster-xedule`) | `eventId` | `activityName` | `startMoment`, `endMoment` | `groupCode` | `teacherCode` | `locationName` | `status` is `cancelled` |
| TimeEdit (`roster-timeedit`) | `activityId` | `activityTitle` | `beginTime`, `endTime` | `resourceGroup` | `staffId` | `roomName` | `cancelled` is true |

The presets live in `lib/roster-mapping-presets.seed.json`. The field names come from each vendor's published API and were not captured from a live school. If a real delivery uses other names, correct the preset; no code changes.

## Linking school codes to cohorts and teachers

Store two maps per source in integriq's app config. Each is a JSON object.

```bash
occ config:app:set integriq roster.roster-zermelo.group_map --value='{"3a": "<cohort id>", "3b": "<cohort id>"}'
occ config:app:set integriq roster.roster-zermelo.teacher_map --value='{"JAN": "jan.devries"}'
```

Learniq can also send maps with a delivery. Its entries win over the stored ones. An unreadable stored value counts as an empty map and is written to the log.

## For integrators

Ask integriq for a delivery with a typed event (ADR-041). Look the class up by name and treat a missing class as "integriq is not installed".

```php
$class = 'OCA\\Integriq\\Event\\RosterImportRequestedEvent';
$event = new $class(sourceApp: 'learniq', systemId: 'roster-zermelo', options: ['groupMap' => [...]], correlationId: $jobId);
$dispatcher->dispatchTyped($event);
$result = $event->getResult(); // status: delivered | failed
```

A failed result names why in `errorCode`:

| `errorCode` | Meaning |
|---|---|
| `unknown-source` | The source id is not one of the four rostering sources. |
| `fetch-failed` | The rostering system could not be read. |
| `planninq-absent` | Planninq is not installed, or did not answer. |
| `planninq-refused` | Planninq refused the whole delivery. |

The full contract is in `openspec/changes/rostering-adapter-targets-planninq/contract.md`.

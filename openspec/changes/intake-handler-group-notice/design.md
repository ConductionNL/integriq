# Design: intake-handler-group-notice

## Decisions

- **Count, not list.** `IGroup::count()` answers the question without loading every member. A group backend that cannot count returns `false`, which reads as empty. That errs towards showing the warning.
- **A missing group is empty.** The repair step creates the groups, but a failed group backend can leave one missing. The warning then still shows.
- **No dismiss.** The warning goes away when the group gets a member. A dismissed warning would hide the same problem on the next visit.
- **Standard component.** `NcNoteCard type="warning"` carries the Nextcloud and NL Design colour variables. No new CSS.

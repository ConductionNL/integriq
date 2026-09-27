# Design: mapping-woo-index-field-mapping

Kind: code. A typed event runs any integriq mapping by slug for a sibling app
that is allowed to, and a seeded mapping holds the Woo-index fields so an
administrator edits them on `/mappings`.

## Where it fits

- Event: `lib/Event/MappingExecutionRequestedEvent.php`, shaped like
  `lib/Event/DocumentRenderRequestedEvent.php`: constructor with
  `mappingSlug`, `input`, `sourceApp`, `correlationId`; result slot
  `setOutput(array)`, `getOutput(): ?array`, `isHandled(): bool`, and
  `refuse(reason, code)` with `getRefusal(): ?array`.
- Listener: `lib/EventListener/MappingExecutionRequestedListener.php`,
  registered in `lib/AppInfo/Application.php` beside the document render
  listener at `:297`. It resolves the mapping through
  `lib/Service/MappingService.php:190` (`normaliseMapping()`), checks
  `callableBy`, and calls `executeMapping()` at `:277`.
- Schema: a fragment `lib/Settings/register.d/mapping-woo-index-field-mapping.json`
  (ADR-037) merges `callableBy` (array of app ids, default empty) onto the
  `mapping` schema at `lib/Settings/integriq_register.json:1414`, and seeds the
  mapping `woo-index-publication` through `x-openregister-seed`.
- Page: none new. The seeded mapping appears on the existing `Mappings` and
  `MappingDetail` pages (`src/manifest.json`, page ids `Mappings` and
  `MappingDetail`); the detail page shows `callableBy` next to the rules and
  saves it with them.

## D1. A typed event, not a route or a shared service

ADR-041 decision 1 makes a cross-app command a typed `IEventDispatcher` event
with a result slot, which is how filinq asks integriq to render a document.
The alternatives were an HTTP route opencatalogi calls on its own server, or
opencatalogi resolving integriq's `MappingService` from the container. ADR-041
forbids both: a server-side HTTP call to oneself adds auth and failure modes
for nothing, and a cross-container service resolution ties opencatalogi to
integriq's class layout.

## D2. A mapping says which apps may run it

A mapping can contain `executeMapping` and `getFileContents` calls
(`lib/Twig/MappingExtension.php:79`), so running an arbitrary mapping on a
sibling's input is not free of consequence. `callableBy` on the mapping lists
the app ids allowed to dispatch it. An empty list means no app may, which is
the default for every mapping that exists today. The alternative was to allow
every installed app. Rejected: a mapping written for one synchronization would
become a public function of the instance the day it was saved.

## D3. The seeded mapping is a starting point an administrator owns

`woo-index-publication` maps:

- `publisher` from the publication's organisation TOOI identifier,
- `officieleTitel` from the publication title, with a fallback to its name,
- `informatiecategorie` from the category's TOOI URI,
- `soortHandeling` from the publication's action field, with no default: an
  empty value stays empty so the gap is visible in the sitemap check.

The field names follow what the sibling evidence says opencatalogi reads
today, so the switch produces the same output plus the official title. The seed
uses `x-openregister-seed`, which creates the object once and never overwrites
an administrator's edit. The alternative was a mapping kept in code and copied
to the database on every upgrade. Rejected: it would undo the administrator's
change, which is the whole point of the row.

## D4. Resolving by slug is tested, not assumed

`normaliseMapping()` calls `find()` first and rethrows its
`DoesNotExistException` at `lib/Service/MappingService.php:211`, before the
slug fallback at `:220` can run. Whether OpenRegister's `find()` throws or
returns null for a slug decides whether a slug ever resolves. The listener
test resolves `woo-index-publication` by slug against a real OpenRegister;
if the fallback is unreachable, the task reorders the lookup so the slug
search runs when `find()` throws.

## Declarative versus imperative

The mapping rules and `callableBy` are declared on the mapping object. Running
a mapping is imperative and synchronous inside the dispatch, which is what a
sitemap render needs. No `x-openregister-*` annotation runs a Twig mapping for
another app.

## Seed data

One mapping, `woo-index-publication`, with the four rules above and
`callableBy: ["opencatalogi"]`, seeded next to the three existing example
mappings (`lib/Settings/integriq_seed_data.json:268`). The existing mappings
get no `callableBy`, so no app can dispatch them.

## Risks

- `callableBy` is only as protected as the mapping itself. The `mapping`
  schema carries no `authorization` block today and falls back to
  OpenRegister's default, so who may edit a mapping, and so its `callableBy`,
  is set by `platform-action-rights-coverage` (`object.mapping.update`).

- A sitemap with many publications dispatches one event per publication. The
  listener caches the resolved mapping for the request, so the cost is the
  mapping run, not the lookup.
- An administrator can break the Woo-index output by editing the mapping. The
  mapping test panel and, once adopted, `automation-integration-regression-tests`
  cases on this mapping are the guard.

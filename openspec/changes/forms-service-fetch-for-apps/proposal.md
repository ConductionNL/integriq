---
kind: code
depends_on: [sources-route-distance-and-rdw-lookup]
---

# Proposal: forms-service-fetch-for-apps

## Summary

Give another app one server-side call that takes named inputs and answers named outputs. An administrator defines a service fetch in integriq once: which source, which request it sends, which inputs it takes and how the answer maps onto outputs. An app asks for that fetch by slug with the inputs it has, and gets the mapped outputs or a named refusal. The app never sees the source, its credential or the raw answer.

Rows covered: portaliq `int-service-fetch` (decision 104). This is integriq's half of portaliq's `data-lookups-and-checks-in-forms` (merged in portaliq #1387).

## Why

Portaliq's `data-lookups-and-checks-in-forms` lets a form step declare `fetch: [{ id, source, inputs, outputs, show }]`. At the step change portaliq's server calls "the integriq source with the named answers and writes the outputs into read-only fields or a check line. The browser never calls the outside service." Its proposal says: "integriq owns connections to outside services; portaliq calls a named integriq source from the server." The FormulierVelden board draws the result: "Wij controleren of u eigenaar bent", "Volgens het Kadaster staat dit pand op uw naam."

Open Formulieren 4.0.1 has the same thing as a service fetch configuration on a logic rule (`src/openforms/api/v2_urls.py:63`, `src/openforms/variables/models.py` ServiceFetchConfiguration): a service, a path and query with variables, and a mapping expression that picks the value out of the answer.

What integriq has today:

- `ConnectionCallRequestedEvent` in the open change `sources-route-distance-and-rdw-lookup` (REQ-CC-001): an app names one of its own declared connections and a raw request, and gets status and body back. That fits humaniq, which knows its connections when it ships. A form designer does not: the Kadaster check is added to a form by a functional administrator, long after portaliq shipped, so portaliq cannot declare it in `connections.json`. And the raw body would make portaliq parse a Kadaster answer, which is the mapping integriq already owns.
- Mappings (`lib/Service/MappingService.php`, spec `mapping-editor-ui`), sources with brokered credentials (`http-call-engine` REQ-SBC-002), the call log (REQ-001).

## What changes

- **A `serviceFetch` object.** Slug, title, the source, method, a path and query template with `{input}` placeholders, an optional body mapping, the declared inputs (name, type, required), an output mapping (an integriq Mapping applied to the answer), the declared outputs, the apps that may call it, a timeout and a cache time.
- **A typed command `ServiceFetchRequestedEvent`.** The calling app, the fetch slug and the input values in; the outputs, or a refusal (`unknown-fetch`, `not-allowed`, `invalid-input`, `source-unavailable`, `mapping-failed`, `timeout`) out. It never throws to the caller, and it never returns the raw answer.
- **A list for designers.** `GET /api/service-fetches` lists the fetches an app may call with their inputs and outputs, so a form designer can pick one and wire its inputs to answers.
- **An admin page.** *Beheer > Service fetches*: create, edit, test with sample inputs (shows the raw answer to the administrator only, and the mapped outputs).
- **One call log line per fetch,** with the calling app and the fetch slug, inputs redacted as the call log already redacts.

## Out of scope

- Which answer feeds which input, and where an output shows: portaliq and the form designer.
- Rendering the Kadaster check: portaliq.
- A Kadaster source template. Any source integriq can call works; a BRK template is a follow-up when a municipality has a contract.

## Impact

- Specs: new capability `service-fetch`.
- New: `lib/Settings/register.d/service-fetch.json` (schema `serviceFetch`), `lib/Event/ServiceFetchRequestedEvent.php`, `lib/Listener/ServiceFetchListener.php`, `lib/Service/ServiceFetchService.php`, `lib/Controller/ServiceFetchController.php`, `src/views/admin/ServiceFetchesPage.vue`, `src/modals/ServiceFetchModal.vue`.
- Changed: `appinfo/routes.php`, `lib/AppInfo/Application.php`, `l10n/`.

## Cross-project dependencies

- portaliq `data-lookups-and-checks-in-forms` dispatches the command from `POST /api/intake/{route}/steps/{step}/fetch` and maps a refusal to situation 4 of FormulierNietBeschikbaar.
- buildiq authors fetch steps in the form designer and reads the list endpoint.

# Discovery: slo-kerndoelen-import

## Question
Can integriq read SLO's curriculum as open data today, under what licence and access terms, and in what response shape, so that one SLO set becomes one learniq goal tree?

## Approach Taken
All probes on 2026-09-27 (times UTC).

- Probed the REST API with `curl`: `GET https://opendata.slo.nl/curriculum/api/v1/` with `Accept: application/json`, then `kerndoel/`, `niveau/`, `vakleergebied/`, `uuid/{id}`, `openapi.json`, `roots/` (10:51 to 10:53).
- Read SLO's own API page (the `/curriculum/api/` entry of `https://opendata.slo.nl/data/data.json`, which feeds the portal) and the disclaimer page.
- Read the OpenAPI document SLO links: `https://api.swaggerhub.com/apis/AUKE_1/slo-curriculum-open-data-api/2024.1` (OpenAPI 3.0.3, version 2024.1).
- Read the licence record: `https://data.overheid.nl/data/api/3/action/package_show?id=slo-curriculumdatabase`.
- Read the REST server source that answers those calls: `slonl/curriculum-rest-api@master` (`src/api-server.js`, `src/opendata-api/*.js`), and its dev harness `slonl/curriculum-restapi-dev@929314f7`, which pins the same server as a submodule.
- Read the dataset repos at their latest release tags: `curriculum-fo@2026.8`, `curriculum-basis@2026.7`, `curriculum-kerndoelen@2026.7`, `curriculum-examenprogramma@2026.7`, `curriculum-leerdoelenkaarten@2026.7`.
- Read learniq's `CompetencyFramework` and `Competency` on `development` (register 0.24.9) and the field contract from lane r2-curriculum.

## Findings
1. **Access needs a free key.** Every JSON request answers `401` with `WWW-Authenticate: Basic`. SLO's API page: "Als u programmatische toegang wenst, moet u zich eerst registreren", then send the registered e-mail and API key as Basic auth. The OpenAPI says the same ("HTTP Basic authentication is required for all endpoints"). The public data browser works only because its JavaScript carries a shared browser token; that token is not ours to use for programmatic access and this change does not use it.
2. **The licence is CC BY 4.0.** data.overheid.nl lists the "SLO Curriculumdatabase API" with `license_id` `http://creativecommons.org/licenses/by/4.0/deed.nl`, publisher Stichting Leerplan Ontwikkeling, access rights PUBLIC. SLO's own disclaimer allows copying "mits de bron wordt vermeld". So: attribution is the one obligation.
3. **Ids are immutable.** SLO's API page: every id keeps the same data forever; a change creates a new id with `replaces`, and the old one moves to `deprecated` with `replacedBy`. A released set therefore never changes under the same root id.
4. **Collections return `{data, page, count, root, @isPartOf}`**, entities carry `@id`, `@type`, `uuid`, `prefix`, `title`. `/uuid/{id}` returns one entity with its children projected one level. `/tree/{id}` returns the full graph under an id in JSONTag (`application/jsontag`): JSON with `<object class="X" id="/uuid/...">` annotations before values and `<link>` for a repeated object.
5. **The per-entity query hides one level of the renewed kerndoelen.** In `curriculum-fo@2026.8` all sixteen kerndoelensets run set, domein, kernzin, doelzin. The server's `FoSet` and `FoDomein` typed queries select `FoDoelzin` and `FoSubdomein` but never `FoKernzin`, so `/uuid/{setId}` stops at the domein for every renewed set. `/tree/{setId}` is the raw graph and does carry `FoKernzin`.
6. **Three per-niveau routes point at queries that do not exist** in the server source (`KerndoelOpNiveau`, `KerndoelVakleergebiedOpNiveau`, `KerndoelVakleergebiedByIdOpNiveau`). They cannot be relied on.
7. **Year information exists only on some sets.** The 2006 kerndoelen are tagged `po`, `ob vo` and phases, never a groep. Renewed kerndoelen carry no niveau. Examenprogramma's name a school type, not a year. Leerdoelenkaart doelniveaus do name groep bands (`groep 3-4`, `groep 7-8`) and single groepen (`groep 8`); 556 of the 23,174 doelniveaus in `curriculum-basis@2026.7` do.
8. **Examenprogramma's carry `versie`** (for example "2020"), a real jaarversie for `CompetencyFramework.edition`.

## Recommendation
Build it now, dormant. Read each root through `/tree/{id}` and parse JSONTag in integriq, because that is the only documented call that returns the kernzin level. Discover roots through the collection routes (`fo_kerndoelen/`, `fo_examenprogrammas/`, `kerndoel_vakleergebied/`, `examenprogramma`, `ldk_vakleergebied/`). Fill `applicableYears` only from SLO's own groep and leerjaar niveaus, never from a guess. Ship the source disabled until an operator registers a key.

## Risks Uncovered
- No keyed response could be captured, so the fixture is real release data in the documented shape (see design.md "Fixture provenance").
- SLO may add `FoKernzin` to its typed queries later; the walker handles both a full tree and a shallow node, so either shape works.
- The public browser token is visible in SLO's JavaScript and in their public test harness. Using it would be easy and wrong; the design names it so nobody reaches for it.

## Next Steps
Proceed to specs and design. After merge: register a Conduction key at SLO, re-record the fixture from a keyed response, and build the learniq write step once `competency-year-scope` lands.

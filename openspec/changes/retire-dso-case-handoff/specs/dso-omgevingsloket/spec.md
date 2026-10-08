## MODIFIED Requirements

### Requirement: Automatic Zaak Creation (REQ-DSO-020)

Each received DSO-verzoek that integriq has mapped MUST become exactly one zaak, created automatically by the case system from the mapped `dso_verzoek`. Integriq prepares the verzoek (parsed data, `mappedCaseTypes`, attachments) and MUST NOT create the zaak itself. The zaak includes all parsed data: aanvrager mapped to the zaak (BSN/KVK-nummer, naam, adres, contactgegevens), locatie (BAG-adres, kadastrale aanduiding, GML-geometrie), startdatum set to DSO-verzoek indieningsdatum, linked bijlagen, and the original DSO-verzoek reference (verzoekId, bronorganisatie).

@e2e exclude backend DSO/Omgevingsloket STAM integration, covered by PHPUnit, not browser UI

#### Scenario: Parsed vergunningaanvraag creates fully populated zaak
- **WHEN** a valid vergunningaanvraag is received, parsed and mapped, and the case system reads the mapped verzoek
- **THEN** the zaak has: zaaktype from the activiteiten-mapping, aanvrager from the verzoek, locatie with BAG-adres and geometrie, startdatum equal to indieningsdatum, all bijlagen linked, and verzoekId stored as external reference

#### Scenario: KVK-registered bedrijf mapped to bedrijf fields
- **WHEN** the verzoek aanvrager is a KVK-registered bedrijf and the zaak is created
- **THEN** the bedrijfsnaam, KVK-nummer, and vestigingsnummer are mapped to the zaak initiatiefnemer fields instead of BSN-based person fields

#### Scenario: GML geometry stored as geospatial eigenschap
- **WHEN** the verzoek locatie contains GML-geometrie (polygon) and the zaak is created
- **THEN** the GML is parsed to GeoJSON, validated against the BAG register, and stored as a geospatial zaak-eigenschap enabling map-based visualization

#### Scenario: Bouwkosten stored for legesberekening
- **WHEN** the verzoek contains optional bouwkosten and the zaak is created
- **THEN** bouwkosten are stored as a zaak-eigenschap for use in legesberekening workflows

#### Scenario: Zaak creation dispatches Integriq event
- **WHEN** a zaak is successfully created and creation completes
- **THEN** an Integriq event is dispatched (EventService) enabling n8n workflows to trigger intake processing such as legesberekening, team-toewijzing, and automatische termijnbewaking

## ADDED Requirements

### Requirement: No case handoff from a DSO verzoek (REQ-DSO-021)

The `dso_verzoek` schema MUST declare no `x-openregister-handoff`, and integriq MUST expose no endpoint that hands a verzoek off to a case. A verzoek MUST NOT become a second zaak through integriq once the case system has made one.

@e2e exclude backend schema declaration and route table; covered by DsoVerzoekDeclaresNoCaseHandoffTest and the live run, no browser surface

#### Scenario: The retired handoff endpoint is called
- **WHEN** a signed-in user posts to `/apps/integriq/api/dso/verzoeken/{id}/handoff`
- **THEN** no route answers it and no zaak is created

#### Scenario: OpenRegister lists the handoffs of a verzoek
- **WHEN** a client asks OpenRegister for the handoffs of a `dso_verzoek`
- **THEN** none is declared, so none can be executed

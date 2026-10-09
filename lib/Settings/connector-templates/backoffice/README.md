# Municipal back-office systems

Gemeente Stein's tender (requirements 166859 and 186343,
https://www.tenderned.nl/aankondigingen/overzicht/226100) names fourteen
systems. Each ends here with a template or a reason (design D2). A template
exists only where a published interface description could be checked and
integriq can reach it; a card that guesses an endpoint would read as a
promise nobody checked.

Checked on 29 September 2026.

| System | Outcome | Reason, or what a buyer needs from the vendor |
|---|---|---|
| Alfresco | Template `alfresco-cmis.json` | Alfresco publishes its CMIS 1.1 API; the browser binding answers JSON over HTTPS, which a plain integriq API source reads. |
| SmartDocuments | No template | Already in the Store as the SmartDocuments adapter (document generation behind filinq). |
| iBurgerzaken (PinkRoccade) | No template | Person data reaches integriq over Haal Centraal BRP from RvIG, already in the Store as the BRP Haal Centraal source. A direct StUF-BG connection to iBurgerzaken is set per installation under the vendor's licence; ask the vendor for its StUF-BG endpoint description. |
| iObjecten BAG (PinkRoccade) | No template | Address and building data reach integriq from the national BAG through the PDOK adapter. The municipal iObjecten interface is not published; ask the vendor. |
| GWS (Centric) | No template | Wmo and Jeugdwet messages reach integriq over iWMO and iJW through the GGK, which integriq speaks. No published description of a direct GWS interface was found; ask the vendor. |
| Civision Samenleving | No template | Same as GWS: iWMO and iJW through the GGK. No published description of a direct interface was found; ask the vendor. |
| NedGeo, NedGlobe, NedOmgeving (NedGraphics) | No template | Geo services of this kind are served as OGC WMS and WFS per installation; integriq reads those through the PDOK adapter's OGC support. No published product interface description was found to check against; ask the vendor for the service URLs. |
| CIR | No template | The system could not be identified from the tender text alone, and no published interface description was found. Ask the municipality which product and version it means. |
| LBA | No template | Same as CIR: not identifiable from the tender text, no published interface description found. |
| Cipers | No template | No published interface description was found; ask the vendor. |
| Stratech | No template | No published interface description was found; ask the vendor. |
| Simsuite | No template | No published interface description was found; ask the vendor. |

A new template here must name `standard` and `verifiedAgainst`; the
validator refuses it otherwise.

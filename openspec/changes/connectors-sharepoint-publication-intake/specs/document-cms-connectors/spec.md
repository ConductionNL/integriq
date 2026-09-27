# document-cms-connectors Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- connectors-sharepoint-publication-intake

## Purpose

An administrator routes Woo dossiers from a SharePoint library into
OpenCatalogi as draft publications with their documents, from integriq's own
screens, over Microsoft Graph and the credential broker. Rows
`opencatalogi:int-sharepoint` and `integriq:con-sharepoint`.

## ADDED Requirements

### Requirement: An administrator sets up SharePoint to publication intake from the Store (REQ-SPPI-001)

The SharePoint card in the Store MUST offer a setup that picks a brokered
credential, a site found by search, a document library, a folder and an
OpenCatalogi catalogue, each loaded live from Graph or OpenRegister. Saving
MUST create one source, one synchronization and one mapping from the
`sharepoint-publications` set, and MUST NOT store a secret in any of them.

#### Scenario: a Woo coordinator's library is connected
- GIVEN an administrator with a brokered Graph credential and a SharePoint site "Woo verzoeken"
- WHEN they open the SharePoint card, choose "Route to publications", search "Woo", pick the site, the "Dossiers" library, its "Gereed" folder and the Woo catalogue, and save
- THEN a source, a synchronization and a mapping exist, and the source holds a broker reference and no secret
- e2e: `tests/e2e/sharepoint-publications.spec.ts`

#### Scenario: Graph refuses the site search
- GIVEN a credential without permission to list sites
- WHEN the administrator searches for a site
- THEN the dialog names the missing Graph permission and creates nothing
- e2e: `tests/e2e/sharepoint-publications.spec.ts`

### Requirement: Each dossier folder becomes a draft publication with its documents (REQ-SPPI-002)

A run MUST write one OpenCatalogi `publication` per subfolder of the chosen
folder, with `title` from the dossier's title column or the folder name, and
MUST attach every file in the subfolder to that publication object. The
mapping MUST NOT write `publicationDate`. The origin identity MUST be the
SharePoint item id, so a renamed folder updates the same publication.

#### Scenario: a dossier arrives as a draft with two documents
- GIVEN a subfolder "Woo-2026-014" holding a besluit and an inventarislijst
- WHEN the synchronization runs from cron
- THEN one publication titled from the dossier exists in the Woo catalogue with both documents attached and no publication date
- e2e: `tests/e2e/sharepoint-publications.spec.ts`

#### Scenario: nothing goes live without an editor
- GIVEN a SharePoint dossier with a filled "publicatiedatum" column
- WHEN it is imported
- THEN the publication has no `publicationDate`, and it is not visible on the public catalogue
- @e2e exclude visibility on the public surface is OpenCatalogi's; covered by PHPUnit on the mapping output

#### Scenario: a cron run keeps the files
- GIVEN a synchronization run with no signed-in user
- WHEN it fetches the documents
- THEN every document is attached to its publication, and none is written to a person's Files folder
- @e2e exclude a cron-context run; covered by an integration test on the synchronization

### Requirement: The legacy SharePoint Woo template is marked deprecated (REQ-SPPI-003)

The configuration import MUST mark every entry from
`configurations/sharepoint-woo/` as deprecated, name the
`sharepoint-publications` set as its replacement, and warn before importing
it.

#### Scenario: an administrator is steered to the new set
- GIVEN the configuration import screen
- WHEN an administrator selects the SharePoint Woo template
- THEN the import warns that it is deprecated and names the SharePoint to publication set
- e2e: `tests/e2e/sharepoint-publications.spec.ts`

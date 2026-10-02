## MODIFIED Requirements

### Requirement: Bijlagen Download and Storage (REQ-DSO-005)

The adapter MUST download every bijlage (documenten, tekeningen, rapporten, berekeningen) a DSO-verzoek references from DSO-LV, and MUST attach each one as a file to the request's `dso_verzoek` object through OpenRegister, so that it is stored in Nextcloud Files under the object's access rights. The download MUST run in a background job after intake, so the STAM endpoint still answers without waiting for it. The adapter MUST record each bijlage's outcome on the request, and MUST NOT write a bijlage anywhere outside Nextcloud Files.

@e2e exclude backend DSO/Omgevingsloket STAM integration — covered by PHPUnit, not browser UI

#### Scenario: Multiple bijlagen downloaded and linked
- **WHEN** a verzoek references 5 bijlagen including PDFs, DWG drawings, and a structural calculation and the background job runs
- **THEN** each bijlage is downloaded via the DSO-LV document API with the source's authentication (mTLS in production)
- **AND** each is attached to the request object as a file tagged `dso-bijlage`
- **AND** each entry in the request's `attachments` has status `stored` and the stored file's id

#### Scenario: The endpoint does not wait for the bijlagen
- **WHEN** DSO-LV pushes a verzoek with bijlagen to the STAM endpoint
- **THEN** the endpoint answers 202 once the request is saved
- **AND** every entry in the request's `attachments` has status `pending` until the job has run

#### Scenario: Bijlage download retried and flagged on failure
- **WHEN** a bijlage download fails due to a network timeout and the job retries (up to 3 attempts with exponential backoff)
- **THEN** on persistent failure its entry has status `failed` with the last error, and the request is flagged "bijlage ontbreekt" for the behandelaar

#### Scenario: Oversized bijlage rejected
- **WHEN** a bijlage exceeds the configured maximum file size (default: 100MB) and the download is attempted
- **THEN** no file is stored, its entry has status `too-large`, and the request is flagged for manual bijlage handling

#### Scenario: Nothing is written outside Nextcloud Files
- **WHEN** any verzoek with bijlagen is processed
- **THEN** no file is created on the server's filesystem outside Nextcloud's data, and no path such as `/DSO-verzoeken` exists afterwards

#### Scenario: A rerun finishes what a crash left
- **GIVEN** the job stopped after storing 2 of 5 bijlagen
- **WHEN** the job runs again
- **THEN** it downloads only the 3 entries that are not `stored`, and stores no duplicate of the first 2

### Requirement: Melding Reception (REQ-DSO-002)

The adapter MUST support receiving meldingen (notifications of activities not requiring a permit) from DSO-LV via the same STAM endpoint. Meldingen follow a simplified flow: they create a zaak in Procest with a "Melding" zaaktype but do not require a vergunningbesluit response.

@e2e exclude backend DSO/Omgevingsloket STAM integration — covered by PHPUnit, not browser UI

#### Scenario: Sloopactiviteit melding creates zaak
- **WHEN** an initiatiefnemer submits a melding via het Omgevingsloket for a sloopactiviteit and DSO-LV pushes the melding to the STAM endpoint
- **THEN** the adapter parses the melding, creates a zaak with zaaktype "Melding Sloop", and pushes status "ontvangen" back to DSO-LV

#### Scenario: Combined melding and vergunning components
- **WHEN** a melding is received for an activiteit that has both a melding and a vergunning component and the adapter processes the melding
- **THEN** it creates a melding-zaak for the meldingsplichtige activiteit and flags the vergunningplichtige activiteit for separate aanvraag handling

#### Scenario: Melding bijlagen stored in Files
- **WHEN** a melding contains bijlagen (asbestinventarisatierapport) and the background job runs
- **THEN** the bijlagen are downloaded from DSO-LV and attached as files to the melding's `dso_verzoek` object, as REQ-DSO-005 describes

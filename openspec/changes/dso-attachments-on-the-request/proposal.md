# Proposal: dso-attachments-on-the-request

kind: code. Cites **ADR-022** (apps consume OpenRegister abstractions).

## Why

REQ-DSO-005 says integriq downloads every attachment a DSO request references, and stores it in Nextcloud Files. Today nothing does:

- On the live path (`DSOController::receiveRequest()`, then `DsoIngestService`), the parser keeps the attachment references inside `rawRequest`, and the handoff passes them on. No file is downloaded or stored.
- The only download code is `DSOAdapterService::downloadAttachments()`. Nothing calls that service. It would write to `/DSO-verzoeken/{year}/{id}/bijlagen` at the root of the server's filesystem, outside Nextcloud Files and outside any access control.

So a case worker never sees the drawings and reports that came with a permit application. And the dormant code is a write to the filesystem root, waiting for a caller.

## What changes

- **Attachments become files on the request object.** A queued job downloads each referenced attachment after intake and attaches it to the `dso_verzoek` object with OpenRegister's `FileService::addFile()`. The files live in Nextcloud Files and carry the object's access rights and audit trail. This is the pattern the Open Formulieren intake already uses (`OpenFormulierenIntakeService`).
- **The request records each attachment's outcome.** The `dso_verzoek` schema gains an `attachments` property: per reference, its name, URL, status (`pending`, `stored`, `failed`, `too-large`), the stored file id and the error.
- **Downloads use the source's own transport.** The job fetches through `DsoClient`, so production traffic gets the source's mTLS certificate (`mtls-client-certificate-transport`). It retries three times with backoff, as REQ-DSO-005 already requires.
- **The endpoint stays fast.** The STAM endpoint still answers 202 straight after intake. The download happens on the cron worker.
- **`DSOAdapterService` is retired.** Anything the live path still lacks moves to where the live path lives; the rest, with the filesystem-root download, is deleted.

## Capabilities

### Modified Capabilities

- `dso-omgevingsloket`: REQ-DSO-005 names where attachments are stored and how their outcome is recorded; the melding scenario in REQ-DSO-002 follows.

## Impact

- New: `lib/BackgroundJob/FetchDsoAttachmentsJob.php`, `lib/Service/Dso/DsoAttachmentFetcher.php`.
- Changed: `DsoIngestService` (enqueues the job), `lib/Settings/integriq_register.json` (`dso_verzoek.attachments`), `DSOParserService` (exposes the references in a fixed shape).
- Removed: `lib/Service/DSOAdapterService.php` and its test, once every caller-less method is confirmed dead.
- Depends on OpenRegister's `FileService`, which is not a published contract (see dossiq's `OpenRegisterBridge::fileService()`). It is resolved lazily, and the job fails soft when OpenRegister is absent.

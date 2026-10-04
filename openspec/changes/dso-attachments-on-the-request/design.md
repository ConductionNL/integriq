## Context

The live DSO intake runs `DSOController::receiveRequest()`. That verifies the signature, then `DSOParserService` parses the payload and `DsoIngestService::ingest()` saves a `dso_verzoek` object with status `received`, then `mapped`. A handoff later passes the request to the configured case system. The parser returns `bijlagen` as references (name and URL), and today they end up only inside `rawRequest`.

`DSOAdapterService` predates this path. It has a download method that writes under `/DSO-verzoeken/{year}/{id}/bijlagen` on the local filesystem. It has no caller in `lib/` or `appinfo/`, only its unit test.

Decided with Ruben on 2026-10-02:
- store attachments as OpenRegister object files;
- download them in a background job after intake;
- retire `DSOAdapterService` in this change.

## Decisions

### Files on the request object, through OpenRegister

`FileService::addFile(objectEntity: $request, fileName: …, content: $stream, tags: ['dso-bijlage'])` attaches each file to the `dso_verzoek` object. OpenRegister stores it in Nextcloud Files, in the object's own folder, and applies the object's access rights. No integriq code chooses a user, a group folder or a path.

The tag lets a case system, or the handoff, find exactly the DSO attachments among other files on the object.

The REQ-DSO-005 folder pattern `/DSO-verzoeken/{year}/{verzoekId}/bijlagen/` is dropped. It named a location; what matters is that the files are in Files, linked to the request, and access-controlled. OpenRegister's object folder gives all three.

### A queued job per request

`DsoIngestService::ingest()` adds one `FetchDsoAttachmentsJob` (a `QueuedJob`, like `FetchFilesJob`) carrying the request's uuid, when the request has attachments. The job:

1. reads the request object and its `attachments` list;
2. downloads each entry still `pending` through `DsoAttachmentFetcher`;
3. writes the outcome back to that entry.

Re-running the job only touches entries that are still `pending` or `failed` and have retries left. So a crash halfway leaves a request that the next run finishes, not a duplicate file.

### Downloads through DsoClient

`DsoAttachmentFetcher` asks the active source's `DsoClient` for a GET, so the source's authentication mode applies: a token for pre-production, mTLS in production. It caps the size at the configured maximum (default 100 MB, REQ-DSO-005), streams the body into `addFile()` rather than holding it in memory, and retries up to three times with exponential backoff.

When an attachment is too large, its entry is marked `too-large` and the file is not stored. When it keeps failing, its entry is marked `failed` and the request is flagged for the case worker; REQ-DSO-005 calls this "bijlage ontbreekt".

### The `attachments` property

`dso_verzoek.attachments` is an array of objects:

| Field | Meaning |
|---|---|
| `name` | The file name from the request |
| `url` | Where DSO-LV serves the file |
| `status` | `pending`, `stored`, `failed` or `too-large` |
| `fileId` | The Nextcloud file id, once stored |
| `attempts` | How many downloads were tried |
| `error` | The last error, once failed |

The parser fills `name` and `url`. Intake sets every `status` to `pending`. The schema in `integriq_register.json` gains the property, so the object store accepts what the job writes.

### Retiring DSOAdapterService

Each public method is checked against the live path:
- the download is replaced by this change;
- case mapping, samenloop and case creation are checked against `DsoIngestService` and the handoff;
- a method with a live equivalent, or no caller and no requirement, is deleted.

Where a requirement still needs one, the method moves next to the live path, with its tests. The class and `DSOAdapterServiceTest` are then removed.

## Risks / Trade-offs

- **`FileService` is not a published OpenRegister contract.** A signature change there breaks this silently. The fetcher resolves it lazily, as dossiq does, and a unit test pins the call shape. Recorded as the same contract gap dossiq names.
- **Files arrive after the request.** A case system that reads the request in the first minute may see `pending` entries. The `verzoek-to-case` handoff maps only the title, summary, channel and priority, plus a provenance link to the request object. The receiver follows that link to the request, its files and their `attachments` statuses, so it can tell "not yet" from "none". Putting the statuses into the handoff mapping itself would change the Case contract, and is left for a change that owns it.
- **Large drawings.** Streaming keeps memory flat, and the size cap bounds disk use per file.

## Migration

Requests received before this change have no `attachments` property. The ingest of a new request is the only writer, so old requests stay as they are. A one-off `occ` command to back-fill them is out of scope unless someone asks.

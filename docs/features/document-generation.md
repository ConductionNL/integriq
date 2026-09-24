# Vendor document generation

Many municipalities have licensed SmartDocuments or Xential and filled it with their own legal texts. A beschikking has to come out of that engine, not out of ours.

Filinq owns document generation for the fleet. This connector is the seam behind it: filinq asks for a render, integriq calls the vendor, and the case app keeps talking to filinq as it always did.

## What ships

| Binding | What it is |
|---------|-----------|
| `log` | A sandbox. It renders a placeholder naming the template and the data hash, makes no network call and needs no credential. |
| `smartdocuments` | SmartDocuments over REST. |
| `xential` | Xential over REST. |

Both vendor bindings ship a dormant source in mock mode, so you can walk the whole path before a licence exists.

## Setting up a source

1. Open the Catalog, filter on **Document generation**, and instantiate SmartDocuments or Xential.
2. Register the API key with the OpenRegister credential broker.
3. Set `configuration.baseUrl`, reference the credential from `configuration.authentication.credentialRef`, and turn `configuration.mockMode` off.
4. Activate the source.

A vendor source without a credential reference is refused at activation, naming what is missing. An API key written into the source itself is not accepted, and there is no fallback that would make one work.

Read the vendor's templates with `GET /apps/integriq/api/document-generation/sources/{sourceId}/templates` and hand a template id to filinq's template administration. Integriq keeps no copy of a vendor template: the vendor administers those, and a copy here would be wrong the first time somebody edits one there.

## What a render records

Every render is one `documentGenerationJob`: the source, the provider, the template, a sha256 of the merge data, who asked, and the lifecycle. The merge data itself is never stored. An audit can match a beschikking to the render that produced it without integriq holding a second copy of the case.

## The four statuses, and why there are four

- **queued**: the vendor took the render and is working on it.
- **rendered**: the vendor produced a document, and integriq fetched it whole.
- **failed**: the vendor answered, and the answer was no. The reason is on the job.
- **unreachable**: nobody got an answer.

That last one is the one worth reading twice. A vendor that times out has refused nothing. The document may exist on the far side, it may be queued there, the request may never have arrived. Reporting that as a failure tells you the vendor said no, which is a sentence nobody said, and it invites a retry that can produce a second beschikking.

So an unreachable render stays open, `unreachableSince` records when the vendor went quiet, and `lastError` stays empty because nothing went wrong that anybody can name. A background sweep asks again every five minutes, and the job settles as soon as the vendor is willing to say what happened.

## A half-generated document is not filed as one

A render is called complete only once its document has been fetched whole.

- A vendor that reports a render as done and names no document ends `failed`, saying so.
- A document that comes back empty ends `failed`, and no reference is kept to it.
- A document the vendor says is ready that cannot be fetched yet is `unreachable`, not failed: it is unfinished business, and the sweep picks it up.

Only a fetched, non-empty document dispatches `DocumentRenderedEvent` with a file reference. Filinq never files a document integriq could not produce whole.

## For developers

filinq asks with a typed command and reads the answer off the same instance:

```php
$event = new DocumentRenderRequestedEvent(
    sourceId: $sourceId,
    templateId: 'sd-beschikking',
    data: $mergeData,
    requestedBy: $userId,
);
$dispatcher->dispatchTyped($event);

$jobId = $event->getJobId();      // null when it was refused
$refusal = $event->getRefusal();  // ['code' => ..., 'reason' => ...]
```

The outcome arrives as `DocumentRenderedEvent` with the job id, the requester, and either a file reference or the error.

Spec: `openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md`, requirements REQ-DGV-001 to REQ-DGV-005.

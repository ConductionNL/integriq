# synchronization-files (delta)

## ADDED Requirements

### Requirement: An endpoint with an empty path segment SHALL be skipped before the source is called

The `fetch_file` rule MUST refuse a file endpoint whose path contains an empty segment
before any request is made to the source. Two consecutive slashes count as an empty
segment anywhere in the path; the scheme separator (`https://`), a protocol-relative
authority at the very start (`//host`), the query string and the fragment are not part of
the path and never count. This deliberately also refuses a key that carries an empty
segment of its own (`bucket//key.pdf`): such an endpoint is indistinguishable from one
whose id is missing. The rule MUST apply the refusal on every path that resolves an
endpoint: a single endpoint, an attachment object, a list of endpoints or objects, and the
queued `async` file fetch; the Flow engine's fetch-file step runs the same rule and
refuses the same endpoints.

The refusal MUST be logged as a warning that names the endpoint and the object. It MUST
NOT be counted as a failed fetch and MUST NOT abort the sibling files or the object. On
the rule engine it MUST be counted as `skipped` in the per-rule file tally, next to
`succeeded` and `failed`, and reported in the rule's outcome line
(`files saved: N, failed: M, skipped: K`); the Flow engine's step keeps no tally, so there
the warning is the only record.

A refused attachment in a list MUST keep its slot for the file cleanup that follows, the
same way a failed download does: a file stored for that attachment on an earlier run
MUST NOT be deleted because this run refused its endpoint. The synchronization contract
is not touched by the refusal; that it survives a failed or skipped file is
`synchronization-engine` REQ-021 (the contract is persisted before the after-rules run).

#### Scenario: An attachment with an empty document id is not requested
- GIVEN a source object whose attachment endpoint is `/api/v1/case/<zaak>/document//download`
- WHEN the `fetch_file` rule runs for that object
- THEN no request is made to the source for that endpoint
- AND a warning names the endpoint and the object
- AND the tally reports `skipped: 1`, `failed: 0`

#### Scenario: Sibling files are still fetched
- GIVEN a list of attachments of which one has an empty document id
- WHEN the `fetch_file` rule runs
- THEN the other attachments are fetched and saved
- AND only the empty one is skipped

#### Scenario: A file stored for a refused attachment is kept
- GIVEN an object that already stores `keep.pdf`
- AND the source now lists that attachment with an empty document id
- WHEN the `fetch_file` rule runs with file cleanup
- THEN `keep.pdf` is not deleted
- AND a file the source no longer lists at all is still removed

#### Scenario: Every attachment refused leaves the stored files alone
- GIVEN an object that stores two files
- AND the source lists both attachments with an empty document id
- WHEN the `fetch_file` rule runs with file cleanup
- THEN no file is deleted

#### Scenario: A double slash outside the path is not an empty segment
- GIVEN an endpoint `https://files.invalid/docs/a.pdf?next=//x` or `//files.invalid/docs/a.pdf`
- WHEN the `fetch_file` rule runs
- THEN the file is requested as usual

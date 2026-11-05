# synchronization-files (delta)

## ADDED Requirements

### Requirement: An endpoint with an empty path segment SHALL be skipped before the source is called

The `fetch_file` rule MUST refuse a file endpoint whose path contains an empty segment
(two consecutive slashes outside the scheme separator, the query string and the
fragment) before any request is made to the source. The refusal MUST be logged as a
warning that names the endpoint and the object, MUST be counted as `skipped` in the
per-rule file tally (next to `succeeded` and `failed`) and MUST be reported in the
rule's outcome line. It MUST NOT be counted as a failed fetch, MUST NOT abort the
sibling files or the object, and MUST leave the object and its synchronization contract
as they are. The rule MUST apply the same refusal on every path that resolves an
endpoint: a single endpoint, an attachment object, a list of endpoints or objects, and
the queued `async` file fetch.

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

#### Scenario: A double slash in the query string is not a path segment
- GIVEN an endpoint `https://files.invalid/docs/a.pdf?next=//x`
- WHEN the `fetch_file` rule runs
- THEN the file is requested as usual

#### Scenario: The object keeps its contract across runs
- GIVEN a case whose only attachment has an empty document id
- WHEN the synchronization runs twice
- THEN there is one object and one contract for that case after both runs

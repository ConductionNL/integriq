# Delta for edurep-source

## ADDED Requirements

### Requirement: An open file of a record is fetched for a copy
The `download` operation (`id`, `url`) MUST fetch the bytes of one file the record itself names, so a
consuming app can keep a copy without the browser fetching it. Only a file on https whose access rights
(the file's own, else the record's) are `OpenAccess` is fetched; any other address is refused with
`file-not-copyable`, an unknown record with `not-found`, a switched-off connection with `source-disabled`.
The fetch follows at most three https redirects, times out after 30 seconds and refuses a file over 25 MB
with `file-too-large`. The answer carries `sourceId`, `url`, `name` (the safe last path segment), `mimeType`,
`size` and `contentBase64`. The licence decision (link or copy) stays with the consumer. With the mock flag
the fetch answers recorded bytes and never calls the network.

#### Scenario: A teacher copies an open file
- GIVEN a record whose file `https://maken.wikiwijs.nl/files/Betoog%20schrijven.pdf` has open access
- WHEN learniq asks `download` for that record and that address
- THEN the answer carries the file's bytes, `name` `Betoog-schrijven.pdf` and its media type

#### Scenario: A restricted file stays at the source
- GIVEN the same record names a second file with `RestrictedAccess`
- WHEN learniq asks `download` for that second address
- THEN the request is refused with `file-not-copyable` and nothing is fetched

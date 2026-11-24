# Proposal: edurep-source-file-download

## Why
learniq's open learning material (`open-learning-material` task 2.3) copies a file into Nextcloud when the
licence allows it, and the spec says the copy is fetched through integriq, never from the browser. The
learning material source offered `search`, `find`, `status` and `saveSettings`, but no way to fetch a file,
so learniq added every copyable record as a link.

## What changes
- A `download` operation on the learning material request event: one file the record names, on https,
  with open access, at most 25 MB, answered as base64 with its name and media type.
- `MaterialFileFetcher` with an HTTP implementation and a recorded mock, bound by `edurep.feature_flag`
  like the source.

## Out of scope
The licence decision (it stays in learniq), storing the file (learniq writes it into Nextcloud).

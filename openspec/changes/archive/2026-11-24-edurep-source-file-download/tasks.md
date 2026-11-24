# Tasks: edurep-source-file-download

- [x] 1.1 `MaterialFileFetcher` (interface, `MAX_BYTES` 25 MB), `MaterialFileFetcherHttp` (https redirects only, 30 s timeout, size limit, media type from the answer) and `MaterialFileFetcherMock` (recorded bytes), bound by `edurep.feature_flag`.
- [x] 1.2 `download` in `LearningMaterialService`: only a file the record names, on https, with open access; refusals `not-found`, `file-not-copyable`, `file-too-large`, `source-disabled`.
- [x] 1.3 Unit tests: `LearningMaterialServiceTest` (download answered, refused per case, too large passed on, file name), `MaterialFileFetcherHttpTest`.

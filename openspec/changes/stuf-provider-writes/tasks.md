# Tasks: stuf-provider-writes

- [x] 1. Tests for the mapping, the refusals, the forwarded write and the delivered HTTP status.
- [x] 2. `StufProviderOperationMapper` maps zakLk01 and edcLk01 onto generic operations.
- [x] 3. The mutation events carry the operation; `refuse()` takes a kind.
- [x] 4. The receiver answers a kennisgeving with a Bv03 or Fo03 and maps each kind to its status.
- [x] 5. The receiver refuses a forwarded write.
- [ ] 6. Live pass: a creeerZaak, an updateZaak and a voegZaakdocumentToe against an instance with
  the case app installed, plus one refused write read from the caller's side.

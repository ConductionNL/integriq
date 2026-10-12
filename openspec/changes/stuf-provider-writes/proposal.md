# Proposal: stuf-provider-writes

## Why

Decision 206 (12 Oct 2026): another system may write cases and documents into the case app over
StUF, but only through integriq and authenticated. Until now the provider receiver turned a zakLk01
or edcLk01 into an event carrying raw StUF element names, and the case app refused every one.

## What changes

- A zakLk01 with mutatiesoort T (creeerZaak) or W (updateZaak) and an edcLk01 with mutatiesoort T
  (voegZaakdocumentToe) are mapped by `StufProviderOperationMapper` onto a generic case operation:
  `create`, `update` or `add-document`, with generic keys (`title`, `startDate`, `caseTypeCode`,
  `initiator`, `content`). The owning app reads the operation from the event and never parses StUF.
- Any other mutatiesoort and a document whose inhoud is not base64 are refused by integriq. The
  owning app is not asked.
- A kennisgeving is answered as StUF-ZDS 1.2 pairs it: a Bv03 when accepted, a Fo03 when not. This
  replaces the Bv01 and Fo01 the receiver gave before.
- The owning app can say why it refuses (`refused`, `forbidden`, `not-found`, `conflict`). Each goes
  back as a Fo03 with HTTP 400, 403, 404 or 409. A fault never travels as HTTP 200.
- A write that a sibling app forwarded from its own URL is refused with HTTP 403. Queries can still
  be forwarded for the one release the wire-contract URLs live.

## Not changed

Authentication is the source's WS-Security UsernameToken with `acceptsProviderRequests` set, as for
queries. The service identity that carries out the write belongs to the owning app, which runs the
operation through its own authorised path. A request body stays capped at 2 MiB, so a document of
about 1.5 MB is the largest that fits; MTOM is not built.

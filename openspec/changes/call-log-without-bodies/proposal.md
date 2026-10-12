# call-log-without-bodies

## Why

A municipality must be able to show who consulted the BRP, when, and with what outcome. The
relation read built in `brp-relations-read` stayed out of the call log completely, because a call
record can hold bodies: during an investigation window on the source, and as a replay request
after a failure. That kept the family's numbers out of storage, and it also left no record that
the question was asked (decision 229).

## What changes

- `CallService::call` takes `withholdBodies`. It is an option on the one call, set in code by the
  caller. No setting, source field or request option switches it off.
- A call made with it is recorded as one call log record: source, direction, status, moment,
  the asking user, the request method and the URL without its query string, and the response
  status, time and size. It holds no request body, no response body, no headers, no query and no
  replay request, also inside an open investigation window and also when the same call passes
  `logBody`.
- The execution trace step of that call gets the same stripped request and response.
- The caller still receives the whole answer.
- `RegistrySourceGateway::readUnrecorded` becomes `readWithoutBodies` and uses the option.
  `BrpRelationReader` reads through it, so a relation read now shows on the source's call log
  page.

## Impact

No schema, route or version change. `userId` already exists on `call_log`; this change is the
first writer of it, and only on a record that withholds its bodies. A failed call made with the
option cannot be replayed from the call log, because nothing of its request is kept.

The URL path is recorded. A caller that uses the option must send its identifiers in the body,
as the relation read does.

## The BRP authorisation, checked

`brp-relations-read` named the BRP elements from memory. They are checked now against an
autorisatietabel that RvIG publishes (afnemersindicatie 252004,
https://publicaties.rvig.nl/media/13550/download, read 12 October 2026). It lists
Burgerservicenummer as 02.01.20 under category 02 Ouder1, 03.01.20 under category 03 Ouder2,
05.01.20 under category 05 Huwelijk/geregistreerd partnerschap and 09.01.20 under category 09
Kind. The municipality's own autorisatiebesluit stays the authority on what it may ask.

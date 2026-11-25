# Tasks: call-log-without-bodies

- [x] Red first: a call with the option inside an open investigation window, searched as a string.
- [x] `CallService::call(withholdBodies: true)` and `BodyCapturePolicy::withhold`.
- [x] The record names the asking user.
- [x] `BrpRelationReader` reads through `readWithoutBodies`; the record is asserted through the real gateway and call service.
- [x] The BRP element numbers checked against a published RvIG autorisatietabel.
- [x] Live pass: a relation read on an instance shows one record on the source's call log page, without bodies.
  Verified 25 Nov 2026 on Nextcloud 34.0.6 + MariaDB with OpenRegister main: a `brp-haalcentraal` source with
  `relationFields: true` against a local Haal Centraal stand-in, an investigation window open on it, one
  `BrpRelationReader::read()` as admin. Answered 4 relations; the source page's Recent calls shows 1 call (200 OK);
  the record holds only method + URL and status/time/size/encoding, `userId` admin, `bodyCaptured` false, no replay
  request; no stored call-log row and no log line contains the burgerservicenummer.

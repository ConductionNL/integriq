# Design: rod-adapter-bsn

## Architecture Overview

```
learniq gate answer {recordId, sourceKind, data}
  -> ExchangeJobRunner (mapping row: learner or schooladvies)
  -> ExchangeTargetDispatcher (berichtsoort from data, else scope)
  -> RodService::sendBericht -> RodEnvelopeTranslator -> provider
```

### Inputs (contract fixed with learniq, lane f-rod)

| mapping slug | sourceKind | data keys |
|---|---|---|
| `learniq-bron-rod-export-learner` | `learner-profile` | `eckId`, `givenName`, `familyName`, `birthDate`, `schoolId`, `persoonsgebondenNummer` (9 digits), `persoonsgebondenNummerType` (`burgerservicenummer`\|`onderwijsnummer`) |
| `learniq-bron-rod-export-schooladvies` (new) | `school-advies` | `persoonsgebondenNummer`, `persoonsgebondenNummerType`, `adviesvolgnummer`, `onderwijsaanbieder`\|null, `onderwijslocatie`\|null, `vestigingscode`, `adviesjaar`, `advies1`, `advies1Datum`, `advies2`\|null, `advies2Datum`\|null |

Learniq already ran the elfproef; integriq checks nine digits and the type only.

### Envelope

Every kind renders the number as:

```xml
<persoonsgebondenNummer><burgerservicenummer>NNNNNNNNN</burgerservicenummer></persoonsgebondenNummer>
```

The schooladvies body is (PvE 7.9.1, contract `DUO_PO_AdviesVO_V1`):

```xml
<AanleverenAdviesVO_Request xmlns="http://duo.nl/contract/DUO_PO_AdviesVO_V1">
  <persoonsgebondenNummer><onderwijsnummer>NNNNNNNNN</onderwijsnummer></persoonsgebondenNummer>
  <adviesvolgnummer>ADV2026001</adviesvolgnummer>
  <onderwijsaanbieder>100A200</onderwijsaanbieder>   <!-- when not null -->
  <onderwijslocatie>100X200</onderwijslocatie>       <!-- when not null -->
  <vestigingscode>12AB00</vestigingscode>
  <adviesjaar>2026</adviesjaar>
  <advies1><advies>VMBO_KB</advies><adviesdatum>2026-01-20</adviesdatum></advies1>
  <advies2><advies>VMBO_GL/TL</advies><adviesdatum>2026-05-15</adviesdatum></advies2> <!-- when not null -->
</AanleverenAdviesVO_Request>
```

### Routing

`ExchangeJobService::buildJob()` fills `exchangeMapping` when the owning app names none:
learniq + `bron-rod` + scope `berichtsoort: schooladvies` gets
`learniq-bron-rod-export-schooladvies`; learniq + `bron-rod` otherwise gets
`learniq-bron-rod-export-learner`. An explicit slug always wins. The dispatcher already reads
`berichtsoort` from the record, then the scope.

### Redaction

`RodPersonalNumberRedactor::redact(text, number)` replaces the known number and any standalone
run of nine digits with `[persoonsgebonden nummer]`. It runs on every provider message in
`RodService` (thrown, stored, logged) and on the transport message in `RodEdukoppelingClient`.
`ExchangeJobRunner` logs only the exception class when a mapping fails, because a mapping
error can quote its input.

## Trade-offs

- DUO's XSD is not printed in the PvE. Element names are the PvE field names in camelCase; the
  namespace follows the PvE's `wsa:Action` pattern. The live provider stays gated on the DUO
  certificate; checking names against the XSD is a task for whoever enables it.
- The AdviesVO value list is not copied. It changes by school year (values end on 31-7-2026);
  DUO rejects an unknown value with `046_waardenlijst_fout`.
- `bsnHash` keeps its name; it hashes the persoonsgebonden nummer of either type.

## Nextcloud Integration

- Services: `RodEnvelopeTranslator`, `RodService`, `RodEdukoppelingClient`, `ExchangeJobService`,
  `ExchangeJobRunner`, new `RodPersonalNumberRedactor` (no DI registration; default-constructed).
- No new events, routes or controllers.

## Security Considerations

The number is personal data under the AVG. It lives only in memory and in the message to DUO.
Logs, exceptions, `rod_message.error` and exchange rejections never hold it (tests capture the
logger). Records are not stored by integriq (unchanged, D7).

## File Structure

```
lib/Service/Rod/RodEnvelopeTranslator.php        (modified)
lib/Service/Rod/RodPersonalNumberRedactor.php    (new)
lib/Service/Rod/RodEdukoppelingClient.php        (modified: redact transport message)
lib/Service/RodService.php                       (modified: redact, hash either type)
lib/Service/Exchange/ExchangeJobService.php      (modified: default mapping)
lib/Service/Exchange/ExchangeJobRunner.php       (modified: log exception class only)
lib/Settings/register.d/learniq-exchange-jobs.json (learner row 1.1.0, new schooladvies row)
tests/Unit/Service/Rod/RodEnvelopeTranslatorTest.php, RodPersonalNumberLeakTest.php
tests/Unit/Service/Exchange/ExchangeJobServiceTest.php
```

## Seed Data

Two mapping rows in `lib/Settings/register.d/learniq-exchange-jobs.json`:
- `learniq-bron-rod-export-learner` version 1.1.0: `persoonsgebondenNummer`,
  `persoonsgebondenNummerType` replace the wrong `"bsn": "eckId"`.
- `learniq-bron-rod-export-schooladvies` version 1.0.0: identity map of the eleven keys above.

No schema changes.

## Declarative-vs-imperative decision

Not applicable: an external integration (ADR-031 exception) with no lifecycle, aggregation or
notification behaviour.

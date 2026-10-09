# Check the ROD adapter against DUO's XSD

Do this before you switch the ROD adapter from the `log` provider to `edukoppeling`.
DUO checks every message body against its XSD. A body that fails gets a SOAP fault and is not processed.

## Why this check is still open

The ROD adapter builds its XML from the Programma van Eisen (PvE) ROD-PO, version 1.14.2 of 15 April 2026:
https://duo.nl/zakelijk/images/pve-po.pdf

The PvE lists the fields, their formats and whether they are required. It does not include the XSDs.
It says so itself in section 6.1: "Bij eventuele afwijkingen tussen de beschrijving hieronder en de XSD is het XSD altijd leidend."

On 28 September 2026 we searched for a public copy. We checked:

- the supplier pages for PO, VO and MBO on duo.nl/zakelijk (they only offer the PvE PDFs);
- the ROD pages for schools on duo.nl/zakelijk;
- a web search and a GitHub code search on the contract names below.

None of them publishes the XSDs.
The PvE PO supplier page says new software suppliers contact the DUO helpdesk to hear the requirements.
PvE section 5.1.3 says an institution or supplier must be registered with DUO before it gets access to a web service.
So the XSD package comes from DUO, after registration. We did not guess its content.

## What to ask DUO for

Email helpdeskpo@duo.nl, or call 050 599 77 66 on working days from 9.00 to 13.00.
Mention the instellingscode or bestuursnummer of the school you connect for.

Ask for the ROD-PO WSDL and XSD package, which DUO delivers as one set (PvE section 5.5). You need at least:

| File | Why |
|---|---|
| `DUO_PO_AdviesVO_V1.xsd` and `.wsdl` | `AanleverenAdviesVO_Request`, the school advice (PvE 7.9.1) |
| the BO inschrijving contract `.xsd` and `.wsdl` | `AanleverenInschrijvingBO_Request` and `AanleverenVerwijderingInschrijvingBO_Request` (PvE 7.2.1, 7.2.3) |
| `DUO_PO_GeneriekBasisgegevens_V1.xsd` | shared groups such as the bedrijfsdocument and the persoonsgebonden nummer |
| `BasisSchema.xsd` | functional types |
| `KerntypeSchema.xsd` | technical types and patterns |

The PvE names the BO contract in two ways. Section 5.5 shows `DUO_PO_inschrijvenBO_V1`. Sections 5.4.2 and 7.2 use `DUO_PO_InschrijvingBO_V1`, also in the `wsa:Action` example. Ask DUO which one is current.

Write down the build number and date in each file's comment (PvE section 5.7). Record them next to the files.

## What the adapter emits today

The code is in `lib/Service/Rod/RodEnvelopeTranslator.php` and `lib/Service/Rod/RodAdviesVoBuilder.php`.
Element names are the PvE field names in camelCase.

For `schooladvies` it emits this element inside its own `RodBericht/body` wrapper:

```xml
<AanleverenAdviesVO_Request xmlns="http://duo.nl/contract/DUO_PO_AdviesVO_V1">
  <persoonsgebondenNummer><burgerservicenummer>123456782</burgerservicenummer></persoonsgebondenNummer>
  <adviesvolgnummer>ADV2026001</adviesvolgnummer>
  <onderwijsaanbieder>100A200</onderwijsaanbieder>
  <onderwijslocatie>100X200</onderwijslocatie>
  <vestigingscode>12AB00</vestigingscode>
  <adviesjaar>2026</adviesjaar>
  <advies1><advies>VMBO_KB</advies><adviesdatum>2026-01-20</adviesdatum></advies1>
  <advies2><advies>VMBO_GL/TL</advies><adviesdatum>2026-05-15</adviesdatum></advies2>
</AanleverenAdviesVO_Request>
```

Only the root element declares the namespace, as a default namespace.
The code builds the children without a namespace, but the serialised text has no `xmlns=""` on them.
So a receiver that parses the text reads every child as qualified in the DUO namespace.
Always validate the serialised text, never the DOM object in memory: the two give different answers.
The namespace follows the PvE's `wsa:Action` pattern, `http://duo.nl/contract/<contract>`.

For `inschrijving`, `uitschrijving` and `verblijfsgegevens` it emits no DUO request element at all.
It writes a flat `RodBericht/body` with `persoonsgebondenNummer` and these fields:

| berichtsoort | fields the adapter writes |
|---|---|
| `inschrijving` | `inschrijvingsdatum`, `leerjaar`, `groep`, optional `oppStartdatum`, `oppEinddatum` |
| `uitschrijving` | `uitschrijvingsdatum`, `redenUitschrijving` |
| `verblijfsgegevens` | `ingangsdatum`, `leerjaar`, `groep`, optional `oppStartdatum`, `oppEinddatum` |

## Differences you can already see in the PvE

These need no XSD. They are read straight from the PvE, so fix them together with the XSD pass.

1. **Registration is not a DUO message yet.** DUO has no `inschrijving`, `uitschrijving` or `verblijfsgegevens` operation.
   It has `AanleverenInschrijvingBO_Request`, which carries the latest state of one enrolment.
   An exit is `datumUitschrijving` on that same message.
   `AanleverenVerwijderingInschrijvingBO_Request` deletes an enrolment that was sent by mistake.
   SO and VSO have their own contracts (PvE 7.3, 7.4).
2. **Registration field names differ.** The PvE uses `inschrijvingvolgnummer` (required), `datumInschrijving`, `datumUitschrijving` and `nNCA`.
   Leerjaar, groep and vestigingscode sit in a repeating group `inschrijvingPeriodeBO` (1 to 100) with its own `datumBegin`.
   The OPP dates are an `Ontwikkelingsperspectief` group (0 to 100) with `datumbegin` and `datumeinde`.
   The PvE has no `redenUitschrijving` for BO.
3. **No bedrijfsdocument.** Every DUO message body carries a bedrijfsdocument (PvE 6.3) with `identificatiecodeBedrijfsdocument` (a UUID), `instellingscode` and `datumTijdBedrijfsdocument` (UTC).
   The adapter writes `kenmerk` and `tijdstipBericht` in its own `stuurgegevens` instead.
4. **The wrapper is ours.** `RodBericht`, `stuurgegevens` and `body` do not appear in the PvE.
   The SOAP body should hold the DUO request element itself (PvE 5.4).
5. **Case of the advice elements.** The PvE table writes `Advies1`, `Advies2`, `Advies` and `Adviesdatum` with a capital. The adapter writes them in lower case. Only the XSD settles this.

## The comparison to run

Put the DUO files in `tests/fixtures/duo/rod-po/`, with a `SOURCE.md` that names who sent them, the date and each file's build number.
Keep the relative imports between the files intact.

### 1. Validate what the adapter renders

Render one message per berichtsoort and validate the DUO request element against its XSD:

```php
$xml = (new RodEnvelopeTranslator())->translate('schooladvies', 'kenmerk-1', $payload);
$doc = new DOMDocument();
$doc->loadXML($xml);
$request = $doc->getElementsByTagNameNS(RodAdviesVoBuilder::NAMESPACE, 'AanleverenAdviesVO_Request')->item(0);
// Re-parse the serialised element, so you validate what DUO receives.
$only = new DOMDocument();
$only->loadXML($doc->saveXML($request));
libxml_use_internal_errors(true);
$valid = $only->schemaValidate(__DIR__.'/../../../fixtures/duo/rod-po/DUO_PO_AdviesVO_V1.xsd');
// On false, print libxml_get_errors(): each error names the element and line.
```

Or from the shell, on a rendered file:

```bash
xmllint --noout --schema tests/fixtures/duo/rod-po/DUO_PO_AdviesVO_V1.xsd adviesvo-request.xml
```

Run it for these payloads:

- a full advice with both advices, with a burgerservicenummer;
- an advice with only `advies1`, with an onderwijsnummer;
- an advice without `onderwijsaanbieder` and `onderwijslocatie`.

### 2. Compare the structure element by element

For each request type, write down from the XSD and check against the adapter:

| Check | Where to look in the XSD | Where to look in the adapter |
|---|---|---|
| root element name and target namespace | `xs:schema/@targetNamespace`, the top `xs:element` | `RodAdviesVoBuilder::NAMESPACE`, `createElementNS` |
| qualified or unqualified children | `elementFormDefault` | on the wire the children inherit the default namespace, so they read as qualified |
| element names and their case | every `xs:element/@name` | the names in `append()` and `appendRegistration()` |
| order | `xs:sequence` | the order of `appendText` calls |
| cardinality | `minOccurs`, `maxOccurs` | the required and optional field lists |
| the persoonsgebonden nummer choice | `xs:choice` and its wrapper name | `appendPersonalNumber()` |
| where the bedrijfsdocument goes | the generic basisgegevens XSD | not emitted yet |
| patterns and lengths | the types in `KerntypeSchema.xsd` | `RodAdviesVoBuilder::FORMATS`, `VALUE_FORMAT` and the nine-digit check |
| date and dateTime formats | `xs:date`, `xs:dateTime` | `Y-m-d` for advice dates |

### 3. Fix and lock it

Change the translator and the builder until every payload above validates.
Add the validation as a unit test under `tests/Unit/Service/Rod/`, so a later change cannot drift from the XSD.
Rebuild the registration messages as `AanleverenInschrijvingBO_Request` and `AanleverenVerwijderingInschrijvingBO_Request` at the same time.
That touches the learniq payload contract, so agree the field list with learniq first.
Then test against the DUO field test environment. It needs a TRIAL PKIoverheid Organisatie Server CA G3 certificate (PvE 5.1.3).

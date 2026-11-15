# Vendored Logius Berichtenbox contract

These files are the official MijnOverheid Berichtenbox schemas, copied byte for byte. Do not edit them. A unit test (`tests/Unit/Adapters/Berichtenbox/VendoredContractTest.php`) compares every file below with its sha256, so a change without a new line here fails the suite.

- Package: https://www.logius.nl/sites/default/files/public/bestanden/diensten/MijnOverheid/logius-mijnoverheid-berichtenbox-xsd-2026.zip
- Example messages: https://www.logius.nl/sites/default/files/website/diensten/mijnoverheid/bestanden/logius-mijnoverheid-voorbeeldberichten-berichtenbox.zip
- Described by: Technische Aansluithandleiding MijnOverheid Berichtenbox 1.6.4, https://www.logius.nl/domeinen/interactie/mijnoverheid/documentatie/technische-aansluithandleiding-mijnoverheid-berichtenbox
- Downloaded: 2026-10-07. Both zips are kept in `tests/fixtures/berichtenbox/logius/`.

Folder names are ours (the package uses spaces, an apostrophe and the misspelling `Reponse`). File names are Logius'.

One file is a copy, not a download. `GLOBEBatchRequest.xsd` imports `GLOBEBatchRequestTypes.xsd`, but the package ships that file as `GLOBEBatchRequestTypes_128ch.xsd`. `GLOBEBatchRequestTypes.xsd` here is a byte-identical copy of the `_128ch` file, so the import resolves without editing a Logius file. Which name Logius means is open question Q3 in `openspec/changes/berichtenbox-client/design.md`.

`xsd0.xsd` and `xsd2.xsd` import their neighbours from an internal RDW host (`http://rdw64825.ot.tld:9002/...`). Nothing here fetches those URLs; a validator maps them to the local files.

## sha256

| File | Package path | sha256 |
|---|---|---|
| `AbonnementService/AbonnementAanvraag.xsd` | `XSD's Berichtenbox_/XSD Berichtenbox Abonnementservice/AbonnementAanvraag.xsd` | `71adc7de77a1fe2df539690f9080d0d0b64759b0edd827c6cbbf5224c70cbcad` |
| `AbonnementService/AbonnementAntwoord.xsd` | `XSD's Berichtenbox_/XSD Berichtenbox Abonnementservice/AbonnementAntwoord.xsd` | `8c18ed56a24b0c1e8a1c0a76115859837af8e9339d10ad34a58666b21512d2fc` |
| `BerichtVerwerkService/Request/GLOBEBatchRequest.xsd` | `XSD's Berichtenbox_/XSD Berichtverwerkservice/Request/GLOBEBatchRequest.xsd` | `64889c97e10c763db203694a3cc8f096780390a13383c986f4849f28a502b286` |
| `BerichtVerwerkService/Request/GLOBEBatchRequestTypes.xsd` | copy of `GLOBEBatchRequestTypes_128ch.xsd` | `f9be34efce3838082024ebeeaf7fe03adddf20c1ba94994007cc720cc8da8bde` |
| `BerichtVerwerkService/Request/GLOBEBatchRequestTypes_128ch.xsd` | `XSD's Berichtenbox_/XSD Berichtverwerkservice/Request/GLOBEBatchRequestTypes_128ch.xsd` | `f9be34efce3838082024ebeeaf7fe03adddf20c1ba94994007cc720cc8da8bde` |
| `BerichtVerwerkService/Response/GLOBEBatchResponse.xsd` | `XSD's Berichtenbox_/XSD Berichtverwerkservice/Reponse/GLOBEBatchResponse.xsd` | `e694d33cc628f542556e1c322bdb1f6bbdbbeefc5afdd0798e99abae42486c11` |
| `BerichtVerwerkService/Response/GLOBEBatchResponseTypes.xsd` | `XSD's Berichtenbox_/XSD Berichtverwerkservice/Reponse/GLOBEBatchResponseTypes.xsd` | `bd8f31b5f57604272c4f63cfb2f72a5526d27600b716f3f61369cfae3c022d9d` |
| `BerichtenboxValidatieService/BerichtenboxValidatieService.wsdl` | `XSD's Berichtenbox_/XSD-WSDL BerichtenboxValidatieService/BerichtenboxValidatieService.wsdl` | `d893519f176be441018034c80f8d20e3e9cd55205d3655b42ccf09921328d223` |
| `BerichtenboxValidatieService/xsd0.xsd` | `XSD's Berichtenbox_/XSD-WSDL BerichtenboxValidatieService/xsd0.xsd` | `281079b61fb5cd9a7d01e5b6b167a22df8f8e609348d51fd5fac68ff1d76b1a3` |
| `BerichtenboxValidatieService/xsd1.xsd` | `XSD's Berichtenbox_/XSD-WSDL BerichtenboxValidatieService/xsd1.xsd` | `fbf96eb98e41a0ec09d58536d65867aba4d96de7d28c4784c68d6f1bcb8d2614` |
| `BerichtenboxValidatieService/xsd2.xsd` | `XSD's Berichtenbox_/XSD-WSDL BerichtenboxValidatieService/xsd2.xsd` | `1bbb7768cb191e91fe2c56bc959cd5eb6c2be3f1e314cbe4d0278da01b8936b0` |
| `BerichtenboxValidatieService/xsd3.xsd` | `XSD's Berichtenbox_/XSD-WSDL BerichtenboxValidatieService/xsd3.xsd` | `44242222d46cf992a4f47e528b4fb25efc1d82edc3873d236b61dcedccc81047` |

## Fixtures (tests/fixtures/berichtenbox/logius)

| File | sha256 |
|---|---|
| `GEB-BV.xml` | `aa2e8790f560e75a68c745fc1cde211d620ddfda2cf0eb8bf3d134ea949c190b` |
| `GLOBE-R-A-Request - BSN Lijst.xml` | `b03f392d2847d88ea56f9a10b13155773a0c786da3c8364b2a81ba69fd198ac9` |
| `GLOBE-R-A-Request - Mutatie.xml` | `f4836ef38553ed67e3bd74bceb0103cb6c9c49be6b4a2552348db0bb6ca351af` |
| `GLOBE-R-A-Request - Volledig.xml` | `ad5bc06031b56ec55a5152aa64a21a51ff545a543e474cd4ce3a525cbb0d1b60` |
| `GLOBE-R-BV-Request.xml` | `aa2e8790f560e75a68c745fc1cde211d620ddfda2cf0eb8bf3d134ea949c190b` |
| `logius-mijnoverheid-berichtenbox-xsd-2026.zip` | `0965489ad2d5375d89c0f9b08f2af2ff092b2d10d195837bc29a91a4a9456300` |
| `logius-mijnoverheid-voorbeeldberichten-berichtenbox.zip` | `6d4f972d291ddb3703b75fd7189e816b3ed035027bfce2b3a11aca2013eb7f10` |

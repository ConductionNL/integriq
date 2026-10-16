# stuf-provider-receiver Specification (delta: writes)

**Status:** proposed
**Scope:** integriq
**Depends on:** the `stuf-provider-receiver` change and decision 206.

@e2e exclude The receiver is a SOAP exchange with an external case system and has no browser
surface; it is proven by StufProviderReceiverTest and StufProviderRequestReceivedListenerTest.

## ADDED Requirements

### Requirement: A write message reaches the owning app as a generic case operation

The receiver SHALL map a zakLk01 with mutatiesoort T onto a `create` operation, a zakLk01 with
mutatiesoort W onto an `update` operation and an edcLk01 with mutatiesoort T onto an `add-document`
operation, and carry it on the mutation event. The operation SHALL use generic keys and ISO dates,
and SHALL read the current values from the last object of the message.

#### Scenario: A creeerZaak becomes a create operation

- GIVEN an authenticated zakLk01 with mutatiesoort T, an omschrijving, a startdatum and a zaaktype
- WHEN it is received
- THEN the `StufCaseMutationEvent` carries an operation with action `create`, `title`, `startDate`
  as Y-m-d and `caseTypeCode`

#### Scenario: A voegZaakdocumentToe carries the decoded document

- GIVEN an authenticated edcLk01 with mutatiesoort T and a base64 inhoud
- WHEN it is received
- THEN the `StufDocumentMutationEvent` carries an operation with action `add-document`, the zaak's
  identifier as `caseIdentifier` and the decoded bytes as `content`

### Requirement: Only create, update and document add are carried out

The receiver SHALL refuse a zakLk01 or edcLk01 with any other mutatiesoort, and an edcLk01 whose
inhoud is not base64, with a Fo03 and HTTP 400, without dispatching an event.

#### Scenario: A delete is refused before the owning app is asked

- GIVEN an authenticated zakLk01 with mutatiesoort V
- WHEN it is received
- THEN the answer is a Fo03 with HTTP 400 and no event is dispatched

### Requirement: A refused write is a StUF fault with a refusing status

The receiver SHALL answer an accepted kennisgeving with a Bv03 and HTTP 200, and every refusal with
a Fo03 inside a SOAP fault: HTTP 400 when the owning app refuses, 403 when it forbids, 404 when the
case does not exist, 409 when it already exists, 401 when the sender is not authenticated and 500
when nobody answered. The status SHALL reach the caller unchanged by Nextcloud's middleware.

#### Scenario: A forbidden write is not delivered as success

- GIVEN the owning app refuses a creeerZaak as forbidden
- WHEN the response has passed the OCS middleware
- THEN the caller reads HTTP 403 and a Fo03, and no Bv03

### Requirement: Writes arrive on integriq's own route only

The receiver SHALL refuse a zakLk01 or edcLk01 that a sibling app forwarded from its own URL with a
Fo03 and HTTP 403, without dispatching an event. A forwarded query SHALL still be answered.

#### Scenario: A write on the case app's old URL is refused

- GIVEN a sibling app forwards a creeerZaak it received on its own URL
- WHEN integriq's listener hands it to the receiver
- THEN the answer is a Fo03 with HTTP 403 and no event is dispatched

## MODIFIED Requirements

### Requirement: Answers go back as StUF responses

An answered case query SHALL go back as a zakLa01, a person query as an npsLa01, and an accepted
mutation as a Bv03, each cross-referencing the request's referentienummer. A refusal by the owning
app SHALL go back as a Fo03 for a kennisgeving and a Fo02 for a vraag.

#### Scenario: An accepted kennisgeving is a Bv03

- GIVEN the owning app accepts a zakLk01
- WHEN the response is built
- THEN it is a Bv03Bericht carrying the request's referentienummer as crossRefnummer

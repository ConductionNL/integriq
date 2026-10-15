# stuf-provider-receiver Specification

**Status:** proposed
**Scope:** integriq
**Depends on:** the `stuf-zkn-bridge` spec (the StUF-ZKN source) and decision 199.

## Purpose

Integriq answers StUF-ZKN and StUF-BG provider requests from a case system on behalf of the app that
owns the cases. Integriq owns the wire; the owning app owns the answer.

@e2e exclude The receiver is a SOAP exchange with an external case system and has no browser
surface; it is proven by the PHPUnit suites (StufProviderReceiverTest, StufProviderControllerTest,
StufProviderRequestReceivedListenerTest, StufProviderQueryEventTest).

## ADDED Requirements

### Requirement: Provider requests are authenticated against the source

The receiver SHALL accept a request only for a `stuf-zkn` source that sets
`configuration.acceptsProviderRequests` to true and carries a WS-Security UsernameToken, and only
when the envelope's UsernameToken matches it. An unknown source, a source that did not opt in and a
wrong password SHALL get the same refusal, so the route is not an oracle for configured sources.

A UsernameToken that carries a `Nonce` and a `Created` SHALL also be fresh and unused: `Created`
within five minutes of now, and a nonce not seen before for that source and user. A repeat SHALL get
the same refusal as a wrong password. A token with only one of the two SHALL be refused.

This replay protection is TRANSITIONAL. A token with neither `Nonce` nor `Created` is accepted on
its password alone while the source's `authentication.wsse.requireNonce` is false (the default,
kept so existing case systems keep working), and an attacker who can read a captured request can
strip both elements and replay it. A source SHOULD set `requireNonce` to true as soon as its case
system sends them. Moving to `PasswordDigest` (Base64(SHA1(Nonce + Created + Password)), so the
password and the replay protection cannot be separated) is the follow-up that closes this.

#### Scenario: A wrong password is refused before anything is asked

- GIVEN a source that accepts provider requests
- WHEN a zakLv01 arrives with a different password
- THEN the answer is HTTP 401 with a fault and no event is dispatched

#### Scenario: A replayed nonce is refused

- GIVEN a source that accepts provider requests and a request carrying a Nonce and a fresh Created
- WHEN the same request arrives a second time
- THEN the answer is HTTP 401 with a fault and no event is dispatched

#### Scenario: A source that requires a nonce refuses a plain token

- GIVEN a source with `authentication.wsse.requireNonce` true
- WHEN a zakLv01 arrives with the right password and no Nonce or Created
- THEN the answer is HTTP 401 with a fault and no event is dispatched

### Requirement: Each query reaches the owning app as a typed event

The receiver SHALL turn a zakLv01 into a `StufCaseQueryEvent`, a zakLk01 into a
`StufCaseMutationEvent`, an npsLv01 into a `StufPersonQueryEvent` and an edcLk01 into a
`StufDocumentMutationEvent`, each carrying the source id, and wait for the answer in the event's
synchronous answer slot. The cases route SHALL take only zakLv01, zakLk01 and edcLk01; the persons
route only npsLv01.

#### Scenario: A case query carries its criteria

- GIVEN a zakLv01 asking for identificatie `ZAAK-2026-7`
- WHEN it is received
- THEN one `StufCaseQueryEvent` is dispatched with that identificatie as a criterion

### Requirement: Answers go back as StUF responses

An answered case query SHALL go back as a zakLa01, a person query as an npsLa01, and an accepted
mutation as a Bv03, each cross-referencing the request's referentienummer. A refusal by the owning
app SHALL go back as a Fo03 for a kennisgeving and a Fo02 for a vraag, with HTTP 400.

#### Scenario: A found zaak is answered as a zakLa01

- GIVEN the owning app answers the query with one case
- WHEN the response is built
- THEN it is a zakLa01 with aantalVoorkomens 1 and the case's identificatie

### Requirement: An unanswered query is a fault, never an empty answer

When no listener answers the event, or the listener fails, the receiver SHALL answer with a fault
carrying StUF055, never with an empty zakLa01 or npsLa01: a fault for a vraag is a Fo02, for a
kennisgeving a Fo03.

#### Scenario: Nobody answers a case query

- GIVEN no app answers the `StufCaseQueryEvent`
- WHEN a zakLv01 is received
- THEN the answer is a Fo02 with code StUF055 and no zakLa01

### Requirement: A sibling app forwards its wire-contract URLs for one release

A sibling app SHALL be able to hand integriq, in process, a provider request that arrived on its own
URL through `StufProviderRequestReceivedEvent`, naming the source and the service, and get the same
status and body the integriq route would give.

#### Scenario: dossiq forwards its old cases URL

- GIVEN dossiq received a zakLv01 on `/api/stuf/cases`
- WHEN it dispatches `StufProviderRequestReceivedEvent`
- THEN integriq's listener answers it with the receiver's status and body

# Proposal: stuf-provider-receiver

## Why

Decision 199 (11 Oct 2026): dossiq's StUF provider side moves into integriq. A municipal case
system that asks "give me this zaak" (zakLv01), "give me this person" (npsLv01), or tells "this zaak
or document changed" (zakLk01, edcLk01) talks to integriq. Integriq owns the wire: WS-Security, the
envelope, the stuurgegevens and the fault catalogue. The app that owns the cases answers the
question, through an event, and never parses StUF itself.

dossiq answered these four messages with stub handlers that returned an empty zakLa01 or npsLa01.
An empty answer reads as "no such zaak" to the caller, which is a lie when nobody looked.

## What changes

- Two public routes, `POST /api/stuf-zkn/provider/{sourceId}/cases` and `.../persons`. The source is
  a `stuf-zkn` source that opts in with `configuration.acceptsProviderRequests: true`. Its
  WS-Security UsernameToken is the authentication.
- Four typed events, one per message: `StufCaseQueryEvent` (zakLv01), `StufCaseMutationEvent`
  (zakLk01), `StufPersonQueryEvent` (npsLv01) and `StufDocumentMutationEvent` (edcLk01). Each carries
  the source id and the parsed question, and has a synchronous answer slot.
- Answers go back as zakLa01, npsLa01 or Bv01. A refusal goes back as a Fo01 (kennisgeving) or Fo02
  (vraag). An event nobody answered goes back as a fault with StUF055, never as an empty answer.
- `StufProviderRequestReceivedEvent` lets a sibling app forward a request that arrived on its own
  wire-contract URL (dossiq's `/api/stuf/cases`, `/persons`, `/zaken`, `/personen`, one release).

## Not changed

`StUFZKNService` and `StUFBGService` are not used by the receiver: they read and write integriq's
own `zaken` and `personen` registers, while decision 199 says the owning app answers. They have no
route and no caller today. Retiring them is left to a follow-up so this PR stays one move.

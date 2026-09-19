# Design: statutory-gateways-and-frameworks

Kind: code. Size L. One catalogue entry shape that carries a law, two new
sector adapters, a broker that is configuration, a registry that is a
binding, and a bridge. Nothing here invents a protocol: every route already
has a standard and most already have an adapter.

## D1. The claim lives on the catalogue entry, not in a document

C-integrations-36 asks for the Archiefwet, the Archiefregeling and the AVG
to be "named separately as frameworks the product meets". A statement in a
tender document ages the day it is written. A statement on the catalogue
entry ages with the adapter, because the same registry that ships the
adapter ships the claim.

Three claim levels and no more: `conformant`, `partial`, `planned`. A fourth
would invite a negotiation. `claimEvidence` is required because a claim with
no evidence is the thing the lane warned about, keeping the row "apart from
the certification row: a claim, not a certificate".

## D2. The broker is procurement, so it is configuration

The lane's clause on C-integrations-39 is the whole argument: "the broker is
a procurement decision the case system usually forces, and offering two is a
deployment capability". `digikoppeling-adapter` REQ-DK-005 already resolves
PKIoverheid keys through a broker rather than holding plaintext keys, so the
seam exists. What changes is that the broker is selected, tested and audited
like any other configuration, and a wrong one fails before a call rather than
during one.

## D3. CORV and GGK are adapters, not a programme

`iwmo-ijw-adapter` is the pattern: a sector route with its own schemas over
the shared delivery machinery. CORV and GGK follow it. They do not get their
own client, their own retry or their own log, because every one of those
already exists and a second copy is how a route stops being observable.

## D4. Wmebv is a route property, not a feature

The Wmebv names twelve obligations. Some are the route's (an accessible
electronic way in, a route that can receive what it says it receives) and
some are the case system's (a confirmation of receipt, a decision reachable
electronically). Integriq cannot meet the second set and should not claim to.

So the gateway declares two lists: what it meets, and what it hands on. The
handed list is the useful half, because it is a question a consumer can be
asked. dossiq's Awb 4:3a ontvangstbevestiging is the first item on it, and
the discovery summary already names it as the thing to fix first.

## D5. Publication by reference, because the document is not ours

Filinq holds documents. OpenRegister holds objects. Copying a document into
integriq to publish it would make integriq a second store with a second
retention question and a second AVG answer. The gateway takes a reference and
a publication instruction, and it records the identifier that comes back.

That identifier is the valuable half: without it, "we published it" is an
assertion. Cluster 50 owns publication and the national indexes in
opencatalogi, and this gateway is the wire underneath it.

## D6. The registry binding is the Common Ground promise made testable

C-integrations-47's clause says it: "'our app works on your existing
registers' is the Common Ground promise made testable, and no row asks
whether an app can run on a foreign store". A binding makes it a
configuration question rather than a slide.

The hard part is not the read, it is keeping the shape stable. The binding
resolves to a reader and a writer, and every consumer sees the same shape
whichever resolves. Testing at configuration time rather than at first use
is what keeps a wrong binding from looking like an intermittent outage.

## D7. The bridge opens outward

An inbound firewall opening is the thing a gemeente's security officer
refuses, and rightly. The bridge sits inside the customer network and dials
out, and integriq offers the resulting connection as a transport a gateway
can pick. That makes it one more transport in the call log rather than a
parallel universe with its own observability.

Revocation is a first-class act, not a configuration edit, because a bridge
is a credential in the shape of a process.

## D8. Jurisdiction is declared, and `unknown` is a real answer

C-integrations-37 reads `yes` for dossiq "by construction, self-hosted; no
row records it". Self-hosting answers where the instance runs. It does not
answer where a gateway sends data, which is the question a DPIA asks.

Declaring it per gateway answers both. `unknown` renders as `unknown`,
because a default would make an unanswered question look answered, which is
the failure mode this whole change exists to avoid.

## D9. What stays out, and why the boundary is worth writing down

C-integrations-3 is in this cluster and its work is not. A missed term with a
statutory consequence is the deadline engine's outcome, and dossiq already
ships `NoticeOfDefaultController` and `DwangsomPaymentCallbackController`.
Building any part of it here would put one behaviour in two apps.

C-integrations-23 reads `yes` and is recorded rather than built. Writing that
down is cheaper than somebody rediscovering it in six months, which is the
reasoning D17 used for the whole `not` bucket.

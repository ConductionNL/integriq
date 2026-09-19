# statutory-gateways Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- statutory-gateways-and-frameworks

## Purpose

Integriq holds the statutory routes a Dutch government instance needs, and
says out loud which law each one serves, which broker it runs over and where
its endpoint sits. Round 4 discovery cluster 56, candidates C-integrations-27,
28, 29, 37, 47, 3, 4, 30, 36, 39, 46 and 23, rows 12.9 and 12.17, decisions
D21 and D6.

## ADDED Requirements

### Requirement: A gateway declares its standard and its conformance claim (REQ-SG-001)

Every catalogue entry of kind `gateway` MUST declare `standard` (the named
standard or law, such as `Digikoppeling WUS`, `StUF-ZKN 3.10`, `Wmebv`), a
`claimLevel` of `conformant`, `partial` or `planned`, and a `claimEvidence`
string naming what the claim rests on. The catalogue MUST filter by standard.
A claim MUST NOT be rendered as a certification.

#### Scenario: the catalogue answers which laws the instance reaches
- GIVEN the Digikoppeling, StUF, CORV and Wmebv gateway entries
- WHEN an administrator filters the catalogue by standard
- THEN each entry shows its standard, its claim level and the evidence behind it
- e2e: `tests/e2e/statutory-gateways-catalogue.spec.ts`

#### Scenario: a gateway without a claim is refused at registration
- GIVEN a gateway entry with no `standard`
- WHEN the adapter metadata registry loads
- THEN registration fails naming the entry and the missing field
- @e2e exclude registry validation; covered by PHPUnit on the adapter metadata registry

#### Scenario: a claim is not a certificate
- GIVEN a `conformant` claim on the Archiefregeling
- WHEN the entry is rendered
- THEN the wording states that it is a claim and names its evidence, and no certification mark is shown
- e2e: `tests/e2e/statutory-gateways-catalogue.spec.ts`

### Requirement: The Digikoppeling broker is chosen per instance (REQ-SG-002)

The Digikoppeling adapter MUST take its broker from configuration, with at
least two supported brokers selectable, and MUST resolve PKIoverheid keys
through the chosen broker as REQ-DK-005 already requires. Changing the broker
MUST NOT require a code change or a release. An unconfigured broker MUST fail
before any call is attempted, naming the missing configuration.

#### Scenario: an instance moves from one broker to another
- GIVEN a WUS profile configured against broker A
- WHEN an administrator selects broker B and saves
- THEN subsequent calls run over broker B and the previous configuration is kept in the audit trail
- @e2e exclude broker dispatch needs two broker endpoints; covered by PHPUnit with two configured brokers

#### Scenario: no broker fails before the call
- GIVEN a Digikoppeling gateway with no broker configured
- WHEN a message is sent
- THEN the send fails naming the missing broker configuration and no HTTP call is made
- @e2e exclude covered by PHPUnit on the adapter

### Requirement: CORV and GGK ship as sector gateways (REQ-SG-003)

Integriq MUST offer a CORV adapter for the justice route and a GGK adapter
for the social domain, each as a catalogue entry under REQ-SG-001, each
carrying its own message schemas and its own delivery guarantees, and each
routing through the existing delivery machinery rather than its own client.

#### Scenario: a jeugdbescherming message leaves over CORV
- GIVEN a configured CORV gateway and a message that validates against its schema
- WHEN the message is sent
- THEN it leaves over the configured route and the attempt is recorded with its outcome
- @e2e exclude the CORV endpoint is a statutory route with no test instance; covered by PHPUnit against a mock-mode fixture

#### Scenario: an invalid message never leaves
- GIVEN a GGK message missing a required element
- WHEN it is sent
- THEN validation refuses it, the failure names the element, and nothing is transmitted
- @e2e exclude covered by PHPUnit on the adapter

### Requirement: An electronic route declares the Wmebv obligations it meets (REQ-SG-004)

Every inbound electronic route MUST declare which Wmebv obligations it meets
itself and which it hands to its consumer, as a list on the gateway entry.
Each received message MUST record which route it arrived on and which
obligations that route met at the moment of receipt. An obligation handed to
the consumer MUST be named, so a consumer can be asked for it.

#### Scenario: a route states what it meets and what it hands on
- GIVEN the Berichtenbox route declaring the obligations it meets and handing the confirmation of receipt to its consumer
- WHEN an administrator reads the gateway entry
- THEN both lists are shown and the handed obligation names its consumer duty
- e2e: `tests/e2e/statutory-gateways-catalogue.spec.ts`

#### Scenario: a received message carries its route and its obligations
- GIVEN a message received on a declared route
- WHEN the receipt is recorded
- THEN the record carries the route id and the obligations that route met
- @e2e exclude inbound receipt recording; covered by PHPUnit on the route

### Requirement: Official publication is a gateway and the document is published by reference (REQ-SG-005)

Integriq MUST offer a publication gateway for the Wet elektronisch
publiceren that takes a reference to a document held elsewhere and a
publication instruction, and MUST NOT require the document to be copied into
integriq. The gateway MUST record the publication identifier the platform
returns and MUST make a failed publication visible as a failed delivery.

#### Scenario: a decision is published from its existing location
- GIVEN a document held by its owning app and a publication instruction
- WHEN the gateway publishes
- THEN the platform's publication identifier is recorded against the instruction and no copy of the document is stored in integriq
- @e2e exclude the publication platform has no test instance; covered by PHPUnit against a mock-mode fixture

#### Scenario: a refused publication is a failed delivery
- GIVEN the platform refuses a publication
- WHEN the attempt finishes
- THEN it is recorded as a failed delivery with the platform's reason and is replayable
- @e2e exclude covered by PHPUnit on the gateway

### Requirement: The ZGW registry is a deployment binding (REQ-SG-006)

The registry integriq reads and writes ZGW resources against MUST be a named
binding in configuration, resolving either to OpenRegister or to an external
ZGW registry reachable over the standard APIs. Switching the binding MUST
NOT change the shape any consumer sees. A binding pointing at an
unreachable registry MUST fail at configuration test time, not at first use.

#### Scenario: an instance runs on another vendor's registry
- GIVEN a binding configured against an external Zaken API
- WHEN a consumer reads a case through integriq
- THEN the answer has the same shape it has on OpenRegister, and the consumer is unchanged
- @e2e exclude a second ZGW registry cannot be staged on CI; covered by PHPUnit against a mock-mode registry

#### Scenario: an unreachable binding fails at test time
- GIVEN a binding with a wrong base URL
- WHEN the administrator tests the configuration
- THEN the test fails naming the unreachable endpoint, and the binding is not marked usable
- e2e: `tests/e2e/statutory-gateways-registry-binding.spec.ts`

### Requirement: An on-premise bridge reaches a system behind the firewall (REQ-SG-007)

Integriq MUST support a bridge component that opens an outbound connection
from inside the customer's network and offers it as a transport a gateway can
select. A bridge MUST authenticate itself, MUST be revocable from the
administration surface, and MUST appear in the call log like any other
transport. Integriq MUST NOT require an inbound firewall opening.

#### Scenario: a call reaches a system with no public endpoint
- GIVEN a registered bridge and a gateway configured to use it
- WHEN a call is made to a system with no public endpoint
- THEN the call travels over the bridge and is recorded with the bridge as its transport
- @e2e exclude the bridge needs a second network; covered by PHPUnit with a loopback bridge

#### Scenario: a revoked bridge stops answering
- GIVEN a bridge an administrator revokes
- WHEN a gateway configured to use it makes a call
- THEN the call fails naming the revoked bridge, and no traffic passes
- e2e: `tests/e2e/statutory-gateways-bridge.spec.ts`

### Requirement: A gateway declares where its endpoint sits (REQ-SG-008)

Every gateway entry MUST declare the jurisdiction of the endpoint it talks
to. The administration surface MUST list every configured gateway with its
jurisdiction, so an instance can answer where data leaves to without reading
configuration files. An undeclared jurisdiction MUST render as `unknown` and
MUST NOT render as a default.

#### Scenario: an administrator reads where data goes
- GIVEN four configured gateways with declared jurisdictions
- WHEN the administrator opens the gateway overview
- THEN each row shows its jurisdiction and the list can be exported
- e2e: `tests/e2e/statutory-gateways-catalogue.spec.ts`

#### Scenario: an undeclared jurisdiction says unknown
- GIVEN a gateway entry with no jurisdiction declared
- WHEN the overview renders
- THEN the row reads `unknown` and is not silently shown as local
- @e2e exclude rendering of a declared-absent value; covered by PHPUnit on the overview service

### Requirement: A WKPB restriction is registered through a gateway (REQ-SG-009)

Integriq MUST offer a WKPB gateway that registers a public-law restriction
against a property and records the identifier the register returns. The
gateway MUST refuse a registration whose property reference does not resolve,
and MUST make a refused registration visible as a failed delivery.

#### Scenario: a restriction is registered and its identifier comes back
- GIVEN a case declaring a WKPB restriction and a resolvable property reference
- WHEN the registration is sent
- THEN the returned identifier is recorded against the case's instruction
- @e2e exclude the WKPB register has no test instance; covered by PHPUnit against a mock-mode fixture

#### Scenario: an unresolvable property is refused before sending
- GIVEN a property reference that does not resolve
- WHEN the registration is attempted
- THEN it is refused naming the reference, and nothing is transmitted
- @e2e exclude covered by PHPUnit on the gateway

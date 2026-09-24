# Tasks: statutory-gateways-and-frameworks

Kind: code. Size L. Round 4 discovery cluster 56, candidates C-integrations-27,
28, 29, 37, 47, 3, 4, 30, 36, 39, 46 and 23, rows 12.9 and 12.17. Decisions
D21 and D6. Waits on nothing.

## Implementation tasks

### Task 1: The gateway catalogue entry carries its law
- **spec_ref**: `openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-a-gateway-declares-its-standard-and-its-conformance-claim-req-sg-001`
- **files**: `lib/Gateway/GatewayDescriptor.php`, `lib/Gateway/GatewayRegistry.php`, `lib/Gateway/GatewayCatalogue.php`, `lib/Controller/GatewaysController.php`
- [x] Implement (`standard`, `claimLevel`, `claimEvidence`, a standard facet, and the claim wording carried in the data so no screen has to remember it)
- [x] Test (an entry with no standard, with no evidence, or with an unknown level fails registration naming itself)
- [ ] The catalogue page's standard facet in the UI, which reads `GET /api/gateways?standard=`.

### Task 2: The Digikoppeling broker becomes configuration
- **spec_ref**: `openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-the-digikoppeling-broker-is-chosen-per-instance-req-sg-002`
- **files**: `lib/Gateway/DigikoppelingBrokerResolver.php`
- [x] Implement (any number of selectable brokers from configuration, the previous choice kept in an audit trail, and a failure before the call when the broker is missing or unconfigured)
- [x] Test (two configured brokers, a move between them, an unconfigured one, and a selected broker nobody configured)

### Task 3: CORV and GGK adapters
- **spec_ref**: `openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-corv-and-ggk-ship-as-sector-gateways-req-sg-003`
- **files**: `lib/Gateway/Adapter/CorvGateway.php`, `lib/Gateway/Adapter/GgkGateway.php`, `lib/Gateway/Adapter/MessageGateway.php`, `lib/Gateway/SourceGatewayTransport.php`
- [x] Implement (message schemas, catalogue entries, delivery over the shared transport rather than a client per adapter)
- [x] Test (a valid send, an invalid message refused before transmission, and a refusal from the other side recorded as a replayable failed delivery)
- [ ] Seeded mock-mode sources per `source-management`, once the statutory endpoints are known.

### Task 4: Wmebv obligations declared per route
- **spec_ref**: `openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-an-electronic-route-declares-the-wmebv-obligations-it-meets-req-sg-004`
- **files**: `lib/Gateway/GatewayDescriptor.php`, `lib/Gateway/InboundRouteReceipt.php`
- [x] Implement (two lists per route, each handed obligation naming its consumer duty, and the route id with its met obligations recorded at the moment of receipt)
- [x] Test

### Task 5: Publication by reference
- **spec_ref**: `openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-official-publication-is-a-gateway-and-the-document-is-published-by-reference-req-sg-005`
- **files**: `lib/Gateway/Adapter/PublicationGateway.php`, `lib/Gateway/GatewayDelivery.php`
- [x] Implement (reference plus instruction, an inline document refused, the returned identifier recorded, a refusal visible as a failed delivery)
- [x] Test (a refused publication is replayable)

### Task 6: The ZGW registry binding
- **spec_ref**: `openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-the-zgw-registry-is-a-deployment-binding-req-sg-006`
- **files**: `lib/Gateway/ZgwRegistryBinding.php`, `lib/Controller/GatewaysController.php`
- [x] Implement (a named binding resolving to OpenRegister or an external Zaken API, and a reachability test that marks the binding usable only when it answers)
- [x] Test (a reachable external registry, one that asks for credentials, a wrong base URL, and an empty one)
- [ ] Routing the ZGW reads and writes through the binding. The binding and its test are here; the call sites move once the external adapter lands.

### Task 7: The on-premise bridge
- **spec_ref**: `openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-an-on-premise-bridge-reaches-a-system-behind-the-firewall-req-sg-007`
- **files**: `lib/Bridge/BridgeRegistry.php`, `lib/Bridge/BridgeTransport.php`
- [x] Implement (a bridge that authenticates with a token stored hashed, revocation from the administration surface, and the bridge named as the transport in the call log)
- [x] Test (a loopback bridge, a revoked one, a gateway naming no bridge, and a token that no longer authenticates)

### Task 8: Jurisdiction on every gateway
- **spec_ref**: `openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-a-gateway-declares-where-its-endpoint-sits-req-sg-008`
- **files**: `lib/Gateway/GatewayRegistry.php`, `lib/Controller/GatewaysController.php`
- [x] Implement (declared jurisdiction, an overview with a comma-separated export, and `unknown` beside `jurisdictionDeclared: false` so an absent value can never read as a checked one)
- [x] Test
- [ ] The overview screen itself, which reads `GET /api/gateways/overview`.

### Task 9: The WKPB gateway
- **spec_ref**: `openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-a-wkpb-restriction-is-registered-through-a-gateway-req-sg-009`
- **files**: `lib/Gateway/Adapter/WkpbGateway.php`
- [x] Implement (registration, the returned identifier recorded, and an unresolvable property reference refused before sending, checked through the `bag` property source rather than a second lookup)
- [x] Test

### Task 10: Coordination, docs and the hand-offs
- **files**: `docs/`, Dutch and English strings, the catalogue entries, this change's row in `competitor-parity-2026-09`
- [ ] Hand dossiq the case-type half: the WKPB flag and the registry binding declaration, and say that C-integrations-3 stays with dossiq's term model, cluster 18
- [ ] Tell the opencatalogi lane that the Wet elektronisch publiceren gateway sits under cluster 50, and agree the instruction shape
- [ ] Record C-integrations-23 as already answered, so it is not rediscovered
- [x] Test (`tests/e2e/statutory-gateways-catalogue.spec.ts`, `tests/e2e/statutory-gateways-registry-binding.spec.ts`, `tests/e2e/statutory-gateways-bridge.spec.ts`, `openspec validate statutory-gateways-and-frameworks --strict`)

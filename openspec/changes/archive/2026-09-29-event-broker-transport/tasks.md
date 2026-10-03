# Tasks: event-broker-transport

Kind: code. Size M. Parity ledger row 12.14. The CloudEvents engine, the
subscription matcher, the retry policy, the dead-letter and the replay all
shipped already; nothing below rebuilds any of them. What is new is a transport
seam and the three brokers behind it.

## Implementation tasks

### Task 1: The transport contract and its registry
- **spec_ref**: `openspec/changes/event-broker-transport/specs/events-cloudevents/spec.md#requirement-a-subscription-s-action-dispatch-must-support-a-broker-kind-req-013`
- **files**: `lib/Broker/BrokerTransportInterface.php`, `lib/Broker/BrokerPublication.php`,
  `lib/Broker/BrokerResult.php`, `lib/Broker/BrokerTransportRegistry.php`
- [x] Implement (three result states, first-wins registration, a refused
      collision logged)
- [x] Test (a taken id refused and the first transport kept, an unknown id
      refused by name)

### Task 2: The CloudEvents HTTP binding, both content modes
- **spec_ref**: `openspec/changes/event-broker-transport/specs/events-cloudevents/spec.md#requirement-cloudevents-travel-in-structured-or-binary-content-mode-req-015`
- **files**: `lib/Broker/CloudEventHttpBinding.php`
- [x] Implement (structured and binary, `ce-` headers, non-scalar attributes
      left out)
- [x] Test (both modes, the data content type in binary mode, an array
      extension attribute absent from the headers)

### Task 3: The three transports and the dormant default
- **spec_ref**: `openspec/changes/event-broker-transport/specs/events-cloudevents/spec.md#requirement-a-broker-that-accepted-a-message-it-delivered-to-nobody-is-a-failure-req-014`
- **files**: `lib/Broker/Transport/RabbitMqHttpTransport.php`,
  `lib/Broker/Transport/KafkaRestTransport.php`,
  `lib/Broker/Transport/CloudEventsHttpTransport.php`,
  `lib/Broker/Transport/LogBrokerTransport.php`
- [x] Implement (each reads its broker's own answer, not the HTTP status alone)
- [x] Test (`routed: false` under a 200 is a refusal, a Kafka `error_code`
      under a 200 is a refusal, a routed publish is a send, the dormant
      transport never reports a send)

### Task 4: The `broker` action kind
- **spec_ref**: `openspec/changes/event-broker-transport/specs/events-cloudevents/spec.md#requirement-a-subscription-s-action-dispatch-must-support-a-broker-kind-req-013`
- **files**: `lib/Service/EventService.php`, `lib/AppInfo/Application.php`,
  `lib/Settings/integriq_register.json`, `lib/Settings/integriq_mock_register.json`
- [x] Implement (dispatch through the registry, REQ-002 bookkeeping, an
      unknown `brokerId` recorded as a configuration error)
- [x] Test (a delivered publish, an unknown broker id that does not retry, a
      refused publish that does)

### Task 5: Say what an administrator configures
- **spec_ref**: `openspec/changes/event-broker-transport/specs/events-cloudevents/spec.md#requirement-an-unconfigured-broker-refuses-rather-than-reporting-success-req-016`
- **files**: the broker documentation page
- [x] Implement (what each broker needs, which credentials, and the two
      answers that look like success and are not)
- [x] Test (walked against the documented broker behaviour and each
      transport's own tests, NOT against a live broker in this lane; the page
      says so rather than implying a walk that did not happen)

# events-cloudevents

## ADDED Requirements

### Requirement: Outside systems SHALL send CloudEvents to a receiver that checks and routes them (REQ-017)

Integriq SHALL offer a receiver URL per configured source that accepts CloudEvents 1.0 in
structured and binary content mode. A request SHALL carry a valid webhook signature or credential
for that source. The receiver SHALL check that `specversion`, `id`, `source` and `type` are present,
refuse an event whose `id` it already received from the same source, store the event as an `event`
object and pass it to `EventService::processEvent()`, so every matching subscription acts on it as
on an event integriq produced itself.

Rows: `evt-receive` (integriq matrix).

#### Scenario: A valid event reaches the matching subscription
- **GIVEN** a source with a receiver URL and a subscription on type `nl.example.order.created`
- **WHEN** that source POSTs a structured CloudEvent of that type with a valid signature
- **THEN** the receiver answers 202, the event is stored, and the subscription's action runs with its data
- @e2e exclude needs an outside system that signs requests with a configured source connection; covered by PHPUnit (CloudEventReceiverControllerTest, CloudEventReceiverTest)

#### Scenario: A binary-mode event is read from its headers
- **WHEN** a source POSTs the event data with `ce-specversion`, `ce-id`, `ce-source` and `ce-type` headers
- **THEN** the receiver stores the same event as it would for the structured form
- @e2e exclude needs an outside system sending signed binary-mode requests; covered by PHPUnit (CloudEventHttpBindingDecodeTest, CloudEventReceiverControllerTest)

#### Scenario: An envelope without a type is refused
- **WHEN** a source POSTs a CloudEvent without `type`
- **THEN** the receiver answers 400 naming the missing field and stores nothing
- @e2e exclude needs a signed request from a configured source; covered by PHPUnit (CloudEventHttpBindingDecodeTest, CloudEventReceiverControllerTest)

#### Scenario: A repeated event is not processed twice
- **GIVEN** an event with `id` `a1` from a source was received
- **WHEN** the same source sends `a1` again
- **THEN** the receiver answers 200 without running any subscription again
- @e2e exclude needs a signed request from a configured source; covered by PHPUnit (CloudEventReceiverTest, CloudEventReceiverControllerTest)

#### Scenario: An unsigned request is refused
- **WHEN** a request reaches the receiver without a valid signature or credential for its source
- **THEN** the receiver answers 401 and stores nothing
- @e2e exclude the refusal comes from the shared webhook gate, which needs a configured source connection; covered by PHPUnit (CloudEventReceiverControllerTest)

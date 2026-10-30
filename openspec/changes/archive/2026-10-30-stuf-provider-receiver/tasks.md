# Tasks: stuf-provider-receiver

- [x] 1. Red tests for the receiver: authentication, each message type, refusal, unanswered fault.
- [x] 2. Typed events with an answer slot (`StufProviderQueryEvent` and four subclasses).
- [x] 3. `StufProviderReceiver` builds the event, dispatches it and renders the answer.
- [x] 4. `StufProviderController` with the two public routes.
- [x] 5. `StufProviderRequestReceivedEvent` and its listener for sibling-app forwarders.
- [x] 6. `StUFXMLBuilder` gains `buildFo02`; an accepted write is answered with the existing `buildBv03` and a refused kennisgeving with `buildFo03` (`stuf-provider-writes`).

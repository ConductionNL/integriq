# outbound-call-log (delta)

@e2e exclude Call engine write path with no browser surface of its own; covered by PHPUnit
(CallServiceWithheldBodiesTest, BrpRelationReadCallLogTest, RegistrySourceGatewayTest).

## ADDED Requirements

### Requirement: A caller can have a call recorded without any body

The call engine SHALL accept a per-call option, `withholdBodies`, set by the calling code. A call
made with it SHALL be written to the call log as one record that names the source, the direction,
the status, the moment, and the Nextcloud user in whose session the call ran when there is one.
That record SHALL keep of the request only the method and the URL without query string or
fragment, and of the response only the status code, status message, response time, size and
encoding. It SHALL NOT hold a request body, a response body, request or response headers, query
parameters, a replay request or a body expiry, and `bodyCaptured` SHALL be false. The option SHALL
win over an open investigation window on the source and over `logBody` on the same call. No app
setting, source field or request configuration key SHALL switch the option off. A buffered record
and the execution trace step of the call SHALL follow the same rule. The caller SHALL still
receive the full response.

#### Scenario: A call inside an investigation window is recorded without bodies

- **GIVEN** a source with an open investigation window
- **WHEN** a call is made with `withholdBodies`
- **THEN** one call log record SHALL be written with the source, the status and the asking user
- **AND** the stored record, read as one string, SHALL hold no part of the request body or the response body

#### Scenario: A failed call keeps no replay request

- **WHEN** a call made with `withholdBodies` is answered with an error status
- **THEN** the record SHALL hold no replay request and no body expiry

#### Scenario: Another option cannot put the bodies back

- **WHEN** a call is made with `withholdBodies` and with `logBody`
- **THEN** the record SHALL hold no body

#### Scenario: A call without a user

- **WHEN** a call is made with `withholdBodies` outside a user session
- **THEN** the record SHALL be written without a user

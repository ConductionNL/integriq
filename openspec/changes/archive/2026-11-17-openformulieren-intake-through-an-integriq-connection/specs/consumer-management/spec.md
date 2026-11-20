## ADDED Requirements

### Requirement: A consumer can authenticate by Open Formulieren webhook signature (REQ-CON-OF-001)

A `consumer` with `authorizationType` `open-formulieren` MUST authenticate a call by verifying the signature header named in its `authorizationConfiguration` over the raw request body, with the scheme, secret and tolerance stored there. Its `userId` MUST be the Nextcloud account the call acts as. An instance MUST hold at most one `open-formulieren` consumer. One `dso-stam` and one `open-formulieren` consumer MAY exist side by side.

#### Scenario: A valid Open Formulieren signature resolves to the consumer's account

- GIVEN an `open-formulieren` consumer with secret S and `userId` `of-intake`
- WHEN a call arrives with a valid timestamped HMAC signature over its raw body under S
- THEN authentication succeeds and resolves to the user `of-intake`
- @e2e exclude backend signature verification: covered by PHPUnit

#### Scenario: A second open-formulieren consumer is refused

- GIVEN an `open-formulieren` consumer exists
- WHEN an administrator saves another consumer with `authorizationType` `open-formulieren`
- THEN the save is refused with an error saying only one Open Formulieren connection is allowed
- @e2e exclude backend save guard: covered by PHPUnit

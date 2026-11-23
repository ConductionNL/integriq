## ADDED Requirements

### Requirement: A consumer can authenticate by DSO STAM signature (REQ-CON-DSO-001)

A `consumer` with `authorizationType` `dso-stam` MUST authenticate a call by verifying the `X-DSO-Signature` header over the raw request body against its `authorizationConfiguration`: an HMAC shared secret when `mode` is `hmac`, a PKIoverheid certificate chain when `mode` is `pkioverheid`. Its `userId` MUST be the Nextcloud account the call acts as. An instance MUST hold at most one `dso-stam` consumer. Its `authorizationConfiguration` MUST stay write-only, as every consumer's does.

@e2e exclude backend signature verification and identity resolution: covered by PHPUnit, no browser surface

#### Scenario: A valid signature resolves to the consumer's account
- **GIVEN** a `dso-stam` consumer in `hmac` mode with secret S and `userId` `dso-intake`
- **WHEN** a call arrives with a valid HMAC-SHA256 signature over its raw body under S
- **THEN** authentication succeeds and resolves to the user `dso-intake`

#### Scenario: A second dso-stam consumer is refused
- **GIVEN** a `dso-stam` consumer exists
- **WHEN** an administrator saves another consumer with `authorizationType` `dso-stam`
- **THEN** the save is refused with an error saying only one DSO connection is allowed

#### Scenario: The trust configuration is never returned
- **GIVEN** a `dso-stam` consumer with an HMAC secret
- **WHEN** any API reads the consumer
- **THEN** the response carries no `authorizationConfiguration` secret

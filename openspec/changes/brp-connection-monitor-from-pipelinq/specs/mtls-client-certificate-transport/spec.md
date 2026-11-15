## ADDED Requirements

### Requirement: mTLS material may be a keepiq reference (REQ-005)

Each part of `configuration.authentication.mtls` (certificate, private key, passphrase, CA bundle) MAY be a `{credentialRef: {credentialId}}` to a keepiq secret instead of inline encrypted material. `MtlsConfigResolver` SHALL resolve a reference through the OpenRegister credential broker with the source's organisation asserted, and SHALL then apply the same validation, transient materialisation and fail-closed rules as for inline material. Inline material SHALL keep working.

#### Scenario: A referenced certificate resolves at call time

@e2e exclude backend credential resolution; covered by MtlsConfigResolverTest.

- **GIVEN** a source whose certificate and key are keepiq references
- **WHEN** a call is made
- **THEN** the resolver SHALL fetch both through the broker, validate them and build the TLS options

#### Scenario: A reference that does not resolve fails closed

@e2e exclude backend credential resolution; covered by MtlsConfigResolverTest.

- **GIVEN** a source whose certificate reference points at a deleted keepiq secret
- **WHEN** a call is attempted
- **THEN** `MtlsConfigurationException` SHALL be raised and no request SHALL be sent

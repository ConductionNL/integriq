# stuf-adapter — Traceability + Auth Coverage Delta

**Spec refs**: `stuf-adapter`, ADR-020 (gate scope), ADR-008 (testing)

## ADDED Requirements

### Requirement: Scenario-Level Test Traceability

Every `#### Scenario:` in this capability MUST carry either an `@e2e` reference to a
browser test, or a reason-bearing `@e2e exclude <reason>` line, so gate-19 can trace
spec coverage without inventing a browser test for a backend-only SOAP/XML adapter.

#### Scenario: Backend-only scenario carries an exclude reason

- GIVEN a scenario describes SOAP/XML wire behavior with no Vue UI surface
- WHEN the scenario is reviewed for e2e traceability
- THEN it MUST carry `@e2e exclude backend StUF-BG/StUF-ZKN integration — covered by PHPUnit, not browser UI`

## MODIFIED Requirements

### Requirement: PKIoverheid mTLS Authentication (REQ-STUF-011)

The adapter MUST support certificate-based mutual TLS authentication for StUF endpoints.
This leverages the existing CallService certificate handling: `getCertificate()` writes
client certificates and SSL keys to temporary files, the SOAP/HTTP request uses them for
mTLS, and `removeFiles()` cleans up after the request. **This behavior MUST be proven by
PHPUnit tests** — a security-relevant authentication path MUST NOT ship with zero test
coverage.

#### Scenario: Client certificate used for mTLS request

- **WHEN** a StUF source is configured with a PKIoverheid client certificate and private
  key and the adapter makes a SOAP request
- **THEN** CallService writes the certificate to a temporary file, passes it to the
  Guzzle/SOAPService client for mTLS, and removes the file after the response
- **AND** a PHPUnit test asserts the temp-file write/passthrough/cleanup sequence

#### Scenario: Escaped newlines in PEM converted correctly

- **WHEN** the PKIoverheid certificate is stored as a PEM string in the Source
  configuration containing escaped newlines (`\n`) and CallService writes the certificate
- **THEN** escaped newlines are converted to actual newlines (existing `writeFile()`
  behavior) ensuring the certificate is valid
- **AND** a PHPUnit test asserts the converted PEM content byte-for-byte

#### Scenario: Expired certificate fails with diagnostic

- **WHEN** the certificate has expired and the adapter attempts a connection
- **THEN** the mTLS handshake fails, a descriptive error is logged in CallLog, and the
  Source status is updated to indicate certificate expiry
- **AND** `@e2e exclude backend StUF-BG/StUF-ZKN integration — covered by PHPUnit, not browser UI`

### Requirement: WS-Security UsernameToken Authentication (REQ-STUF-012)

The adapter MUST support WS-Security UsernameToken authentication for StUF endpoints.
This adds a SOAP header with username and password (optionally with nonce and timestamp)
to outbound SOAP requests. The authentication method is configured as a new auth type in
AuthenticationService. **This behavior MUST be proven by PHPUnit tests**, including an
exact assertion of the `PasswordDigest` hash formula (not merely that a header exists).

#### Scenario: UsernameToken header added to SOAP request

- **WHEN** a StUF source is configured with WS-Security authentication (username +
  password) and the adapter sends a SOAP request
- **THEN** the SOAP envelope includes a `wsse:Security` header with `wsse:UsernameToken`,
  `wsse:Username`, and `wsse:Password` elements
- **AND** a PHPUnit test asserts the header structure and element values

#### Scenario: PasswordDigest hashing applied

- **WHEN** WS-Security with PasswordDigest is configured and the adapter builds the
  security header
- **THEN** the password is hashed as `Base64(SHA1(Nonce + Created + Password))` per the
  WS-Security UsernameToken 1.0 profile
- **AND** a PHPUnit test asserts the computed digest against a hand-computed fixture
  value (not just presence of a non-empty string)

#### Scenario: PasswordText included as plaintext

- **WHEN** WS-Security with PasswordText is configured and the adapter builds the
  security header
- **THEN** the password is included as plaintext in the UsernameToken (suitable only
  over TLS)
- **AND** a PHPUnit test asserts the plaintext value is present unmodified

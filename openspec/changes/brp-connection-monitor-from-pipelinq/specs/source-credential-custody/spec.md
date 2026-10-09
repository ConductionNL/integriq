## ADDED Requirements

### Requirement: A connection handed over by another app keeps its secrets in keepiq

When another app hands integriq the settings of a connection it used to run itself (first: pipelinq's BRP connection), integriq SHALL fill only the fields of the matching source that are empty, SHALL mint a keepiq secret through the credential broker for every secret part (client secret, certificate, key, CA bundle), SHALL verify each resolves byte for byte before writing the reference, and SHALL answer per part whether it was taken, already set or failed. It SHALL never overwrite a value an administrator set, and SHALL never log or return a secret.

#### Scenario: pipelinq's BRP settings arrive in an empty source

@e2e exclude an event between apps with no browser surface; covered by the handover listener test.

- **GIVEN** an empty `brp-haalcentraal` source
- **WHEN** pipelinq hands over a base URL, client id, client secret, certificate and key
- **THEN** the source SHALL hold the base URL and client id and four keepiq references
- **AND** the answer SHALL report every part as taken

#### Scenario: A value already set is kept

@e2e exclude an event between apps with no browser surface; covered by the handover listener test.

- **GIVEN** a source with a base URL already set
- **WHEN** pipelinq hands over a different base URL
- **THEN** the source SHALL keep its base URL and the answer SHALL report the conflict

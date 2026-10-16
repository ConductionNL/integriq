## ADDED Requirements

### Requirement: A source shows how it performed in the last 24 hours (REQ-CRUN-006)

The source page and the connection monitor SHALL show, for the last 24 hours and from integriq's own call log: the number of calls, the number of errors grouped by kind (time-out, client error, server error, TLS), the average and the slowest response time, and the moment the figures were made.

#### Scenario: The BRP source shows its calls and errors

- **GIVEN** the `brp-haalcentraal` source made 1,284 calls in the last 24 hours, 3 of them timed out
- **WHEN** an administrator opens its connection monitor
- **THEN** the page SHALL show 1,284 calls, 3 errors marked as time-outs and the average response time

### Requirement: A source shows when its certificate expires (REQ-CRUN-007)

When a source's mTLS certificate is a keepiq reference, the source page and the connection monitor SHALL show the certificate's expiry date and the days left, as answered by keepiq, with a link to that secret in keepiq to renew it. integriq SHALL NOT show certificate material. When keepiq does not answer, the page SHALL say the expiry is unknown and why.

#### Scenario: A certificate that expires soon links to keepiq

- **GIVEN** the BRP source's certificate is a keepiq secret expiring in 54 days
- **WHEN** an administrator opens the connection monitor
- **THEN** the page SHALL show the expiry date, 54 days left and a warning
- **AND** a link SHALL open that secret in keepiq

#### Scenario: Without an answer from keepiq the expiry is unknown

@e2e exclude needs an instance without keepiq; covered by ConnectionCertificateExpiryTest.

- **GIVEN** keepiq is not installed
- **WHEN** an administrator opens the connection monitor
- **THEN** the certificate block SHALL say the expiry is unknown because keepiq did not answer

### Requirement: An expiring certificate opens an alert (REQ-CRUN-008)

The hourly connection health job SHALL open one alert per source and threshold when a referenced certificate comes within 30, 14 or 7 days of expiry (configurable per source), and SHALL notify the group named for the source. It SHALL NOT open the same alert twice.

#### Scenario: Thirty days before expiry the named group is told once

@e2e exclude a background job; covered by PHPUnit on the connection health job.

- **GIVEN** a source whose certificate expires in 29 days and a notify group
- **WHEN** the health job runs twice
- **THEN** the group SHALL receive one notification for the 30-day threshold

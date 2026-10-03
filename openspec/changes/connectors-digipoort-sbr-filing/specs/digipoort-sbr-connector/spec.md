# digipoort-sbr-connector Specification

## ADDED Requirements

### Requirement: A Digipoort source delivers through a log or WUS provider (REQ-DPS-001)

Integriq MUST offer a `digipoort-sbr` source type with a `log` provider and a `wus` provider. The `wus` provider MUST sign every request with WS-Security using the PKIoverheid certificate the credential broker holds for the source, MUST send it over two-way TLS, and MUST reject an answer whose signature does not verify. Without resolvable certificate material it MUST fail closed with a reason and send nothing. The `log` provider MUST answer without a network call.

#### Scenario: a filing on a new instance
- GIVEN the seeded `digipoort-sbr` source on the `log` provider
- WHEN a VAT return instance is delivered
- THEN a deterministic delivery reference is returned and no call leaves the instance
- @e2e exclude backend provider; covered by PHPUnit

#### Scenario: the certificate is missing
- GIVEN a `wus` source whose certificate reference the broker cannot resolve
- WHEN an instance is delivered
- THEN the delivery fails with a reason naming the certificate, and the filing is not marked delivered
- @e2e exclude backend provider; covered by PHPUnit

### Requirement: A filing request becomes one delivery (REQ-DPS-002)

Integriq MUST consume `nl.conduction.sbr.filing.requested` objects created in its own `event` schema, carrying `sourceApp`, `objectType`, `objectUri`, `filingType`, `recipient`, `payloadFileUri` and the filer's identity number and type. It MUST store one `sbr_filing` per `objectUri` and `filingType`, MUST read the XBRL instance from `payloadFileUri`, and MUST deliver it once through the active source. A repeated request MUST NOT deliver again.

#### Scenario: shillinq submits a VAT return
- GIVEN shillinq creates a request for the OB aangifte 2026 Q3 of Adviesbureau Kade B.V. with the instance stored in Nextcloud Files
- WHEN integriq consumes it
- THEN one `sbr_filing` exists with the delivery reference, and the instance was delivered once
- @e2e exclude backend consumer; covered by PHPUnit

#### Scenario: the same request arrives twice
- GIVEN a filing already delivered for that return
- WHEN the request is created again
- THEN no second delivery is made
- @e2e exclude backend consumer; covered by PHPUnit

### Requirement: Every status change is reported back (REQ-DPS-003)

Integriq MUST poll the processing status of every filing that has not reached a final status, MUST append each new status to the filing's history, and MUST emit one `nl.conduction.sbr.filing.status` event per change with `objectUri`, `deliveryReference`, `status` (`delivered`, `accepted`, `rejected` or `failed`) and `errors`, where a rejection carries the recipient's error codes and texts. A delivery that keeps failing MUST follow the retry budget and then the dead-letter surface.

#### Scenario: the Belastingdienst rejects a return
- GIVEN a delivered VAT return
- WHEN the status service reports a rejection with an error code
- THEN a status event with `rejected` and that code is emitted, and the filing stops being polled
- @e2e exclude scheduled poll; covered by PHPUnit with a scripted provider

### Requirement: An administrator sees every filing and its status (REQ-DPS-004)

Integriq MUST list the Digipoort connector in its catalogue under the category `Tax filing`, and MUST show a filings log page to administrators with each filing's recipient, type, delivery reference, status history and errors.

#### Scenario: an administrator checks a filing
- GIVEN the seeded filing for Adviesbureau Kade B.V.
- WHEN an administrator opens the Digipoort filings page
- THEN the filing shows the statuses delivered and accepted with their times
- e2e: `tests/e2e/digipoort-filings.spec.ts`

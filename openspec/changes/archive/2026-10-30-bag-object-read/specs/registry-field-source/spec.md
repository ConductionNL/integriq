# registry-field-source (delta)

@e2e exclude Outbound registry read with no browser surface; covered by PHPUnit
(BagObjectReaderTest, PdokWfsClientHttpTest).

## ADDED Requirements

### Requirement: A BAG object is read by its identificatie, and an unanswered read is not a missing object

The app SHALL offer one read of a BAG `pand`, `verblijfsobject` or `nummeraanduiding` by its
16-digit identificatie. It SHALL answer whether the question was answered, the reason when it was
not, whether the BAG holds the object, whether the answer came from a mock client, and the object
when found. A question PDOK did not answer, or answered about another object, SHALL be reported as
not answered and SHALL NOT be reported as an object the BAG does not hold. An identificatie that is
not 16 digits, or whose type code does not match the asked type, SHALL NOT be sent to PDOK.

#### Scenario: An object the BAG holds is found

- **GIVEN** PDOK answers a feature or address whose identificatie is the one asked
- **WHEN** the object is read
- **THEN** the answer SHALL say it was answered and the object was found
- **AND** it SHALL carry the object's attributes

#### Scenario: An object the BAG does not hold is not found

- **GIVEN** PDOK answers with no feature or no address
- **WHEN** the object is read
- **THEN** the answer SHALL say it was answered and the object was not found

#### Scenario: PDOK that does not answer is not an empty register

- **GIVEN** PDOK cannot be reached, or answers something that is not JSON
- **WHEN** the object is read
- **THEN** the answer SHALL say it was not answered, with the reason `unreachable`

#### Scenario: A mock answer says it is one

- **GIVEN** integriq's PDOK clients in their mock flavour
- **WHEN** the object in their canned answer is read
- **THEN** the answer SHALL say it was found and that it is simulated

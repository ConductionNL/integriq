# registry-field-source (delta)

@e2e exclude Outbound registry call with no browser surface; covered by PHPUnit
(BrpRelationReaderTest, BrpRelationReadCallLogTest, RegistryProviderContractTest,
RegistrySourceGatewayTest).

## ADDED Requirements

### Requirement: BRP relations are read only where the source says the municipality may

The app SHALL offer one read of a person's partner, parents and children from the BRP, by
burgerservicenummer. The read SHALL run only when the BRP source carries
`configuration.relationFields` with the boolean value true; in every other case it SHALL NOT call
the registry. It SHALL ask for `partners.burgerservicenummer`, `ouders.burgerservicenummer` and
`kinderen.burgerservicenummer` and for no other field. It SHALL answer whether the question was
answered, the reason when it was not, and each relation as a relation name (`partner`, `parent`,
`child`) with a burgerservicenummer. A question that was not answered SHALL NOT be reported as a
person without relations. The call SHALL be written to the call log as one record without any
body, naming the source, the asking user, the moment and the outcome (decision 229); that record
SHALL hold no burgerservicenummer and no part of the question or the answer. The call SHALL NOT be
written to the property value cache, and no burgerservicenummer and no part of the answer SHALL be
written to the application log. The `brp` property source binding SHALL leave relation fields out of its own question.

#### Scenario: The relations of the seeded person are read

- **GIVEN** the seeded `brp-haalcentraal` source with `configuration.relationFields` true
- **WHEN** the relations of the person in its shipped answer are read
- **THEN** the question SHALL be a RaadpleegMetBurgerservicenummer post to `/personen` with the three relation fields only
- **AND** the answer SHALL hold that person's partner, parents and child, each with a burgerservicenummer

#### Scenario: A source without the setting is not asked

- **GIVEN** a BRP source without `configuration.relationFields`, or with any value other than true
- **WHEN** the relations of a person are read
- **THEN** the registry SHALL NOT be called
- **AND** the answer SHALL say the read is not available, with the reason `relations-not-enabled`

#### Scenario: A registry that does not answer is not an empty family

- **GIVEN** a BRP source with the setting on
- **WHEN** the registry refuses, fails or answers without the person
- **THEN** the answer SHALL say the read is not available
- **AND** it SHALL hold no relations

#### Scenario: The read leaves a record of who asked, without bodies

- **GIVEN** a BRP source with the setting on and an open investigation window
- **WHEN** a user reads the relations of a person, answered or refused
- **THEN** the call log SHALL hold one record naming that user, the source and the status
- **AND** the stored record, read as one string, SHALL hold no burgerservicenummer and no part of the question or the answer

#### Scenario: Nothing personal is logged

- **WHEN** the relations of a person are read, with any outcome
- **THEN** the application log SHALL hold the source and the outcome
- **AND** it SHALL hold no burgerservicenummer and no part of the registry's answer

#### Scenario: A property cannot ask for relations

- **WHEN** a schema property on the `brp` binding declares `partners`, `ouders` or `kinderen` fields
- **THEN** the binding SHALL leave those fields out of its question

# registry-field-source (delta)

@e2e exclude Outbound registry calls with no browser surface; covered by PHPUnit
(RegistryProviderContractTest, RegistrySourceGatewayTest, BrpPropertySourceTest).

## ADDED Requirements

### Requirement: A registry binding asks the way its registry answers

A property source binding SHALL put its question in the form the registry's published API takes,
and SHALL read the record out of the envelope that API returns. The `brp` binding SHALL post a
RaadpleegMetBurgerservicenummer question to `/personen`, with the burgerservicenummer in the body
and never in the URL, and SHALL return the first person of the `personen` list. The `kvk` binding
SHALL ask `/zoeken` by `kvkNummer` and SHALL return the head office row of that number, or the
first row of that number when there is no head office. A binding SHALL be able to read the mock
answer its seeded source ships.

#### Scenario: A person is read from the seeded source's answer

- **GIVEN** the seeded `brp-haalcentraal` source and the answer it ships
- **WHEN** the `brp` binding resolves the burgerservicenummer in that answer
- **THEN** it SHALL post to `/personen` with type RaadpleegMetBurgerservicenummer
- **AND** it SHALL return that person

#### Scenario: A company is read from the seeded source's answer

- **GIVEN** the seeded `kvk` source and the answer it ships
- **WHEN** the `kvk` binding resolves a kvkNummer in that answer
- **THEN** it SHALL ask `/zoeken` with that kvkNummer
- **AND** it SHALL return the head office row

#### Scenario: BRP has no free search

- **WHEN** the `brp` binding is asked to suggest for a text that is not a whole burgerservicenummer
- **THEN** it SHALL suggest nothing
- **AND** it SHALL NOT call the registry

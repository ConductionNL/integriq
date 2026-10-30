# connection-registry Delta: health-probe-skips-switched-off-sources

## ADDED Requirements

### Requirement: The health job skips a switched-off source

The hourly health job SHALL NOT probe a connection whose linked source has `isEnabled` false. A linked source that
cannot be read SHALL still be probed, so its probe reports it missing.

#### Scenario: An example set's made-up source

- **GIVEN** a connection linked to a source with `isEnabled` false
- **WHEN** the health job runs
- **THEN** the source is not called and the connection keeps its status

# outbound-call-log Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- call-log-personal-data-masking

## Purpose

Personal data in a logged message body is masked before the log is written. Row `integriq:obs-redact`.

## ADDED Requirements

### Requirement: Personal data is masked before a body is logged (REQ-PDM-001)

Integriq MUST mask the values of declared personal data fields in every request and response body, header and URL it writes to the call log, to a trace snapshot or to an outbound message record, before the write. The declared list MUST cover at least BSN, name, address, date of birth, e-mail address and telephone number, in JSON and in XML bodies, and an administrator MUST be able to add field names. Masking MUST run in the same pass as the secret masking of REQ-006 and MUST NOT change the message that is sent.

#### Scenario: a StUF call with a BSN is logged without it
- GIVEN an outbound StUF-ZKN call whose body carries `inp.bsn` and `geslachtsnaam`
- WHEN the call log record is written
- THEN both values read `[afgeschermd]` in the stored request
- AND the partner received the real values

#### Scenario: an administrator adds a field
- GIVEN an administrator who adds `kenteken` to the personal data list
- WHEN a call with a `kenteken` field is logged
- THEN its value is masked in the record

# github-publiccode-source Specification

## ADDED Requirements

### Requirement: GitHub is a source template that holds only a credential reference (REQ-GHP-001)

Integriq MUST seed a source `github-api` with location `https://api.github.com`, the headers `Accept: application/vnd.github+json` and `X-GitHub-Api-Version: 2022-11-28`, and authentication by broker reference only (`configuration.authentication.credentialRef`). It MUST ship disabled. Integriq MUST also seed a source `github-raw` with location `https://raw.githubusercontent.com` and no credential. Both MUST appear in the connector catalogue under `Code hosting`. Neither MUST carry a token or any other secret.

#### Scenario: an administrator connects GitHub
- GIVEN a fresh install of integriq
- WHEN the catalogue is listed
- THEN `github-api` and `github-raw` appear under `Code hosting`, `github-api` disabled and naming the credential `github-publiccode`
- @e2e exclude seed data; covered by PHPUnit on the fragment and the catalogue

### Requirement: A step decodes a YAML or base64 response (REQ-GHP-002)

`openconnector.source-call` MUST accept a `decode` option with the values `auto`, `json`, `yaml`, `base64+yaml`, `base64+json` and `text`, and MUST refuse any other value when the flow is saved. `yaml` MUST parse the body as YAML. `base64+yaml` and `base64+json` MUST decode a base64 body, or the base64 `content` field of a JSON object, before parsing. `auto`, the default, MUST keep today's JSON behaviour and MUST parse a body served with a YAML content type as YAML. A synchronization whose source sets `configuration.format: "yaml"`, or whose page arrives with a YAML content type, MUST parse the page as YAML. YAML MUST be parsed without object, constant or custom tags.

#### Scenario: a raw publiccode.yml becomes an object
- GIVEN a source-call step on `github-raw` with `decode: yaml`
- WHEN the response is a valid publiccode.yml version 0.4
- THEN the item's `body` holds `publiccodeYmlVersion` "0.4", the `name` and the `url` from the file
- @e2e exclude backend flow node; covered by PHPUnit with the real fixture

#### Scenario: a contents API answer becomes an object
- GIVEN a source-call step with `decode: base64+yaml`
- WHEN the response is a contents API object whose `content` is a base64 publiccode.yml version 0.2
- THEN the item's `body` holds the parsed file, not the envelope
- @e2e exclude backend flow node; covered by PHPUnit with a recorded answer

#### Scenario: a PHP object tag is refused
- GIVEN a YAML body carrying `!php/object`
- WHEN it is decoded
- THEN decoding fails and no object is built
- @e2e exclude backend decoder; covered by PHPUnit

### Requirement: A file that does not decode fails its item (REQ-GHP-003)

A response that does not decode in the asked mode MUST fail that item with kind `decode` and a message that names the mode and the parser's reason. Under `onError: continue` the item MUST carry the error and MUST NOT carry the output key. The decoder MUST NOT return an empty object or an empty array for a body it could not read.

#### Scenario: a malformed publiccode.yml among good ones
- GIVEN three items whose files are a valid v0.2, a malformed file and a valid v0.4, and `onError: continue`
- WHEN the step runs
- THEN two items carry a parsed body and the second carries `_error` with kind `decode`
- @e2e exclude backend flow node; covered by PHPUnit

### Requirement: A spent quota suspends the run (REQ-GHP-004)

When a synchronization page answers 403 or 429 and its headers say `X-RateLimit-Remaining: 0` or carry `Retry-After`, the fetch MUST raise the rate-limit refusal with that response's rate-limit headers, so the flow node suspends the run until the reset. A 403 without those headers MUST stay an ordinary failed page.

#### Scenario: GitHub code search runs out of quota on page 3
- GIVEN a code search synchronization whose source answers page 3 with 403 and `X-RateLimit-Remaining: 0`
- WHEN the synchronization-run step runs
- THEN the run is suspended until `X-RateLimit-Reset`, clamped to between 60 seconds and one hour
- @e2e exclude backend engine; covered by PHPUnit and the live run recorded in the PR

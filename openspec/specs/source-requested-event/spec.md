# source-requested-event Specification

## Purpose
Let a sibling app that holds an outbound call to a plain URL obtain a Source for that URL's base,
so the call runs through `openconnector.source-call` with integriq's governance.

@e2e exclude The seam is a backend-only in-process typed-event exchange with no browser surface of
its own; a created Source shows on the existing Sources page, which has its own coverage. The
behaviour is proven by SourceRequestedListenerTest, FlowTemplateTest and SourceCallNodeTest here
and by dossiq's RetiredWebhookStepsTest on the consumer side.

## Requirements

### Requirement: A Source request is found or created once per base URL

Integriq SHALL expose `OCA\Integriq\Event\SourceRequestedEvent` carrying `sourceApp`, `baseUrl`,
`purpose`, an optional `timeoutSeconds` and an optional `userId`. The listener SHALL derive the
slug `url-<scheme>-<host>[-<port>]` from the base URL, return the Source with that slug when one
exists, and otherwise create an enabled `api` Source with that slug, the normalised base URL as
its location, no credentials, the timeout (at most 120 seconds) as `configuration.timeout`, and a
description naming the requesting app, the user and the purpose. Every find or create SHALL be
logged with the requesting app, the purpose and the user.

#### Scenario: The first request creates the Source

- **GIVEN** no Source has the slug `url-https-hooks-example-org-8443`
- **WHEN** dossiq requests `https://hooks.example.org:8443` with a timeout of 5 seconds
- **THEN** a Source MUST be created with location `https://hooks.example.org:8443`, enabled, with
  `configuration.timeout` 5 and no credential field
- **AND** the event MUST be handled, report the new uuid and slug, and `wasCreated()` MUST be true

#### Scenario: A second request returns the same Source

- **GIVEN** a Source with the slug `url-http-hooks-example-org` exists
- **WHEN** a request for `http://hooks.example.org` is handled
- **THEN** nothing MUST be created, and the event MUST report the existing Source with
  `wasCreated()` false

### Requirement: A request that is not a bare http(s) base URL on an allowed host is refused

The listener SHALL refuse, leaving the event unhandled with a reason, a base URL whose scheme is
not http or https, that has no host, that carries a user name, password, path, query or
fragment, or whose host `IRemoteHostValidator::isValid()` rejects. A failure to read or create the
Source SHALL also leave the event unhandled with the reason.

#### Scenario: A private address is refused

- **GIVEN** the instance does not allow local remote servers
- **WHEN** a request for `http://127.0.0.1` is handled
- **THEN** the event MUST stay unhandled, its refusal MUST name the host, and no Source MUST be
  created

### Requirement: A flow template can name the whole item

`FlowTemplate` SHALL resolve the reserved path `@item` to the item's entire record: typed when it
is the whole value, compact JSON inside text. No other path under `@item` is reserved.

#### Scenario: A source-call body posts the item itself

- **GIVEN** an `openconnector.source-call` step with body `{"case": "{{ @item }}"}`
- **WHEN** it runs on an item whose record is a case
- **THEN** the request body MUST be `{"case": <that case>}`

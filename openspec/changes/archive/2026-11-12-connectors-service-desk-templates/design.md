# Design: connectors-service-desk-templates

Kind: config, with two small node changes. Read at integriq development `6dca8100e`, stackiq `feature/sharing-itsm-exchange` `ff16c88f`, OpenRegister development `9a4e28e9a9`, on 2026-10-01. Widened that day for the Rotterdam programme; the first version (three templates, six presets, application records only) is replaced by the one below.

## Context

stackiq's `sharing-itsm-exchange` runs the exchange as OpenRegister flows: inbound `source-paginate`, `apply-mapping`, `contract`, `object-write`; outbound `trigger-object`, `apply-mapping`, `contract`, `source-call`. It needs from integriq the sources, the presets with their owners, the synchronizations its contracts are keyed on, and a way to send a mapped object as a body. Integriq ships all of that as data plus two node options. No vendor client in PHP.

## D1. Sources

| Slug | Location | Authentication | Records |
|---|---|---|---|
| `topdesk` | `https://<tenant>/tas/api` | HTTP Basic, operator login name and an application password | `/assetmgmt/assets`, `/assetmgmt/assetLinks` |
| `servicenow` | `https://<instance>.service-now.com` | HTTP Basic, integration user | `/api/now/table/{cmdb_ci_appl,cmdb_rel_ci,alm_license,ast_contract}` |
| `glpi` | `https://<host>/apirest.php` | `App-Token` and `user_token` headers | `/Appliance` |

Each ships `isEnabled: false` with a placeholder host. The secret is a broker placeholder at its own position (`configuration.authentication.password = {"credentialRef": {"credentialName": "topdesk-application-password"}}`), and `configuration.auth` renders it into Guzzle's basic-auth pair at call time. That is the injection path of ADR-064; the proxy path would need a host-lock in OpenRegister's provider catalogue, which a per-tenant host cannot have. The `authentication` key is stripped before dispatch and the secret is redacted from the call log by the existing code.

## D2. Field ownership

A mapping object gets `ownership: {<output field>: "source" | "<local app>"}`. The presets use `source` and `stackiq`. Every key of `mapping` must have an entry; a unit test checks every shipped preset, and the node refuses an unowned field at run time.

`openconnector.apply-mapping` gets two config keys:

- `ownership`: `inbound` or `outbound`;
- `exists`: a dot path on the item to the record id on the writing side.

When `exists` is empty the record is new and every mapped field is kept. When it is set, inbound keeps only the fields `source` owns and outbound keeps only the others. With OpenRegister's `object-write` upsert (a patch by default), an inbound update never touches a stackiq field and an outbound update never sends a desk field. Ownership is read from the same mapping object that is executed, so the two cannot drift. `exists` is not special-cased beyond empty or not: stackiq points it at a path that is always set to get the desk-owned projection it hashes for its contract.

The split, agreed with lane sq:

| Feed | Desk owns | stackiq owns |
|---|---|---|
| application | record id, link, name, supplier, installed version, status, description | owners, BBN level, TIME class, publication date, licence and contract summary |
| relation | both ends, name, type, direction | |
| licence, contract | record id, link, application, supplier | number, vendor reference, type, start, end, cost, cost period, currency, metric, seats |

Licence and contract terms are stackiq's, but many organisations first recorded them in the desk. So a create seeds them and an update never overwrites them: the same rule.

## D3. Presets

Inbound presets output stackiq's flat field names. Outbound presets read stackiq's usage at the mapping root and output desk field ids.

| Slug | Input | Notes |
|---|---|---|
| `itsm-topdesk-application-inbound` | an item of `dataSet` | Dutch and English life cycle values map onto stackiq's status enum |
| `itsm-topdesk-application-outbound` | stackiq usage | `type_id` from `_desk.templateId` (TOPdesk requires the template on create) |
| `itsm-topdesk-relation-inbound` | `{applicationRecordId, link}` | `link` is one `LinkedAsset` from `GET /assetLinks?sourceId=`; TOPdesk has no list of all links, so stackiq calls it per application |
| `itsm-topdesk-licence-inbound`, `-contract-inbound` | an item of `dataSet` | assets of the Licence and Contract templates |
| `itsm-servicenow-application-inbound` | an item of `result`, `sysparm_display_value=all` | `install_status` codes map onto stackiq's status |
| `itsm-servicenow-application-outbound` | stackiq usage | send with `sysparm_input_display_value=true`; stackiq fields in `u_` columns |
| `itsm-servicenow-relation-inbound` | a `cmdb_rel_ci` record | direction from the relation type name |
| `itsm-servicenow-licence-inbound`, `-contract-inbound` | `alm_license`, `ast_contract` | the contract's application from a `u_application` reference |
| `itsm-glpi-appliance-inbound`, `-outbound` | an Appliance, `expand_dropdowns=true` | |
| `itsm-file-application-inbound` | a spreadsheet row with stackiq's column names | the file owns every field |

Mapping Twig sees only the mapping input, so tenant data travels in the input as `_desk`: `baseUrl` (for the record link; without it the link is empty, never relative), `templateId` (TOPdesk create), `currency` (ServiceNow). Contract dates are `Y-m-d`, matching stackiq's `catalogContract.startDate` and `endDate` (`format: date`). Empty optional values are left out (`unsetIfValue==`), so an empty field never blanks the other side.

Answer paths for stackiq's desk profiles: TOPdesk create answers `FrontendAsset`, new id at `body.data.id`, update is `POST /assetmgmt/assets/{id}`; ServiceNow create answers 201, new id at `body.result.sys_id`, update is `PATCH`. Record links: TOPdesk `{baseUrl}/tas/secure/assetmgmt/card.html?unid={id}`, ServiceNow `{baseUrl}/{table}.do?sys_id={id}`.

## D4. Synchronizations

Dormant (no job), keyed by slug: `itsm-topdesk-applications`, `-licences`, `-contracts` (offset paging on `pageStart`, `pageSize` 500, records at `dataSet`, filtered on `templateName`), `itsm-servicenow-applications`, `-relations`, `-licences`, `-contracts` (offset paging on `sysparm_offset`, records at `result`), and `itsm-topdesk-outbound`, `itsm-servicenow-outbound`, `itsm-file-applications`, which no engine runs and only key stackiq's contracts. There is no `itsm-topdesk-relations` (see D3).

## D5. `bodyFrom` on source-call

`"bodyFrom": "<item path>"` sends the object at that path as the JSON body, untouched. It is refused together with `body`. When the path holds no object the step fails before any call of the page is sent: a create with an empty body would make an empty record in the desk and report success.

## D6. Mocks

`tests/mocks/topdesk/server.py` and `tests/mocks/servicenow/server.py`, standard-library Python, run as `rdam-mock-topdesk` and `rdam-mock-servicenow`. They replay the documented shapes (TOPdesk 200 or 206 by page, `dataSet`, `FrontendAsset`; ServiceNow `result`, `X-Total-Count`, `Link` with first, prev, next, last, the three display modes), answer 401 with each desk's own body when the login is wrong, and keep a request log and a `__set` endpoint to simulate an operator's edit in the desk.

## Declarative versus imperative

Declarative: sources, presets, ownership and synchronizations are seed data. Imperative, and small: the ownership filter and `bodyFrom`, both generic node options any two-way exchange can use.

## Seed data

Three sources, 13 mappings, 10 synchronizations, all dormant. OpenRegister's seed import skips an object that already exists, so an administrator's edit survives a re-import.

## Risks

- [Tenant field ids] TOPdesk field ids are per template and ServiceNow needs `u_` columns for stackiq's fields. The docs list them; the presets are editable.
- [Unconfirmed shapes] mocks replay the published documentation. Open task: one run against Ruben's ServiceNow developer instance.
- [GLPI 11 moves to a new API] the template carries the path; a second template can follow without touching the field names.

# Service desk connectors

Integriq ships the outside half of stackiq's CMDB exchange with TOPdesk, ServiceNow and GLPI: a source per desk, mapping presets in both directions, and the synchronizations stackiq's flows page with. stackiq's set-up action builds the flows on top of them. You configure the source here, once.

## Who owns which field

Every preset says, per field, who owns it: `source` (the service desk) or `stackiq`. When a record is new, every field is written. After that, the import only changes the fields the desk owns, and the export only sends the fields stackiq owns. So the desk wins for name, supplier, version and status, and stackiq keeps its own owners, BBN level, TIME class and contract terms, whatever happens on the other side.

| Feed | The desk owns | stackiq owns |
|---|---|---|
| Applications | record id and link, name, supplier, installed version, status, description | BBN level, TIME class, publication date, catalogue link, licence and contract summary |
| Relations | both ends, name, type, direction | |
| Licences and contracts | record id and link, application, supplier | number, vendor reference, type, start, end, cost, cost period, currency, metric, seats |

The licence and contract terms are filled in from the desk once, when stackiq first sees the contract, and never overwritten after that.

## Connect TOPdesk

1. In TOPdesk, create an operator for the exchange with read and write rights on the Application, Licence and Contract asset templates, and give it an application password (Modules, Supporting files, Application passwords).
2. In OpenRegister, add a credential named `topdesk-application-password` that holds that application password.
3. In Integriq, open the source **TOPdesk** and set:
   - the location to `https://<your environment>/tas/api`;
   - `configuration.authentication.username` to the operator's login name.
4. Check the field ids. The presets read and write these fields of the Application template: `name`, `supplier`, `version`, `lifecycleStatus`, `description`, and for stackiq's own data `stackiqId`, `catalogueUrl`, `bbnLevel`, `timeClassification`, `publicationDate`, `licencesBought`, `licencesInUse`, `licenceMetric`, `contractNumber`, `contractEndDate`. Different ids in your template? Edit the preset `itsm-topdesk-application-outbound` and the `fields` query of the synchronization `itsm-topdesk-applications`.
5. Enable the source. Then run stackiq's set-up and choose TOPdesk; it asks for the id of your Application template, which TOPdesk needs to create an asset.

The life cycle field may hold stackiq's values (`Acquisition`, `Planned`, `In production`, `To be phased out`, `Phased out`) or their Dutch counterparts (`Aanschaf`, `Gepland`, `In gebruik`, `Uit te faseren`, `Uitgefaseerd`).

## Connect ServiceNow

1. Create an integration user with the roles to read and write `cmdb_ci_appl`, and to read `cmdb_rel_ci`, `alm_license` and `ast_contract`.
2. Add these string columns to `cmdb_ci_appl` for the fields stackiq owns: `u_stackiq_id`, `u_catalogue_url`, `u_bbn_level`, `u_time_classification`, `u_publication_date`, `u_licences_bought`, `u_licences_in_use`, `u_licence_metric`, `u_contract_number`, `u_contract_end_date`. To link contracts to applications, add a reference column `u_application` to `ast_contract`.
3. In OpenRegister, add a credential named `servicenow-integration-password` with the user's password.
4. In Integriq, open the source **ServiceNow**, set the location to `https://<your instance>.service-now.com` and `configuration.authentication.username` to the integration user, and enable it.

## Connect GLPI

Add the credentials `glpi-app-token` and `glpi-user-token`, set the location of the source **GLPI** to `https://<your server>/apirest.php`, and enable it. GLPI has application presets only.

## What is seeded

| Kind | Slugs |
|---|---|
| Sources | `topdesk`, `servicenow`, `glpi` |
| Inbound presets | `itsm-topdesk-application-inbound`, `itsm-topdesk-relation-inbound`, `itsm-topdesk-licence-inbound`, `itsm-topdesk-contract-inbound`, `itsm-servicenow-application-inbound`, `itsm-servicenow-relation-inbound`, `itsm-servicenow-licence-inbound`, `itsm-servicenow-contract-inbound`, `itsm-glpi-appliance-inbound`, `itsm-file-application-inbound` |
| Outbound presets | `itsm-topdesk-application-outbound`, `itsm-servicenow-application-outbound`, `itsm-glpi-appliance-outbound` |
| Synchronizations | `itsm-topdesk-applications`, `-licences`, `-contracts`, `itsm-servicenow-applications`, `-relations`, `-licences`, `-contracts`, `itsm-topdesk-outbound`, `itsm-servicenow-outbound`, `itsm-file-applications` |

Everything ships switched off, with no secret on the source. Nothing runs until stackiq's set-up creates the flows.

## Flow options these use

- `openconnector.apply-mapping` with `ownership` (`inbound` or `outbound`) and `exists` (the path to the record id on the writing side) applies the ownership rule. Without them the step maps every field, as before.
- `openconnector.source-call` with `bodyFrom` sends the mapped object at that path as the request body.

See [Flow nodes](flow-nodes.md).

## Try it without a tenant

`tests/mocks/topdesk` and `tests/mocks/servicenow` are small servers that answer like TOPdesk's Assets API and ServiceNow's Table API. Their README files say how to run them next to a development instance. Start there before you connect a real desk.

# Data feeds

## Overview

An app can offer its data to reporting tools (Power BI, Excel, any OData or
web connector) without anyone writing an endpoint by hand. The app declares
its datasets in `lib/Settings/feeds.json`; Integriq turns each dataset into a
read-only feed endpoint and keeps one API product per app, so the normal
consumer, subscription and rate-limit rules of the gateway apply.

## Declaring feeds

`lib/Settings/feeds.json` holds a list of datasets. Each names its register and
schema, the fields it exposes, an optional fixed filter, the scope it is bound
by and a page size. The shape is published in Integriq's
`lib/Settings/feeds.schema.json`.

```json
{
  "feeds": [
    {
      "key": "ledger-lines",
      "title": "Ledger lines",
      "register": "shillinq",
      "schema": "ledgerLine",
      "fields": ["administrationId", "period", "account", "amount"],
      "filter": { "status": "posted" },
      "scope": { "parameter": "administrationId", "field": "administrationId" },
      "pageSize": 1000
    }
  ]
}
```

Integriq reads the file of every enabled app on install, on upgrade and when an
administrator asks for a sync. A dataset that names a field its schema does not
declare is refused with that field named; the other datasets of the app are
still served. A dataset no declaration names is never exposed.

## Reading a feed

Each dataset answers at `/api/endpoint/feeds/<app>/<key>`, GET only:

- the answer is `{ "value": [...], "@odata.nextLink": "..." }`, with only the
  declared fields; follow the next link until there is none;
- `$top` (at most the page size) and `$skip` page through the records;
- `$select` narrows to some of the declared fields;
- `$filter` accepts `eq` on declared fields, joined with `and`;
- any other query option, such as `$orderby`, answers `400` naming it.

## Who may read what

A feed is read by a **consumer** with its API key. On the consumer form, under
**Data feeds**, an administrator binds the consumer per app to the scope values
it may read, one line per app:

```
shillinq: <administration id>, <administration id>
```

The binding is applied as a filter on every request. A request for another
scope value, or from a consumer without a line for that app, answers `403`.
The check runs after authentication and the source allowlist, before the rate
limit. Clearing the field removes every binding.

## The Data feeds page

**Data feeds**, next to Endpoints in the menu, lists the feeds the installed
apps declare: the app, the address, the declared fields and the page size. The
consumers subscribed to an app's feeds are on its API product, `<app> data
feeds`, under **API products**.

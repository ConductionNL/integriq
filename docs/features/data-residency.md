# Data residency

An organisation that must keep personal data in the EU has to be able to say where every system it sends data to sits. Integriq is the place those calls leave from, so it records where each source processes the data, lists every outbound destination with its location, and can refuse to call a destination that is not shown to be in the EU.

## Where a source sits

Every source can carry a `residency`: the country (an ISO 3166-1 alpha-2 code), how it was established (`basis`), the evidence, and who recorded it and when.

| Basis | Set by |
|-------|--------|
| `set-by-admin` | An administrator, with **Set residency** on the Sources page or on the outbound destinations page. |
| `declared-by-app` | The app that declares the connection, through `residency` in its `connections.json` (`country` and `evidence`). The connection sync copies it onto the linked source. |

A residency an administrator set always wins: a later sync never overwrites it with a declared one.

Each country falls in one zone: `eu` (the 27 member states), `eea` (Iceland, Liechtenstein and Norway), `outside`, or `unknown` when no residency is recorded.

## Outbound destinations

**Connections → Outbound destinations** (administrators only) lists every place integriq sends data to:

- each enabled source, with its host, country, zone, basis and evidence;
- each host in the call log of the last 30 days that no source covers, as a row without a source and with zone `unknown`.

Above the list stand the verdict and the count per zone. The verdict is **EU only** when every destination is in the EU, and **Not proven** as soon as one is in the EEA, outside it, or unknown. Calls that other apps make without integriq are not covered by this page.

The same list is served as JSON by `GET /api/egress/destinations`, for an auditor's export.

## EU only mode

With **EU only mode** on, integriq refuses to call a source whose zone is not `eu`. No request is sent; the call log gets a record with status 451 and the message `refused-residency: <country or unknown>`. When the setting cannot be read, integriq treats the mode as on.

The mode cannot be switched on while any destination is not shown to be in the EU: the save is refused (`PUT /api/egress/eu-only` answers 409) and the message names those destinations. Record their residency first, or remove them.

With the mode off, which is the default, calls behave as before.

## API

| Route | What it does |
|-------|--------------|
| `GET /api/egress/destinations` | The destinations, the verdict (`eu-only` or `not-proven`), the counts per zone and whether EU only mode is on. |
| `PUT /api/egress/eu-only` | Body `{ "enabled": true }`. 409 with `blocking` when a destination is not in the EU. |
| `PUT /api/sources/{id}/residency` | Body `{ "country": "NL", "evidence": "..." }`. Stores it with basis `set-by-admin`. |

Every route is for Nextcloud administrators only and answers 403 to anyone else.

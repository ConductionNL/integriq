# TOPdesk Assets API mock

Replays TOPdesk's Asset Management API as its published specification 1.91.3 describes it (`https://developers.topdesk.com/swagger/assets_specification_1.91.3.json`), under `/tas/api/assetmgmt`:

- `GET /assets`: `dataSet` and `columns`, paged with `pageStart` (zero-based offset) and `pageSize` (0 to 1000). The answer is 206 when more assets match than the page holds, 200 on the last page. Filters `templateName`, `archived`, `fields`; `count` with `fetchCount=true`.
- `GET /assets/{id}` and the answer to a create or update: `FrontendAsset` (`data`, `fields`, `metadata`). The new id is at `data.id`.
- `POST /assets` creates (needs `type_id`, else 400 with `errors`); `POST /assets/{id}` updates, as TOPdesk does.
- `GET /assetLinks?sourceId=`: a bare array of `LinkedAsset`.
- A missing or wrong HTTP Basic login is 401.

Seed: five Application assets (life cycle values in Dutch), one Licence, one Contract, two asset links. Application template id `a72b24c1-0553-4f88-9add-5b5bb85c7d4e`.

| Variable | Default | Meaning |
|---|---|---|
| `PORT` | `8080` | Listen port |
| `MOCK_USER` | `stackiq` | Operator login name |
| `MOCK_PASSWORD` | `mock-password` | Application password (test only) |

Control endpoints, not part of TOPdesk and without login: `GET /__requests` lists the requests served with their bodies, `POST /__reset` restores the seed, `POST /__set/{id}` merges a JSON object into an asset as an operator editing it would.

## Run it

```bash
docker run -d --name rdam-mock-topdesk --network rdam2_default \
  -v "$PWD/tests/mocks/topdesk:/mock:ro" python:3.12-alpine python /mock/server.py
```

Point the `topdesk` source at `http://rdam-mock-topdesk:8080/tas/api`.

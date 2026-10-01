# ServiceNow Table API mock

Replays ServiceNow's Table API at `/api/now/table/{table}`:

- `GET` a table: `{"result": [...]}`, paged with `sysparm_offset` and `sysparm_limit`, with `X-Total-Count` and a `Link` header carrying `first`, `prev`, `next` and `last`.
- `GET`, `PATCH` and `PUT` one record by `sys_id`; 404 with ServiceNow's "No Record found" body when it does not exist.
- `POST` creates and answers 201; the new id is at `result.sys_id`.
- `sysparm_display_value` `false`, `true` and `all`, `sysparm_exclude_reference_link`, `sysparm_fields`, `sysparm_query` (`a=b` terms joined with `^`, one dot-walk through a reference), and `sysparm_input_display_value=true` to write a reference by its display value.
- A missing or wrong HTTP Basic login is 401 with `{"error": {"message": "User Not Authenticated", ...}, "status": "failure"}`.

Every value is a string, as ServiceNow sends it. Tables: `core_company`, `cmdb_ci_appl` (five applications), `cmdb_rel_type`, `cmdb_rel_ci` (three relations, one to a CI that is not an application), `alm_license`, `ast_contract`, `cmdb_contract_model`.

| Variable | Default | Meaning |
|---|---|---|
| `PORT` | `8080` | Listen port |
| `MOCK_USER` | `stackiq` | Integration user |
| `MOCK_PASSWORD` | `mock-password` | Its password (test only) |
| `BASE_URL` | `http://rdam-mock-servicenow:8080` | Used in links |

Control endpoints, not part of ServiceNow and without login: `GET /__requests`, `POST /__reset`, `POST /__set/{table}/{sys_id}` (merge raw values into a record).

## Run it

```bash
docker run -d --name rdam-mock-servicenow --network rdam2_default \
  -v "$PWD/tests/mocks/servicenow:/mock:ro" python:3.12-alpine python /mock/server.py
```

Point the `servicenow` source at `http://rdam-mock-servicenow:8080`.

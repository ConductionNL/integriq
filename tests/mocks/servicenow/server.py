"""A mock of the ServiceNow Table API for integriq's live tests.

Replays the shapes of ServiceNow's Table API (`/api/now/table/{table}`):

  GET    /api/now/table/{t}         {"result": [...]}, paged with
                                    `sysparm_offset` and `sysparm_limit`
                                    (default 10000), `X-Total-Count`, and a
                                    `Link` header with rel first, prev, next
                                    and last, as ServiceNow sends them.
  GET    /api/now/table/{t}/{id}    {"result": {...}}; 404 "No Record found".
  POST   /api/now/table/{t}         201 {"result": {...}} with a new sys_id.
  PATCH  /api/now/table/{t}/{id}    200 {"result": {...}}; PUT does the same.

Query parameters: `sysparm_display_value` (false, true, all),
`sysparm_exclude_reference_link`, `sysparm_fields`, `sysparm_query` (`a=b`
terms joined with `^`, with one dot-walk through a reference such as
`parent.sys_class_name=cmdb_ci_appl`), and `sysparm_input_display_value`
(a reference may be written by display value, as ServiceNow allows).

Every value is a string, as ServiceNow sends it. A reference is
`{"link": ..., "value": sys_id}`; with `all` every field is
`{"display_value": ..., "value": ...}`. A missing or wrong HTTP Basic login is
401 with ServiceNow's own error body.

Tables: core_company, cmdb_ci_appl, cmdb_rel_type, cmdb_rel_ci, alm_license,
ast_contract, cmdb_contract_model. Custom `u_` columns for the stackiq
owned fields exist on cmdb_ci_appl, as an instance prepared for the exchange
has them.

Standard library only. Control endpoints (no authentication):

  GET  /__requests            the requests served so far, bodies included
  POST /__reset               restore the seed data and clear the log
  POST /__set/{table}/{id}    merge raw values into a record, as an operator
                              editing it in ServiceNow would

Environment: PORT (8080), MOCK_USER (stackiq), MOCK_PASSWORD (mock-password),
BASE_URL (http://rdam-mock-servicenow:8080, used in links).
"""

import base64
import copy
import json
import os
import uuid
from datetime import datetime, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlencode, urlparse

PORT = int(os.environ.get("PORT", "8080"))
USER = os.environ.get("MOCK_USER", "stackiq")
PASSWORD = os.environ.get("MOCK_PASSWORD", "mock-password")
BASE_URL = os.environ.get("BASE_URL", "http://rdam-mock-servicenow:8080").rstrip("/")

# Field kinds per table: a reference names its table, a choice its labels.
REFERENCES = {
    "cmdb_ci_appl": {"vendor": "core_company"},
    "cmdb_rel_ci": {"parent": "*", "child": "*", "type": "cmdb_rel_type"},
    "alm_license": {"vendor": "core_company", "ci": "*"},
    "ast_contract": {"vendor": "core_company", "contract_model": "cmdb_contract_model", "u_application": "*"},
}
CHOICES = {
    ("cmdb_ci_appl", "install_status"): {"1": "Installed", "2": "On order", "3": "In maintenance",
                                         "4": "Pending install", "6": "In stock", "7": "Retired"},
    ("ast_contract", "payment_schedule"): {"monthly": "Monthly", "annually": "Annually", "one_time": "One time"},
}
DISPLAY_FIELD = {"core_company": "name", "cmdb_rel_type": "name", "cmdb_contract_model": "name",
                 "cmdb_ci_appl": "name", "alm_license": "display_name", "ast_contract": "number"}


def sid(n):
    return "%032x" % n


SEED = {
    "core_company": [
        {"sys_id": sid(1), "name": "Dimpact"},
        {"sys_id": sid(2), "name": "Centric"},
        {"sys_id": sid(3), "name": "PinkRoccade"},
    ],
    "cmdb_rel_type": [
        {"sys_id": sid(11), "name": "Sends data to::Receives data from"},
        {"sys_id": sid(12), "name": "Depends on::Used by"},
        {"sys_id": sid(13), "name": "Exchanges data with::Exchanges data with"},
    ],
    "cmdb_contract_model": [
        {"sys_id": sid(21), "name": "Software License"},
        {"sys_id": sid(22), "name": "Maintenance Contract"},
        {"sys_id": sid(23), "name": "Service Level Agreement"},
    ],
    "cmdb_ci_appl": [
        {"sys_id": sid(101), "name": "Zaaksysteem", "vendor": sid(1), "version": "2.4.1",
         "install_status": "1", "short_description": "Zaakgericht werken"},
        {"sys_id": sid(102), "name": "Burgerzaken", "vendor": sid(2), "version": "11.2",
         "install_status": "1", "short_description": "Basisregistratie personen"},
        {"sys_id": sid(103), "name": "Belastingen", "vendor": sid(3), "version": "7.0",
         "install_status": "7", "short_description": "Gemeentelijke belastingen"},
        {"sys_id": sid(104), "name": "Parkeren", "vendor": "", "version": "",
         "install_status": "4", "short_description": ""},
        {"sys_id": sid(105), "name": "Subsidies", "vendor": sid(1), "version": "1.0",
         "install_status": "2", "short_description": "Subsidieaanvragen"},
    ],
    "cmdb_rel_ci": [
        {"sys_id": sid(201), "parent": sid(101), "child": sid(102), "type": sid(11)},
        {"sys_id": sid(202), "parent": sid(101), "child": sid(103), "type": sid(13)},
        {"sys_id": sid(203), "parent": sid(104), "child": sid(9999), "type": sid(12)},
    ],
    "alm_license": [
        {"sys_id": sid(301), "display_name": "Zaaksysteem gebruikers", "asset_tag": "SL-2026-01",
         "po_number": "PO-77812", "vendor": sid(1), "ci": sid(101), "cost": "48000",
         "start_date": "2026-01-01", "end_date": "2028-12-31", "rights": "350"},
    ],
    "ast_contract": [
        {"sys_id": sid(401), "number": "CNTR0010014", "vendor": sid(2), "vendor_contract": "CEN-SLA-7781",
         "contract_model": sid(23), "starts": "2025-03-01", "ends": "2027-02-28",
         "payment_amount": "1250", "payment_schedule": "monthly", "u_application": sid(102)},
    ],
}

state = {"tables": copy.deepcopy(SEED), "log": []}


def now_sn():
    return datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")


def stamp(table, record):
    record.setdefault("sys_class_name", table)
    record.setdefault("sys_created_on", "2026-09-01 08:00:00")
    record["sys_updated_on"] = record.get("sys_updated_on", "2026-09-01 08:00:00")
    return record


for _table, _rows in state["tables"].items():
    for _row in _rows:
        stamp(_table, _row)
for _table, _rows in SEED.items():
    for _row in _rows:
        stamp(_table, _row)


def locate(sys_id):
    for table, rows in state["tables"].items():
        for row in rows:
            if row["sys_id"] == sys_id:
                return table, row
    return None, None


def display_of(table, field, raw):
    target = REFERENCES.get(table, {}).get(field)
    if target is not None:
        if not raw:
            return ""
        ref_table, ref = locate(raw)
        return "" if ref is None else ref.get(DISPLAY_FIELD.get(ref_table, "sys_id"), "")
    labels = CHOICES.get((table, field))
    if labels is not None:
        return labels.get(raw, raw)
    return raw


def render(table, row, mode, exclude_links, fields):
    keys = fields or list(row.keys())
    out = {}
    for key in keys:
        raw = row.get(key, "")
        is_ref = key in REFERENCES.get(table, {})
        link = ""
        if is_ref and raw:
            ref_table, _ = locate(raw)
            link = "%s/api/now/table/%s/%s" % (BASE_URL, ref_table or "sys_metadata", raw)
        if mode == "all":
            value = {"display_value": display_of(table, key, raw), "value": raw}
            if is_ref and raw and not exclude_links:
                value["link"] = link
        elif mode == "true":
            shown = display_of(table, key, raw)
            value = {"display_value": shown, "link": link} if is_ref and raw and not exclude_links else shown
        else:
            value = {"link": link, "value": raw} if is_ref and raw and not exclude_links else raw
        out[key] = value
    return out


def matches(table, row, query):
    for term in [t for t in query.split("^") if t]:
        if "=" not in term:
            continue
        field, _, expected = term.partition("=")
        if "." in field:
            ref_field, _, sub = field.partition(".")
            _, ref = locate(row.get(ref_field, ""))
            actual = "" if ref is None else ref.get(sub, "")
        else:
            actual = row.get(field, "")
        if actual != expected:
            return False
    return True


def resolve_reference(table, field, given, by_display):
    target = REFERENCES.get(table, {}).get(field)
    if target is None or not given:
        return given
    if locate(given)[1] is not None:
        return given
    if not by_display or target == "*":
        return given
    for row in state["tables"].setdefault(target, []):
        if row.get(DISPLAY_FIELD.get(target, "name")) == given:
            return row["sys_id"]
    # ServiceNow leaves an unmatched display value empty.
    return ""


def error(message, detail):
    return {"error": {"message": message, "detail": detail}, "status": "failure"}


class Handler(BaseHTTPRequestHandler):
    server_version = "ServiceNow-mock/1.0"

    def log_message(self, fmt, *args):  # noqa: N802
        return

    def _send(self, status, body, headers=None):
        payload = json.dumps(body).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json;charset=UTF-8")
        self.send_header("Content-Length", str(len(payload)))
        for name, value in (headers or {}).items():
            self.send_header(name, value)
        self.end_headers()
        self.wfile.write(payload)

    def _body(self):
        length = int(self.headers.get("Content-Length") or 0)
        if length == 0:
            return None
        raw = self.rfile.read(length)
        try:
            return json.loads(raw)
        except ValueError:
            return raw.decode(errors="replace")

    def _authorised(self):
        header = self.headers.get("Authorization", "")
        if not header.startswith("Basic "):
            return False
        try:
            user, _, password = base64.b64decode(header[6:]).decode().partition(":")
        except ValueError:
            return False
        return user == USER and password == PASSWORD

    def _control(self, body):
        url = urlparse(self.path)
        if url.path == "/__requests" and self.command == "GET":
            return self._send(200, state["log"])
        if url.path == "/__reset" and self.command == "POST":
            state["tables"] = copy.deepcopy(SEED)
            state["log"] = []
            return self._send(200, {"reset": True})
        parts = url.path.split("/")
        if url.path.startswith("/__set/") and len(parts) == 4 and self.command == "POST":
            _, row = locate(parts[3])
            if row is None:
                return self._send(404, error("No Record found", "Record doesn't exist"))
            row.update(body or {})
            row["sys_updated_on"] = now_sn()
            return self._send(200, {"result": row})
        return self._send(404, error("Invalid path", url.path))

    def _dispatch(self):
        body = self._body() if self.command in ("POST", "PUT", "PATCH") else None
        url = urlparse(self.path)
        if url.path.startswith("/__"):
            return self._control(body)
        state["log"].append({"method": self.command, "path": url.path, "query": parse_qs(url.query),
                             "body": body, "authorised": self._authorised(), "at": now_sn()})
        if not self._authorised():
            return self._send(401, error("User Not Authenticated", "Required to provide Auth information"))
        parts = [p for p in url.path.split("/") if p]
        if len(parts) < 4 or parts[:3] != ["api", "now", "table"]:
            return self._send(400, error("Requested URI does not represent any resource", url.path))
        table = parts[3]
        if table not in state["tables"]:
            return self._send(400, error("Invalid table %s" % table, None))
        query = {k: v[-1] for k, v in parse_qs(url.query).items()}
        mode = query.get("sysparm_display_value", "false").lower()
        exclude = query.get("sysparm_exclude_reference_link", "false").lower() == "true"
        fields = [f for f in query.get("sysparm_fields", "").split(",") if f]
        by_display = query.get("sysparm_input_display_value", "false").lower() == "true"
        rows = state["tables"][table]

        if len(parts) == 4 and self.command == "GET":
            return self._list(table, rows, query, mode, exclude, fields)
        if len(parts) == 4 and self.command == "POST":
            if not isinstance(body, dict):
                return self._send(400, error("Exception while reading request", "The payload is not valid JSON."))
            row = {"sys_id": uuid.uuid4().hex}
            for key, value in body.items():
                row[key] = resolve_reference(table, key, str(value), by_display)
            row = stamp(table, row)
            row["sys_created_on"] = row["sys_updated_on"] = now_sn()
            rows.append(row)
            return self._send(201, {"result": render(table, row, mode, exclude, fields)},
                              {"Location": "%s/api/now/table/%s/%s" % (BASE_URL, table, row["sys_id"])})
        if len(parts) == 5:
            row = next((r for r in rows if r["sys_id"] == parts[4]), None)
            if row is None:
                return self._send(404, error("No Record found",
                                             "Record doesn't exist or ACL restricts the record retrieval"))
            if self.command == "GET":
                return self._send(200, {"result": render(table, row, mode, exclude, fields)})
            if self.command in ("PATCH", "PUT"):
                if not isinstance(body, dict):
                    return self._send(400, error("Exception while reading request", "The payload is not valid JSON."))
                for key, value in body.items():
                    if key == "sys_id":
                        continue
                    row[key] = resolve_reference(table, key, str(value), by_display)
                row["sys_updated_on"] = now_sn()
                return self._send(200, {"result": render(table, row, mode, exclude, fields)})
        return self._send(405, error("Method not Supported", "%s is not supported here" % self.command))

    def _list(self, table, rows, query, mode, exclude, fields):
        selected = [r for r in rows if matches(table, r, query.get("sysparm_query", ""))]
        try:
            limit = int(query.get("sysparm_limit", "10000"))
            offset = int(query.get("sysparm_offset", "0"))
        except ValueError:
            return self._send(400, error("Invalid sysparm_limit or sysparm_offset", None))
        page = selected[offset:offset + limit]
        total = len(selected)

        def link(at, rel):
            params = dict(query)
            params["sysparm_offset"] = str(at)
            params["sysparm_limit"] = str(limit)
            return '<%s/api/now/table/%s?%s>;rel="%s"' % (BASE_URL, table, urlencode(params), rel)

        links = [link(0, "first")]
        if offset > 0:
            links.append(link(max(0, offset - limit), "prev"))
        if offset + limit < total:
            links.append(link(offset + limit, "next"))
        last = 0 if total == 0 or limit == 0 else ((total - 1) // limit) * limit
        links.append(link(last, "last"))
        headers = {"X-Total-Count": str(total), "Link": ",".join(links)}
        return self._send(200, {"result": [render(table, r, mode, exclude, fields) for r in page]}, headers)

    do_GET = do_POST = do_PUT = do_PATCH = do_DELETE = _dispatch


if __name__ == "__main__":
    ThreadingHTTPServer(("0.0.0.0", PORT), Handler).serve_forever()

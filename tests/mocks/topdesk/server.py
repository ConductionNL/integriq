"""A mock of the TOPdesk Asset Management API for integriq's live tests.

Replays the shapes of TOPdesk's Assets API, specification 1.91.3
(https://developers.topdesk.com/swagger/assets_specification_1.91.3.json),
under the base path `/tas/api/assetmgmt`:

  GET  /assets               GridAssetStructureResponse: `dataSet`, `columns`,
                             `count` (with fetchCount=true). Paged with
                             `pageStart` (zero-based offset) and `pageSize`
                             (0..1000, default 50); 206 when more assets match
                             than the page holds, 200 otherwise. Filters:
                             `templateName`, `archived`, `fields`.
  GET  /assets/{id}          FrontendAsset: `data`, `fields`, `metadata`.
  POST /assets               create, body needs `type_id` (template id);
                             answers FrontendAsset, 400 with
                             ValidationErrorsResponse when it is missing.
  POST /assets/{id}          update (TOPdesk updates with POST); 404 when
                             unknown.
  GET  /assetLinks?sourceId= a bare array of LinkedAsset.

Every TOPdesk call needs HTTP Basic with an operator login name and an
application password; anything else is 401, as TOPdesk answers.

Standard library only. Control endpoints, not part of TOPdesk and without
authentication:

  GET  /__requests           the requests served so far, bodies included
  POST /__reset              restore the seed data and clear the log
  POST /__set/{id}           merge a JSON object into an asset, as an
                             operator editing it in TOPdesk would

Environment: PORT (8080), MOCK_USER (stackiq), MOCK_PASSWORD (mock-password).
"""

import base64
import copy
import json
import os
import uuid
from datetime import datetime, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse

PORT = int(os.environ.get("PORT", "8080"))
USER = os.environ.get("MOCK_USER", "stackiq")
PASSWORD = os.environ.get("MOCK_PASSWORD", "mock-password")
BASE = "/tas/api/assetmgmt"

TEMPLATES = {
    "Application": "a72b24c1-0553-4f88-9add-5b5bb85c7d4e",
    "Licence": "b1c0f6a2-3d4e-4f50-8a61-7b8c9d0e1f20",
    "Contract": "c2d1e7b3-4e5f-4061-9b72-8c9d0e1f2a31",
}
TEMPLATE_NAMES = {v: k for k, v in TEMPLATES.items()}


def _asset(asset_id, template, name, **fields):
    record = {"id": asset_id, "name": name, "@type": {"name": template}, "archived": False}
    record.update(fields)
    record["@etag"] = "2026-09-30T10:00:00.000"
    return record


SEED = [
    _asset("0b6f4d2e-1a2b-4c3d-8e9f-000000000001", "Application", "Zaaksysteem",
           supplier="Dimpact", version="2.4.1", lifecycleStatus="In gebruik",
           description="Zaakgericht werken voor alle afdelingen"),
    _asset("0b6f4d2e-1a2b-4c3d-8e9f-000000000002", "Application", "Burgerzaken",
           supplier="Centric", version="11.2", lifecycleStatus="In gebruik",
           description="Basisregistratie personen en reisdocumenten"),
    _asset("0b6f4d2e-1a2b-4c3d-8e9f-000000000003", "Application", "Belastingen",
           supplier="PinkRoccade", version="7.0", lifecycleStatus="Uit te faseren",
           description="Heffen en innen van gemeentelijke belastingen"),
    _asset("0b6f4d2e-1a2b-4c3d-8e9f-000000000004", "Application", "Financien",
           supplier="Exact", version="2025.3", lifecycleStatus="Gepland",
           description="Financiele administratie"),
    _asset("0b6f4d2e-1a2b-4c3d-8e9f-000000000005", "Application", "Omgevingsloket",
           supplier="Rijkswaterstaat", version="", lifecycleStatus="Aanschaf",
           description=""),
    _asset("0b6f4d2e-1a2b-4c3d-8e9f-000000000101", "Licence", "LIC-2026-001",
           application="0b6f4d2e-1a2b-4c3d-8e9f-000000000001", supplier="Dimpact",
           contractNumber="LIC-2026-001", vendorReference="DIM-88213",
           startDate="2026-01-01T00:00:00.000", endDate="2028-12-31T00:00:00.000",
           cost="48000.00", costPeriod="Jaarlijks", currency="EUR",
           licenceMetric="Per named user", licencesBought="350"),
    _asset("0b6f4d2e-1a2b-4c3d-8e9f-000000000201", "Contract", "CON-2025-014",
           application="0b6f4d2e-1a2b-4c3d-8e9f-000000000002", supplier="Centric",
           contractNumber="CON-2025-014", vendorReference="CEN-SLA-7781", contractType="SLA",
           startDate="2025-03-01T00:00:00.000", endDate="2027-02-28T00:00:00.000",
           cost="1250.00", costPeriod="Maandelijks", currency="EUR"),
]

LINKS = [
    {"source": "0b6f4d2e-1a2b-4c3d-8e9f-000000000001", "target": "0b6f4d2e-1a2b-4c3d-8e9f-000000000002",
     "linkId": "9f8e7d6c-0000-4000-8000-000000000001", "capabilityId": "e462d679-aec2-4cc8-b81d-09236de4c198",
     "capabilityName": "Uses data from", "linkType": "child"},
    {"source": "0b6f4d2e-1a2b-4c3d-8e9f-000000000003", "target": "0b6f4d2e-1a2b-4c3d-8e9f-000000000004",
     "linkId": "9f8e7d6c-0000-4000-8000-000000000002", "capabilityId": None,
     "capabilityName": None, "linkType": "parent"},
]

state = {"assets": copy.deepcopy(SEED), "log": []}


def now_iso():
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%S.000")


def find(asset_id):
    for record in state["assets"]:
        if record["id"] == asset_id:
            return record
    return None


def project(record, fields):
    if not fields:
        keys = ["id", "name", "@type"]
    else:
        keys = ["id"] + [f for f in fields if f != "id"]
    return {k: record.get(k, "") for k in keys}


def frontend_asset(record):
    data = {k: v for k, v in record.items() if k != "@type"}
    data["@status"] = "OPERATIONAL"
    return {
        "data": data,
        "fields": {k: {"fieldType": "text"} for k in data if not k.startswith("@")},
        "metadata": {"templateId": TEMPLATES[record["@type"]["name"]], "templateName": record["@type"]["name"]},
        "settings": {},
    }


class Handler(BaseHTTPRequestHandler):
    server_version = "TOPdesk-mock/1.0"

    def log_message(self, fmt, *args):  # noqa: N802 - keep stdout quiet
        return

    def _send(self, status, body=None, content_type="application/json"):
        payload = b"" if body is None else json.dumps(body).encode()
        self.send_response(status)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(payload)))
        if status == 401:
            self.send_header("WWW-Authenticate", 'Basic realm="TOPdesk"')
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

    def _record(self, body):
        url = urlparse(self.path)
        state["log"].append({
            "method": self.command, "path": url.path, "query": parse_qs(url.query),
            "body": body, "authorised": self._authorised(), "at": now_iso(),
        })

    def _control(self, body):
        url = urlparse(self.path)
        if url.path == "/__requests" and self.command == "GET":
            return self._send(200, state["log"])
        if url.path == "/__reset" and self.command == "POST":
            state["assets"] = copy.deepcopy(SEED)
            state["log"] = []
            return self._send(200, {"reset": True})
        if url.path.startswith("/__set/") and self.command == "POST":
            record = find(url.path[len("/__set/"):])
            if record is None:
                return self._send(404, {"errors": [{"errorCode": "notFound"}]})
            record.update(body or {})
            record["@etag"] = now_iso()
            return self._send(200, frontend_asset(record))
        return self._send(404, {"errors": [{"errorCode": "notFound"}]})

    def _dispatch(self):
        body = self._body() if self.command in ("POST", "PUT", "PATCH") else None
        url = urlparse(self.path)
        if url.path.startswith("/__"):
            return self._control(body)
        self._record(body)
        if not url.path.startswith(BASE):
            return self._send(404, {"errors": [{"errorCode": "notFound"}]})
        if not self._authorised():
            return self._send(401, None, "text/plain")
        path = url.path[len(BASE):].rstrip("/")
        query = {k: v[-1] for k, v in parse_qs(url.query).items()}
        if path == "/assets" and self.command == "GET":
            return self._list(query)
        if path == "/assets" and self.command == "POST":
            return self._create(body)
        if path.startswith("/assets/") and path.count("/") == 2:
            asset_id = path[len("/assets/"):]
            if self.command == "GET":
                record = find(asset_id)
                return self._send(200, frontend_asset(record)) if record else self._send(404)
            if self.command == "POST":
                return self._update(asset_id, body)
        if path == "/assetLinks" and self.command == "GET":
            return self._links(query)
        return self._send(405 if path in ("/assets", "/assetLinks") else 404)

    def _list(self, query):
        try:
            start = int(query.get("pageStart", "0"))
            size = int(query.get("pageSize", "50"))
        except ValueError:
            return self._send(400, {"errors": [{"errorCode": "invalidParameter", "field": "pageStart"}]})
        if size < 0 or size > 1000:
            return self._send(400, {"errors": [{"errorCode": "invalidParameter", "field": "pageSize"}]})
        records = state["assets"]
        names = [n for n in query.get("templateName", "").split(",") if n]
        if names:
            records = [r for r in records if r["@type"]["name"] in names]
        if query.get("archived") in ("true", "false"):
            records = [r for r in records if r["archived"] == (query["archived"] == "true")]
        fields = [f for f in query.get("fields", "").split(",") if f]
        page = records[start:start + size]
        body = {
            "columns": [{"fieldName": f, "displayName": f, "fieldType": "text"} for f in (["id"] + fields if fields else ["id", "name", "@type"])],
            "dataSet": [project(r, fields) for r in page],
        }
        if query.get("fetchCount") == "true":
            body["count"] = len(records)
        return self._send(206 if start + size < len(records) else 200, body,
                          "application/x.topdesk-am-assets-v2+json")

    def _create(self, body):
        if not isinstance(body, dict) or body.get("type_id") not in TEMPLATE_NAMES:
            return self._send(400, {"errors": [{"errorCode": "required", "field": "type_id",
                                                "message": "The ID of the template is required"}]})
        if not body.get("name"):
            return self._send(400, {"errors": [{"errorCode": "required", "field": "name"}]})
        fields = {k: v for k, v in body.items() if k not in ("type_id", "name") and not k.startswith("@")}
        record = _asset(str(uuid.uuid4()), TEMPLATE_NAMES[body["type_id"]], body["name"], **fields)
        state["assets"].append(record)
        return self._send(200, frontend_asset(record))

    def _update(self, asset_id, body):
        record = find(asset_id)
        if record is None:
            return self._send(404)
        if not isinstance(body, dict):
            return self._send(400, {"errors": [{"errorCode": "invalidBody"}]})
        record.update({k: v for k, v in body.items() if k not in ("type_id", "id") and not k.startswith("@")})
        record["@etag"] = now_iso()
        return self._send(200, frontend_asset(record))

    def _links(self, query):
        source_id = query.get("sourceId")
        result = []
        for link in LINKS:
            if source_id and link["source"] != source_id:
                continue
            target = find(link["target"])
            if target is None:
                continue
            item = {
                "assetId": target["id"], "linkId": link["linkId"], "linkType": link["linkType"],
                "name": target["name"], "type": target["@type"]["name"], "archived": False,
                "archivedAsset": target["archived"], "status": "OPERATIONAL", "summary": target.get("description", ""),
            }
            if link["capabilityId"]:
                item["capabilityId"] = link["capabilityId"]
                item["capabilityName"] = link["capabilityName"]
            result.append(item)
        return self._send(200, result)

    do_GET = do_POST = do_PUT = do_PATCH = do_DELETE = _dispatch


if __name__ == "__main__":
    ThreadingHTTPServer(("0.0.0.0", PORT), Handler).serve_forever()

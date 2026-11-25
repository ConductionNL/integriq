#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
"""A small MCP tool server over streamable HTTP, for live tests of an mcp gateway endpoint.

It answers JSON-RPC on every POST path:

- ``initialize``: a result, with the header ``Mcp-Session-Id: mock-session-1``.
- ``tools/list``: the tools ``search`` and ``stream``.
- ``tools/call`` of ``search``: a text result naming the query it got.
- ``tools/call`` of ``headers``: a text result holding, as JSON, the request
  headers the tool server received (so a test sees what the gateway passed on).
- ``tools/call`` of ``stream``: with ``Accept: text/event-stream``, three
  ``message`` events, one JSON-RPC message each (the last carries the result).
- anything else: the JSON-RPC error -32601.

``GET /_last`` answers the method, path and headers of the last POST, so a test
can check what the gateway passed on (and that a consumer key never reached
the tool server). ``GET /_count`` answers how many POSTs arrived.

Environment: ``PORT`` (default 8091), ``HOST`` (default 127.0.0.1).
Run: ``python3 tests/mocks/mcp/server.py``.
"""
import json
import os
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

SESSION = "mock-session-1"
STATE = {"last": None, "count": 0}
TOOLS = [
    {"name": "search", "description": "Search the mock index.", "inputSchema": {"type": "object", "properties": {"q": {"type": "string"}}}},
    {"name": "headers", "description": "Answer the request headers received.", "inputSchema": {"type": "object"}},
    {"name": "stream", "description": "Answer as an event stream.", "inputSchema": {"type": "object"}},
]


class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, fmt, *args):  # quiet
        return

    def _json(self, status, payload, headers=None):
        body = json.dumps(payload).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        for key, value in (headers or {}).items():
            self.send_header(key, value)
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        if self.path.startswith("/_last"):
            self._json(200, STATE["last"] or {})
        elif self.path.startswith("/_count"):
            self._json(200, {"count": STATE["count"]})
        else:
            self._json(405, {"error": "use POST"})

    def do_POST(self):
        raw = self.rfile.read(int(self.headers.get("Content-Length") or 0))
        STATE["count"] += 1
        STATE["last"] = {"method": "POST", "path": self.path, "headers": {k.lower(): v for k, v in self.headers.items()}}
        try:
            message = json.loads(raw or b"{}")
        except ValueError:
            self._json(400, {"jsonrpc": "2.0", "id": None, "error": {"code": -32700, "message": "parse error"}})
            return
        rpc_id = message.get("id")
        method = message.get("method")
        if method == "initialize":
            result = {"protocolVersion": "2025-06-18", "capabilities": {"tools": {}}, "serverInfo": {"name": "mock-mcp", "version": "1.0.0"}}
            self._json(200, {"jsonrpc": "2.0", "id": rpc_id, "result": result}, {"Mcp-Session-Id": SESSION})
        elif method == "tools/list":
            self._json(200, {"jsonrpc": "2.0", "id": rpc_id, "result": {"tools": TOOLS}}, {"Mcp-Session-Id": SESSION})
        elif method == "tools/call" and (message.get("params") or {}).get("name") == "search":
            query = ((message.get("params") or {}).get("arguments") or {}).get("q", "")
            result = {"content": [{"type": "text", "text": "found: " + str(query)}]}
            self._json(200, {"jsonrpc": "2.0", "id": rpc_id, "result": result}, {"Mcp-Session-Id": SESSION})
        elif method == "tools/call" and (message.get("params") or {}).get("name") == "headers":
            result = {"content": [{"type": "text", "text": json.dumps(STATE["last"]["headers"])}]}
            self._json(200, {"jsonrpc": "2.0", "id": rpc_id, "result": result}, {"Mcp-Session-Id": SESSION})
        elif method == "tools/call" and (message.get("params") or {}).get("name") == "stream":
            events = [
                {"jsonrpc": "2.0", "method": "notifications/progress", "params": {"progress": 1, "total": 2}},
                {"jsonrpc": "2.0", "method": "notifications/progress", "params": {"progress": 2, "total": 2}},
                {"jsonrpc": "2.0", "id": rpc_id, "result": {"content": [{"type": "text", "text": "streamed"}]}},
            ]
            body = "".join("event: message\ndata: " + json.dumps(e) + "\n\n" for e in events).encode()
            self.send_response(200)
            self.send_header("Content-Type", "text/event-stream")
            self.send_header("Cache-Control", "no-cache")
            self.send_header("Mcp-Session-Id", SESSION)
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
        else:
            self._json(200, {"jsonrpc": "2.0", "id": rpc_id, "error": {"code": -32601, "message": "method not found"}})


if __name__ == "__main__":
    server = ThreadingHTTPServer((os.environ.get("HOST", "127.0.0.1"), int(os.environ.get("PORT", "8091"))), Handler)
    server.serve_forever()

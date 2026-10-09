"""A mock of GitHub code search for integriq's live tests.

Replays the shapes GitHub's REST API really sends for
`GET /search/code` (as of API version 2022-11-28): the JSON body
(`total_count`, `incomplete_results`, `items[]` with `repository`), the
RFC 5988 `Link` header with `rel="next"` and `rel="last"`, and the
`X-RateLimit-*` headers. When the quota is spent it answers the way GitHub
does: HTTP 403 with `X-RateLimit-Remaining: 0` and a `X-RateLimit-Reset`
in epoch seconds.

Standard library only. Control endpoints (not part of GitHub):

  POST /__reset?limit=N   reset the quota to N requests (default LIMIT)
  GET  /__requests        the requests served so far, as JSON

Environment: PORT (8080), LIMIT (10, GitHub's code search per minute),
TOTAL (25 repositories in the corpus), WINDOW (60 seconds until reset).
"""

import json
import os
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlencode, urlparse

PORT = int(os.environ.get("PORT", "8080"))
LIMIT = int(os.environ.get("LIMIT", "10"))
TOTAL = int(os.environ.get("TOTAL", "25"))
WINDOW = int(os.environ.get("WINDOW", "60"))

# Real repositories that publish a publiccode.yml, so a follow-up fetch from
# raw.githubusercontent.com finds an actual file for the first two.
KNOWN = [
    ("ConductionNL", "opencatalogi", "main"),
    ("Amsterdam", "signals-frontend", "main"),
    ("italia", "publiccode-editor", "master"),
]

state = {"remaining": LIMIT, "reset": int(time.time()) + WINDOW, "log": []}


def corpus():
    items = []
    for i in range(TOTAL):
        if i < len(KNOWN):
            owner, repo, _ = KNOWN[i]
        else:
            owner, repo = "example-gemeente", "app-%02d" % i
        full = "%s/%s" % (owner, repo)
        sha = ("%040x" % (i * 7919 + 1))[-40:]
        items.append({
            "name": "publiccode.yml",
            "path": "publiccode.yml",
            "sha": sha,
            "url": "https://api.github.com/repositories/%d/contents/publiccode.yml?ref=%s" % (100000 + i, sha),
            "git_url": "https://api.github.com/repositories/%d/git/blobs/%s" % (100000 + i, sha),
            "html_url": "https://github.com/%s/blob/%s/publiccode.yml" % (full, sha),
            "repository": {
                "id": 100000 + i,
                "node_id": "R_kgDO%06d" % i,
                "name": repo,
                "full_name": full,
                "private": False,
                "owner": {"login": owner, "id": 5000 + i, "type": "Organization",
                          "html_url": "https://github.com/%s" % owner},
                "html_url": "https://github.com/%s" % full,
                "description": "Mock repository %d" % i,
                "fork": False,
                "url": "https://api.github.com/repos/%s" % full,
            },
            "score": 1.0,
        })
    return items


class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, fmt, *args):
        pass

    def send_json(self, status, body, headers=None):
        raw = json.dumps(body).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(raw)))
        for key, value in (headers or {}).items():
            self.send_header(key, value)
        self.end_headers()
        self.wfile.write(raw)

    def rate_headers(self):
        return {
            "X-RateLimit-Limit": str(LIMIT),
            "X-RateLimit-Remaining": str(max(state["remaining"], 0)),
            "X-RateLimit-Reset": str(state["reset"]),
            "X-RateLimit-Used": str(LIMIT - max(state["remaining"], 0)),
            "X-RateLimit-Resource": "code_search",
        }

    def do_POST(self):
        url = urlparse(self.path)
        if url.path == "/__reset":
            query = parse_qs(url.query)
            limit = int(query.get("limit", [LIMIT])[0])
            state["remaining"] = limit
            state["reset"] = int(time.time()) + WINDOW
            state["log"] = []
            return self.send_json(200, {"remaining": limit, "reset": state["reset"]})
        return self.send_json(404, {"message": "Not Found"})

    def do_GET(self):
        url = urlparse(self.path)
        if url.path == "/__requests":
            return self.send_json(200, state["log"])

        state["log"].append({"path": self.path, "at": int(time.time()),
                             "authorization": "present" if self.headers.get("Authorization") else "absent",
                             "apiVersion": self.headers.get("X-GitHub-Api-Version")})

        # GitHub refills the quota when the window ends.
        if int(time.time()) >= state["reset"]:
            state["remaining"] = LIMIT
            state["reset"] = int(time.time()) + WINDOW

        if url.path != "/search/code":
            return self.send_json(404, {"message": "Not Found",
                                        "documentation_url": "https://docs.github.com/rest"})

        if state["remaining"] <= 0:
            return self.send_json(403, {
                "message": "API rate limit exceeded for user ID 1.",
                "documentation_url": "https://docs.github.com/rest/overview/rate-limits-for-the-rest-api",
            }, self.rate_headers())

        state["remaining"] -= 1
        query = parse_qs(url.query)
        page = max(int(query.get("page", ["1"])[0] or 1), 1)
        per_page = min(max(int(query.get("per_page", ["30"])[0] or 30), 1), 100)
        items = corpus()
        last = max((len(items) + per_page - 1) // per_page, 1)
        chunk = items[(page - 1) * per_page: page * per_page]

        base = {k: v[0] for k, v in query.items()}
        host = self.headers.get("Host", "localhost:%d" % PORT)

        def link(p):
            q = dict(base, page=str(p))
            return "<http://%s/search/code?%s>" % (host, urlencode(q))

        rels = []
        if page < last:
            rels.append('%s; rel="next"' % link(page + 1))
            rels.append('%s; rel="last"' % link(last))
        if page > 1:
            rels.append('%s; rel="prev"' % link(page - 1))
            rels.append('%s; rel="first"' % link(1))

        headers = self.rate_headers()
        if rels:
            headers["Link"] = ", ".join(rels)

        return self.send_json(200, {"total_count": len(items), "incomplete_results": False,
                                    "items": chunk}, headers)


if __name__ == "__main__":
    ThreadingHTTPServer(("0.0.0.0", PORT), Handler).serve_forever()

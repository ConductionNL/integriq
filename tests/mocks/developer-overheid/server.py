"""A mock of the developer.overheid.nl OSS-register for integriq's live tests.

Replays the shapes the register's API (oss-register v1) sends:

  GET /oss-register/v1/repositories?publiccode=true&perPage=N&page=P
      A JSON array of RepositorySummary records (id, url, name,
      shortDescription, organisation{uri,label}, publicCodeUrl, forkType,
      archived, createdAt, lastCrawledAt, lastActivityAt) with the headers
      Total-Count, Total-Pages, Current-Page and Per-Page. A Link header with
      single-quoted rel values (as in the register's published example) names
      'next' and 'last' when there are more pages, and 'prev' and 'first' after
      page 1; a listing that fits on one page has no Link header. perPage is at
      most 100. A page past the last is an empty array.
  GET /oss-register/v1/repositories/{id}
      RepositoryDetail: the summary plus publicCode (the register crawler's
      parse, which lacks categories, softwareVersion, releaseDate, logo,
      applicationSuite, it and legal.repoOwner) and longDescription. 404 for an
      unknown id.

Both answer 401 with the register's message when the X-Api-Key header is
missing or is not KEY.

It also serves the publiccode.yml files the records point at, under the
paths OpenCatalogi derives from each publicCodeUrl, so the raw-file sources
can be pointed here too. These need no key, like the real hosts:

  GET /raw/github/{owner}/{repo}/{ref}/{path}                 (github-raw)
  GET /raw/gitlab/{namespace}/{project}/-/raw/{ref}/{path}    (gitlab-raw)

The corpus mixes github.com and gitlab.com repositories and holds one of
each case the harvest must handle: a file below the repository root
(monorepo), a fork whose file names the original, a repository on another
host (only the detail endpoint has its publicCode), a file that is broken
YAML and a file that is missing (404).

Control endpoints, not part of the register:

  GET  /__requests   the requests served so far, as JSON (never the key)
  POST /__reset      clear that list

Environment: PORT (8080), TOTAL (92 repositories), KEY (YOUR_API_KEY_HERE),
BASE (the URL the Link headers name; default http://{Host header}).

To test against it point developer-overheid-oss at {BASE}/oss-register/v1,
github-raw at {BASE}/raw/github and gitlab-raw at {BASE}/raw/gitlab.

Standard library only.
"""

import json
import os
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlencode, urlparse

PORT = int(os.environ.get("PORT", "8080"))
TOTAL = int(os.environ.get("TOTAL", "92"))
KEY = os.environ.get("KEY", "YOUR_API_KEY_HERE")
BASE = os.environ.get("BASE", "").rstrip("/")

API = "/oss-register/v1"
MAX_PER_PAGE = 100
DEFAULT_PER_PAGE = 10

# The register's 401, verbatim.
UNAUTHORIZED = {"status": 401, "title": "Missing credentials (Authorization or x-api-key)"}

# The records with a special role, by position in the corpus (1-based). The
# rest alternate between github.com and gitlab.com.
SPECIAL = {
    1: {"host": "github", "path": "example-org/catalogus", "file": "publiccode.yml"},
    2: {"host": "gitlab", "path": "example-group/sub/project", "file": "publiccode.yml"},
    3: {"host": "github", "path": "example-org/monorepo", "file": "apps/portal/publiccode.yml", "role": "monorepo"},
    4: {"host": "github", "path": "example-fork/catalogus", "file": "publiccode.yml", "role": "fork",
        "names": "https://github.com/example-org/catalogus"},
    5: {"host": "other", "path": "example-org/elders", "file": "publiccode.yml", "role": "other-host"},
    6: {"host": "github", "path": "example-org/kapot", "file": "publiccode.yml", "role": "broken"},
    7: {"host": "gitlab", "path": "example-group/weg", "file": "publiccode.yml", "role": "missing"},
}

BROKEN_YAML = "publiccodeYmlVersion: \"0.4\"\nname: [unclosed\n  url: : :\n\t- tab indented\n"

state = {"log": []}


def record_id(n):
    return "00000000-0000-0000-0000-%012d" % n


def repository(n):
    """The RepositorySummary of the n-th repository, plus the mock's own notes."""
    spec = SPECIAL.get(n)
    if spec is None:
        host = "github" if n % 2 == 1 else "gitlab"
        spec = {"host": host, "path": "example-gemeente-%02d/app-%02d" % (n, n), "file": "publiccode.yml"}

    if spec["host"] == "github":
        url = "https://github.com/%s" % spec["path"]
        public_code_url = "%s/blob/main/%s" % (url, spec["file"])
        raw_path = "/raw/github/%s/main/%s" % (spec["path"], spec["file"])
    elif spec["host"] == "gitlab":
        url = "https://gitlab.com/%s" % spec["path"]
        public_code_url = "%s/-/blob/main/%s?ref_type=heads" % (url, spec["file"])
        raw_path = "/raw/gitlab/%s/-/raw/main/%s" % (spec["path"], spec["file"])
    else:
        url = "https://codeberg.org/%s" % spec["path"]
        public_code_url = "%s/src/branch/main/%s" % (url, spec["file"])
        raw_path = None

    day = 1 + (n % 28)
    summary = {
        "id": record_id(n),
        "url": url,
        "name": spec["path"].split("/")[-1],
        "shortDescription": "Example repository %d of the mock OSS-register." % n,
        "organisation": {
            "uri": "https://identifier.example.invalid/organisation/%d" % (1 + n % 5),
            "label": "Example: Organisatie %d" % (1 + n % 5),
        },
        "publicCodeUrl": public_code_url,
        "forkType": "GIT_FORK" if spec.get("role") == "fork" else None,
        "archived": False,
        "createdAt": "2024-01-%02dT09:00:00Z" % day,
        "lastCrawledAt": "2026-10-15T02:00:00Z",
        "lastActivityAt": "2026-09-%02dT12:00:00Z" % day,
    }
    notes = {"role": spec.get("role"), "rawPath": raw_path, "names": spec.get("names", url)}
    return summary, notes


def corpus():
    return [repository(n) for n in range(1, TOTAL + 1)]


def public_code(summary, notes):
    """The register crawler's parse of the file: a subset of publiccode.yml."""
    return {
        "publiccodeYmlVersion": "0.4",
        "name": summary["name"],
        "url": notes["names"],
        "landingURL": summary["url"],
        "platforms": ["web"],
        "developmentStatus": "stable",
        "softwareType": "standalone/web",
        "description": {
            "nl": {
                "shortDescription": summary["shortDescription"],
                "longDescription": "Een voorbeeldcomponent uit de mock van het OSS-register, nummer %s." % summary["id"],
                "features": ["Voorbeeldfunctie"],
            },
        },
        "legal": {"license": "EUPL-1.2"},
        "maintenance": {"type": "internal", "contacts": [{"name": "Example: Beheerder"}]},
        "localisation": {"localisationReady": True, "availableLanguages": ["nl"]},
    }


def publiccode_yml(summary, notes):
    """A full publiccode.yml for the raw routes, written by hand (no YAML library)."""
    return "\n".join([
        "publiccodeYmlVersion: \"0.4\"",
        "name: %s" % json.dumps(summary["name"]),
        "url: %s" % json.dumps(notes["names"]),
        "landingURL: %s" % json.dumps(summary["url"]),
        "softwareVersion: \"1.0.0\"",
        "releaseDate: \"2026-01-31\"",
        "platforms:",
        "  - web",
        "categories:",
        "  - it-development",
        "developmentStatus: stable",
        "softwareType: standalone/web",
        "description:",
        "  nl:",
        "    shortDescription: %s" % json.dumps(summary["shortDescription"]),
        "    longDescription: %s" % json.dumps(
            "Een voorbeeldcomponent uit de mock van het OSS-register, nummer %s." % summary["id"]),
        "    features:",
        "      - Voorbeeldfunctie",
        "legal:",
        "  license: EUPL-1.2",
        "maintenance:",
        "  type: internal",
        "  contacts:",
        "    - name: \"Example: Beheerder\"",
        "localisation:",
        "  localisationReady: true",
        "  availableLanguages:",
        "    - nl",
        "",
    ])


class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, fmt, *args):
        pass

    def send_body(self, status, raw, content_type, headers=None):
        self.send_response(status)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(raw)))
        for key, value in (headers or {}).items():
            self.send_header(key, value)
        self.end_headers()
        self.wfile.write(raw)

    def send_json(self, status, body, headers=None):
        self.send_body(status, json.dumps(body).encode(), "application/json; charset=utf-8", headers)

    def send_problem(self, status, title):
        self.send_body(status, json.dumps({"status": status, "title": title}).encode(),
                       "application/problem+json", None)

    def key_state(self):
        given = self.headers.get("X-Api-Key")
        if given is None:
            return "absent"
        return "valid" if given == KEY else "wrong"

    def do_POST(self):
        if urlparse(self.path).path == "/__reset":
            state["log"] = []
            return self.send_json(200, {"requests": 0})
        return self.send_problem(404, "Not Found")

    def do_GET(self):
        url = urlparse(self.path)
        if url.path == "/__requests":
            return self.send_json(200, state["log"])

        state["log"].append({"path": self.path, "at": int(time.time()), "apiKey": self.key_state()})

        if url.path.startswith("/raw/"):
            return self.raw(url.path)

        if url.path == API + "/repositories" or url.path.startswith(API + "/repositories/"):
            if self.key_state() != "valid":
                return self.send_body(401, json.dumps(UNAUTHORIZED).encode(), "application/problem+json")
            if url.path == API + "/repositories":
                return self.listing(parse_qs(url.query))
            return self.detail(url.path[len(API + "/repositories/"):])

        return self.send_problem(404, "Not Found")

    def listing(self, query):
        try:
            page = max(int(query.get("page", ["1"])[0] or 1), 1)
            per_page = min(max(int(query.get("perPage", [str(DEFAULT_PER_PAGE)])[0] or DEFAULT_PER_PAGE), 1),
                           MAX_PER_PAGE)
        except ValueError:
            return self.send_problem(400, "Bad Request")

        records = [summary for summary, _ in corpus()]
        last = max((len(records) + per_page - 1) // per_page, 1)
        chunk = records[(page - 1) * per_page: page * per_page]

        base = BASE or ("http://%s" % self.headers.get("Host", "localhost:%d" % PORT))
        params = {k: v[0] for k, v in query.items()}
        params["perPage"] = str(per_page)

        def link(p):
            return "<%s%s/repositories?%s>" % (base, API, urlencode(dict(params, page=str(p))))

        rels = []
        if page < last:
            rels.append("%s; rel='next'" % link(page + 1))
            rels.append("%s; rel='last'" % link(last))
        if page > 1:
            rels.append("%s; rel='prev'" % link(page - 1))
            rels.append("%s; rel='first'" % link(1))

        headers = {
            "Total-Count": str(len(records)),
            "Total-Pages": str(last),
            "Current-Page": str(page),
            "Per-Page": str(per_page),
        }
        if rels:
            headers["Link"] = ", ".join(rels)

        return self.send_json(200, chunk, headers)

    def detail(self, rid):
        for summary, notes in corpus():
            if summary["id"] == rid:
                code = public_code(summary, notes)
                return self.send_json(200, dict(summary, publicCode=code,
                                                longDescription=code["description"]["nl"]["longDescription"]))
        return self.send_problem(404, "Not Found")

    def raw(self, path):
        for summary, notes in corpus():
            if notes["rawPath"] != path:
                continue
            if notes["role"] == "missing":
                break
            if notes["role"] == "broken":
                return self.send_body(200, BROKEN_YAML.encode(), "text/plain; charset=utf-8")
            return self.send_body(200, publiccode_yml(summary, notes).encode(), "text/plain; charset=utf-8")
        return self.send_body(404, b"404: Not Found", "text/plain; charset=utf-8")


class MockServer(ThreadingHTTPServer):
    """A listen backlog that survives a flow's concurrent fetches.

    The default backlog is 5. A harvest run fans out dozens of raw-file
    requests at once, and the connections past the fifth were refused, which
    integriq records as a 503 for that file.
    """

    request_queue_size = 128
    daemon_threads = True


if __name__ == "__main__":
    MockServer(("0.0.0.0", PORT), Handler).serve_forever()

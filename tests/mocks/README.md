# Mock servers for live tests

Small HTTP servers that replay the documented response shapes of outside systems, so a flow can be tested on a dev instance without an account at the real system. Each one is a single Python file using only the standard library.

| Directory | Stands in for | Used by |
|---|---|---|
| `github/` | GitHub code search (`GET /search/code`) with `Link` paging and `X-RateLimit-*` headers | `sources-github-publiccode` |

## Run one

On a Docker network the Nextcloud container can reach (the Rotterdam test stack uses `rdam_default`):

```bash
docker run -d --name rdam-mock-github --network rdam_default \
  -v "$PWD/tests/mocks/github:/mock:ro" -e LIMIT=10 \
  python:3.12-alpine python /mock/server.py
```

Then point a source at `http://rdam-mock-github:8080`. Each server's own docstring lists its environment variables and control endpoints.

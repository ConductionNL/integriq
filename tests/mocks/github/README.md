# GitHub code search mock

Replays `GET /search/code` as GitHub sends it for API version `2022-11-28`:

- the body: `total_count`, `incomplete_results`, `items[]` with `repository.full_name`, `path`, `sha`, `url`, `html_url`;
- `Link` with `rel="next"`, `rel="last"`, `rel="prev"`, `rel="first"`;
- `X-RateLimit-Limit`, `-Remaining`, `-Reset` (epoch seconds), `-Used`, `-Resource: code_search`.

When the quota is spent it answers `403` with `X-RateLimit-Remaining: 0`, as GitHub does. The quota refills when `X-RateLimit-Reset` passes.

The first three results are real repositories with a `publiccode.yml` (ConductionNL/opencatalogi, Amsterdam/signals-frontend, italia/publiccode-editor), so a follow-up fetch from `raw.githubusercontent.com` finds a real file.

| Variable | Default | Meaning |
|---|---|---|
| `PORT` | `8080` | Listen port |
| `LIMIT` | `10` | Requests per window, GitHub's code search limit per minute |
| `TOTAL` | `25` | Results in the corpus |
| `WINDOW` | `60` | Seconds until the quota refills |

Control endpoints, not part of GitHub: `POST /__reset?limit=N` resets the quota and the request log; `GET /__requests` lists the requests served.

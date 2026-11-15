# The source behind this endpoint did not answer correctly

Problem type `https://integriq.nl/problems/upstream-unavailable`, status 500, 502, 503 or 504.

The gateway could not get a usable answer from the source it proxies to, or failed while handling the request.

Every gateway error is an RFC 9457 problem document with content type `application/problem+json` and the members `type`, `title`, `status`, `detail` and `instance` (the request id). For one release the document also carries the old `error` and `details` members; they are deprecated, read `detail` instead.

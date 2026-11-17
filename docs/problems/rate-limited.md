# Too many requests

Problem type `https://integriq.nl/problems/rate-limited`, status 429.

The consumer passed its rate limit or quota. The `RateLimit` and `Retry-After` headers say when to try again.

Every gateway error is an RFC 9457 problem document with content type `application/problem+json` and the members `type`, `title`, `status`, `detail` and `instance` (the request id). For one release the document also carries the old `error` and `details` members; they are deprecated, read `detail` instead.

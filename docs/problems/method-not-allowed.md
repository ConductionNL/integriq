# The method is not allowed on this endpoint

Problem type `https://integriq.nl/problems/method-not-allowed`, status 405.

The endpoint does not accept this HTTP method.

Every gateway error is an RFC 9457 problem document with content type `application/problem+json` and the members `type`, `title`, `status`, `detail` and `instance` (the request id). For one release the document also carries the old `error` and `details` members; they are deprecated, read `detail` instead.

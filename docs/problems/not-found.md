# The resource was not found

Problem type `https://integriq.nl/problems/not-found`, status 404.

The object, endpoint or source the request names does not exist, or is not visible to the caller.

Every gateway error is an RFC 9457 problem document with content type `application/problem+json` and the members `type`, `title`, `status`, `detail` and `instance` (the request id). For one release the document also carries the old `error` and `details` members; they are deprecated, read `detail` instead.

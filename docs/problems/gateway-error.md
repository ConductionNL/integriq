# The gateway could not handle the request

Problem type `https://integriq.nl/problems/gateway-error`, status any other.

A gateway error with no more specific type.

Every gateway error is an RFC 9457 problem document with content type `application/problem+json` and the members `type`, `title`, `status`, `detail` and `instance` (the request id). For one release the document also carries the old `error` and `details` members; they are deprecated, read `detail` instead.

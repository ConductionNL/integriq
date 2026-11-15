# The caller may not make this call

Problem type `https://integriq.nl/problems/forbidden`, status 403.

The caller was authenticated but lacks a scope or right the endpoint requires. `detail` names what is missing.

Every gateway error is an RFC 9457 problem document with content type `application/problem+json` and the members `type`, `title`, `status`, `detail` and `instance` (the request id). For one release the document also carries the old `error` and `details` members; they are deprecated, read `detail` instead.

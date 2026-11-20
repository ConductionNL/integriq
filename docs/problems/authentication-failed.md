# The caller could not be authenticated

Problem type `https://integriq.nl/problems/authentication-failed`, status 401.

The credential was missing, wrong, expired, or of a kind this endpoint does not accept. Check the key or token and the authentication the endpoint requires.

Every gateway error is an RFC 9457 problem document with content type `application/problem+json` and the members `type`, `title`, `status`, `detail` and `instance` (the request id). For one release the document also carries the old `error` and `details` members; they are deprecated, read `detail` instead.

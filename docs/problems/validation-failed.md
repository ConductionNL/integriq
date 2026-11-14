# The request is not valid

Problem type `https://integriq.nl/problems/validation-failed`, status 400 or 422.

The request body or parameters did not match what the endpoint expects. `invalid-params` lists each field with `name` and `reason`.

Every gateway error is an RFC 9457 problem document with content type `application/problem+json` and the members `type`, `title`, `status`, `detail` and `instance` (the request id). For one release the document also carries the old `error` and `details` members; they are deprecated, read `detail` instead.

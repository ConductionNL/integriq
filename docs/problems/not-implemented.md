# This is not supported

Problem type `https://integriq.nl/problems/not-implemented`, status 501.

The endpoint is configured with something this version of integriq does not support, such as an unknown authentication method.

Every gateway error is an RFC 9457 problem document with content type `application/problem+json` and the members `type`, `title`, `status`, `detail` and `instance` (the request id). For one release the document also carries the old `error` and `details` members; they are deprecated, read `detail` instead.

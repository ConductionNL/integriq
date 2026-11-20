# AsyncAPI 3.0.0 JSON schema

`asyncapi-3.0.0.schema.json` is `schemas/3.0.0-without-$id.json` from the
npm package `@asyncapi/specs` 6.8.0, unchanged. It is the official
AsyncAPI 3.0.0 document schema, licensed Apache-2.0 by the AsyncAPI
Initiative. The `$id`-free variant resolves every reference locally, so a
validator needs no network.

`tests/Unit/Service/AsyncApiDocumentSchemaTest.php` validates the document
`AsyncApiDocumentService` builds against it.

# Design: objecten-api-facade

Kind: code. Two standard APIs, served as endpoint configuration over
OpenRegister, with one mapping table and one token model.

## D1. An objecttype is a schema, by configuration

An `objecttype` configuration object carries the VNG uuid, the name, the
OpenRegister register and schema it stands for, and the allowed
`versions`. Nothing derives the mapping from a name: two registers may
hold a schema called `melding`, and guessing which one a national uuid
means is how a municipality's data ends up in the wrong register.

## D2. The wire shape is the standard's, not ours

`record.data` holds the object's data, `record.startAt`,
`record.registrationAt`, `record.correctionFor` and `record.geometry` hold
what the standard puts beside it. The translator pattern is the one
`zgw-version-translation` already uses: one translator per resource, each
testable per direction, with the literal-leak guard so an OpenRegister
field never reaches a VNG consumer under our name.

## D3. The token is checked before OpenRegister's RBAC, never instead of it

`Authorization: Token <key>` resolves to a token object with a permission
per objecttype. A refusal at that layer is the standard's 403. A request
that passes then runs as the token's configured principal, so
OpenRegister's own RBAC, multitenancy and field-level rules still apply.
Two gates, both closed by default; a token that names an objecttype it has
no permission for never reaches the register.

## D4. `jsonSchema` is rendered, never stored twice

The Objecttypen API's `jsonSchema` comes from the OpenRegister schema at
read time. Storing a copy would mean a schema edit and the published
objecttype could disagree, and the caller would believe the copy.

## D5. Versions come from the schema's version chain

`/{uuid}/versions/{version}` answers from the schema version the
configuration allows. A version the configuration does not list is 404,
not a silent fall back to the newest, because a consumer pinned to v1 that
silently receives v2 is a data corruption they will not see.

## D6. Credentials by reference

The token's material resolves through the OpenRegister credential broker
(ADR-064). No key in a method argument, a log line, an endpoint
configuration export or the objecttype configuration.

## D7. Every seam is wired in one place, and a read carries the principal

The handlers take their OpenRegister access as callables so each is
testable alone. `OpenRegisterObjectenGateway` is the production side of all
six: `objectRead` and `objectWrite`/`objectDelete` go through OpenRegister's
object service, `schemaRead` through the schema mapper, `credentialRead`
through the credential broker by reference, and `announce` through
`EventService::emitCloudEvent()` (type `nl.vng.objecten.object.<actie>`), so
a subscription whose action forwards to a Notificaties API carries the
change on. `ObjectenWiring` registers one factory per facade service, called
from `Application::register()`; without it the container autowired every
handler with its seams at null and every route answered 401 or 404.

The read seam originally took no principal, while D3 says a request runs as
the token's principal. It now takes one: `index`, `show` and `search` pass
the principal the token check resolved, and the gateway runs the read as
that user (a volatile session user, restored after). A principal that is no
user of the instance reads and writes nothing, and a warning is logged.
Two reads are NOT made as the principal, on purpose: the objecttype and
token declarations (integriq's own admin-only configuration; no token may
choose which tokens exist) and a schema's definition for the Objecttypen
API (configuration the objecttype publishes, read after the token check).

The declarations live in two admin-only schemas in the integriq register,
`objecttype` and `objecten_token` (fragment
`lib/Settings/register.d/objecten-api-facade.json`). The published uuid is
the `publishedUuid` property, not the configuration object's own id, so a
reseed does not move it.

## Risks

- **A national uuid that must survive a register rebuild.** The uuid lives
  on the objecttype configuration, not on the OpenRegister schema, so
  reseeding a register does not change what a counterparty has registered.
- **Geometry search performance.** The standard's `POST /objects/search`
  takes a geometry and a radius. It delegates to OpenRegister's geo query;
  the tasks require the query plan to be read rather than assumed.
- **A leaf app that keeps its controller.** Two answers at two URLs is
  worse than one. The declaration task includes deleting the app-side
  controller, and ADR-091 §3 governs which URL keeps answering.

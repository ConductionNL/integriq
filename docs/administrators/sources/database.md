# Database sources

A back-office database without an API can be an integriq source. Integriq
connects to PostgreSQL, MySQL, MariaDB and Microsoft SQL Server, and runs
only the statements you declare on the source. A synchronization or flow
names a statement and sends values for its parameters, never SQL.

## Connecting

Create a source of type `database` and fill its connection:

| Field | What |
|---|---|
| `database.driver` | `pdo_pgsql` (PostgreSQL), `pdo_mysql` (MySQL and MariaDB) or `pdo_sqlsrv` (SQL Server) |
| `database.host`, `database.port` | The server; leave the port empty for the driver's default |
| `database.dbname` | The database |
| `database.user` | The account integriq connects as. Use a read-only account unless the source declares write statements |
| `database.timeout` | Seconds connecting and each statement may take; 30 by default, at most 300 |

The password is never stored on the source. Put a credential reference in
`configuration.authentication.password`, for example
`{"credentialRef": {"credentialName": "zaken-db"}}`, pointing at a generic
(inject-only) credential in OpenRegister's credential store that lists
`openconnector` among its allowed apps. A password typed into the source is
refused.

The server needs the PHP extension for the driver (`pdo_pgsql`, `pdo_mysql`
or `pdo_sqlsrv`). The adapter's health lists which drivers are available; a
source whose driver is missing is refused with the extension named.

## Declaring statements

`statements` lists every statement integriq may run:

```json
{
  "name": "zaken-sinds",
  "kind": "read",
  "sql": "SELECT id, zaaknummer, omschrijving FROM zaken WHERE id > :after ORDER BY id LIMIT :limit",
  "parameters": [],
  "pageKey": "id",
  "pageStart": 0,
  "pageSize": 500
}
```

- One statement per entry, with named placeholders (`:name`) only. A `?`
  placeholder, a second statement after `;`, or a placeholder that is not
  declared is refused.
- `parameters` declares every placeholder with its type (`string`,
  `integer`, `number`, `boolean`). Values a caller sends for anything not
  declared are never bound.
- A read with a `pageKey` pages by keyset: integriq binds `:after` to the last
  key of the previous page (`pageStart` for the first) and `:limit` to the page
  size, and reads until a page is shorter than the page size. Keyset paging
  does not drift on a table that changes while it is read, as offset paging
  does.
- A page returns at most 5,000 rows, 500 unless the statement or the caller
  asks otherwise.
- `kind: write` statements change rows. The source page says when a source
  declares any, so you can check the account's rights.

## On the source page

The source page of a database source shows where it connects, lists the
statements, and has **Test the statement** on each read statement: it runs the
statement once and shows the first page of rows and how long it took. A write
statement is never run from the page. **Show tables and columns** lists the
tables and views the account can see, to help write statements.

## Synchronizations

A synchronization with source type `database` names its read statement in
`sourceConfig.statement`, its values in `sourceConfig.parameters` and,
optionally, `sourceConfig.pageSize`. Rows go to the mapping as any other
source's objects do. If a later page fails, what was read is kept and the
fetch is marked incomplete, so no deletions follow from it.

A synchronization with target type `database` writes through
`targetConfig.statement`; the mapped object's fields are the values, plus
`targetId`. `targetConfig.deleteStatement` handles deletions and
`targetConfig.idField` (default `id`) names the field that becomes the
contract's target id.

## Example

The seed data ships a dormant source `example-postgres` with the read
statement above. Set its host, database, account and credential reference
before enabling it.

## Not supported

Oracle (the `oci8` extension is not in a standard Nextcloud image), change
data capture (the adapter polls), and NoSQL stores.

# Design — Retrofit xml-response

Retrofit change. Tasks describe retroactive annotation, not new implementation work.

## Context

`lib/Http/XMLResponse.php` is a Nextcloud `OCP\AppFramework\Http\Response`
subclass that serialises array payloads to XML and sets
`Content-Type: application/xml; charset=utf-8` (plus an attachment
`Content-Disposition` if the request path ends in `.xml`). The serialisation
is a recursive walk via `DOMDocument`:

- `arrayToXml($data, $rootTag = null)` is the public entry point. It picks a
  root tag (either explicit, `@root` from data, or `'root'`), creates the
  root element, walks the data via `buildXmlElement`, and post-processes the
  serialised string (decimal `&#13;` → hex `&#xD;`, self-closing-tag space).
- `buildXmlElement($dom, $element, $data)` handles `@attributes`, `#text`,
  indexed-array fan-out, and recurses for associative arrays via
  `createChildElement`.
- `createChildElement($dom, $parent, $tagName, $data)` either recurses (array)
  or coerces to string and appends a safe text node. Objects without
  `__toString` (and `IQueryBuilder` instances explicitly) are replaced with
  `'[Object of class Foo]'`.
- `createSafeTextNode($dom, $text)` decodes HTML entities **twice** before
  creating the text node.

This class is consumed by the endpoint runtime (cluster `endpoint-runtime`)
when an endpoint configures XML output, and by any controller that returns an
`XMLResponse` directly.

## Observed-but-suspicious behaviour (flagged, not fixed)

| Site | Issue | Severity |
|---|---|---|
| `createSafeTextNode` | calls `html_entity_decode` **twice** — explicitly to "handle cases like `&amp;#039;` into `'`". This unwinds attacker-controlled entity escapes: if the upstream caller passes `&amp;lt;script&amp;gt;` (already-escaped HTML for `&lt;script&gt;`), the double-decode unwraps it to literal `<script>` which is then placed as a DOM text node (DOM will re-escape on serialisation, so the on-the-wire XML is safe, BUT any consumer that uses the rendered XML inside an HTML context after a second decode pass would see executable script). The name "createSafe…" oversells the safety property. | medium — sanitiser anti-pattern, depends on downstream consumer |
| `setRenderCallback` | replaces `render()` with arbitrary callable; the callable receives `$this->getData()` (the unwrapped `['value' => $this->data]`) and is trusted to produce XML. Bypasses every safety property of the DOM path including the `&#13;` normalisation. No documented contract that the callback must produce valid XML or escape input — caller responsibility. | medium — unbounded escape hatch |
| `arrayToXml::preg_replace` | post-serialisation regex `\/<([^>]*)\/>\/` rewrites `<foo/>` to `<foo />` (cosmetic). `[^>]*` is fine for well-formed DOM output, but is a fragile contract if `$dom->saveXML()` ever produces a tag with embedded `>` inside an attribute value (it shouldn't, but this is the assumption being baked into the class). | low — cosmetic, no security impact |
| `createChildElement::IQueryBuilder` | hard-coded `instanceof IQueryBuilder` special-case to skip `__toString` (which would emit a parametrised SQL fragment). Suggests prior incident — the rest of the class is generic about object handling but this one type gets a fence. | informational — code smell of a past bug |
| `buildXmlElement::is_numeric` | tag names that look like numbers are rewritten to `item<N>` to keep XML well-formed (XML tags can't start with a digit). Tag names with other invalid characters (`:`, `<`, spaces) are NOT sanitised — `DOMDocument::createElement` will throw `DOMException`. | low — partial sanitisation |
| `__construct::str_ends_with('.xml')` | the attachment-header guard relies on a `?string $path` argument that the controller must supply. If forgotten, large XML payloads render in-browser instead of downloading. Not a security issue, just an ergonomics surface. | informational |

There is **no XML parsing** in this class — it only emits XML, so the
classic XXE / billion-laughs surface does not apply here. (Inbound XML
parsing is in the rule-pipeline cluster, deferred to a separate retrofit.)

## REQ → method map

| REQ | Methods |
|---|---|
| REQ-001 | `getData` + `setRenderCallback` (paired: accessor + override) |
| REQ-002 | `render` |
| REQ-003 | `arrayToXml` |
| REQ-004 | `buildXmlElement` |
| REQ-005 | `createChildElement` + `createSafeTextNode` (paired: child creation always ends in a safe text node) |

## What the spec deliberately does NOT cover

- Constructor wiring — the constructor's header/content-type logic is REQ-implicit
  but the wiring itself is plumbing.
- The XML schema or shape of the payloads consumed by clients — that's the
  caller's contract.

## Validation

After archive, `openspec validate xml-response --strict` MUST pass.

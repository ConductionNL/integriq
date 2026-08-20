---
status: done
---

# xml-response Specification

## Purpose
Provides an XML response type that serialises an array payload to a well-formed XML string via DOMDocument, honouring an `@root` key (or explicit root tag) for the document element and supporting `@attributes`, `#text`, indexed-array fan-out, and numeric-key rewriting in the recursive walk. It allows a render callback to fully override the default serialisation, applies carriage-return normalisation, and uses safe text nodes with an object-to-string fallback that hides IQueryBuilder SQL fragments.

@e2e exclude backend XML serialisation helper (array→XML rendering, no browser UI) — all 13 scenarios below are asserted by `OCA\OpenConnector\Tests\Unit\Http\XMLResponseTest` (tests/Unit/Http/XMLResponseTest.php), which drives the real `OCA\OpenConnector\Http\XMLResponse` through `render()` and `arrayToXml()` and runs in the `tests/Unit` PHPUnit suite on every CI leg.

## Requirements
### Requirement: Data accessor and render-callback override (REQ-001)

`getData(): array` MUST return `['value' => $this->data]` (the constructor
wraps a string payload as `['content' => $string]` before storage). This is
the shape passed to both the default `render()` path and any callable
installed via `setRenderCallback`.

`setRenderCallback(callable $callback): self` MUST store the callable and
return `$this` for fluent chaining. The callable signature is
`fn(array $data): string` where `$data` is the `getData()` shape.

The callback, if set, MUST replace the entire default DOM serialisation
path — `render()` invokes it and returns its result verbatim.

#### Scenario: getData wraps the stored payload

- **GIVEN** an XMLResponse constructed with `['foo' => 'bar']`
- **WHEN** `getData()` is called
- **THEN** the result is `['value' => ['foo' => 'bar']]`

#### Scenario: setRenderCallback returns self for chaining

- **WHEN** `setRenderCallback(fn($d) => '<a/>')` is called
- **THEN** the return value is the same XMLResponse instance

#### Notes

- The render-callback is a **complete bypass** of the safe DOM path
  including the CR-normalisation and entity-escaping. Caller is solely
  responsible for producing well-formed XML. Documented as observed
  contract; documented in design.md as a security-adjacent escape
  hatch.

---

### Requirement: Top-level render with @root unwrap (REQ-002)

`render(): string` MUST first dispatch to the render-callback if one is
installed. Otherwise, it MUST inspect the wrapped data
(`getData()['value']`). If the wrapped data has an `@root` key, the
method MUST call `arrayToXml($data)` and trust `arrayToXml` to use the
`@root` value as the document element name. Otherwise, the method MUST
wrap the data in `['value' => $data]` and call `arrayToXml(..., rootTag:
'response')`.

The return value is the rendered XML string. The method MUST NOT throw.

#### Scenario: @root convention drives the document element name

- **GIVEN** a payload `['@root' => 'envelope', 'body' => 'hello']`
- **WHEN** `render()` is called (no callback set)
- **THEN** the output XML begins `<envelope>` and contains `<body>hello</body>`

#### Scenario: no @root falls back to <response><value>...</value></response>

- **GIVEN** a payload `['foo' => 'bar']` with no `@root`
- **WHEN** `render()` is called
- **THEN** the output begins `<response>` and contains `<value><foo>bar</foo></value>`

---

### Requirement: Array-to-XML serialisation with DOMDocument (REQ-003)

`arrayToXml(array $data, ?string $rootTag = null): string` MUST construct a
`DOMDocument('1.0', 'UTF-8')` with `formatOutput = true`, create a root
element from the first non-null of: `$rootTag` argument, `$data['@root']`,
the literal `'root'`. The method MUST strip `@root` from `$data` after
extraction so it does not surface as a child element.

The method MUST recurse into the data via `buildXmlElement` (REQ-004),
serialise via `DOMDocument::saveXML()`, then post-process:

1. Replace all `&#13;` (decimal carriage-return entity) with `&#xD;`
   (hexadecimal form) — for downstream parsers that only recognise the
   hex form.
2. Run a regex `<([^>]*)\/>` → `<$1 />` to add a space before the
   self-closing slash.

If element creation fails or `saveXML()` returns `false`, the method MUST
return an empty string.

#### Scenario: explicit rootTag overrides @root

- **GIVEN** data `['@root' => 'ignored', 'a' => 1]` and rootTag `'used'`
- **WHEN** `arrayToXml($data, 'used')` is called
- **THEN** the output document element is `<used>` not `<ignored>`

#### Scenario: CR normalisation rewrites &#13; to &#xD;

- **GIVEN** data containing a string with `\r` characters
- **WHEN** `arrayToXml(...)` is called
- **THEN** the output contains `&#xD;` but no `&#13;`

#### Notes

- The post-serialisation regex is fragile against attribute values that
  contain a `>` character (DOM should never produce such, but the
  assumption is baked in). Documented in design.md.

---

### Requirement: Recursive DOM walk for arrays with @attributes and #text (REQ-004)

`buildXmlElement(DOMDocument $dom, DOMElement $element, array $data): void` MUST
process `$data` in this order:

1. **`@attributes`**: if present and an array, iterate and call
   `$element->setAttribute((string) $key, (string) $value)` for each
   entry, then unset the key.
2. **`#text`**: if present, append a safe text node (REQ-005) with the
   string cast of `$data['#text']`, then unset the key.
3. **Remaining keys**: iterate. For each key:
   - Strip a leading `@` via `ltrim($key, '@')`.
   - If the key is numeric, rewrite to `"item$key"` to keep XML well-formed.
   - If the value is an indexed array of arrays (first element is an array),
     fan out one child element per item with the same tag name.
   - If the value is an associative array, create one child element via
     `createChildElement` (REQ-005) and recurse.
   - Otherwise (scalar / object), create a child element via
     `createChildElement` with the scalar / object data.

The method MUST NOT throw on invalid input — element-creation failures are
swallowed by the helpers.

#### Scenario: @attributes become element attributes

- **GIVEN** data `['@attributes' => ['id' => 1, 'href' => '/foo'], 'name' => 'bar']`
- **WHEN** the walk runs on a `<thing>` element
- **THEN** the output is `<thing id="1" href="/foo"><name>bar</name></thing>`

#### Scenario: indexed array of arrays fans out sibling elements

- **GIVEN** data `['item' => [['n' => 1], ['n' => 2], ['n' => 3]]]`
- **WHEN** the walk runs
- **THEN** the output contains `<item><n>1</n></item><item><n>2</n></item><item><n>3</n></item>`

#### Scenario: numeric key is rewritten to itemN

- **GIVEN** data `['0' => 'first', '1' => 'second']`
- **WHEN** the walk runs
- **THEN** the output contains `<item0>first</item0><item1>second</item1>`

#### Notes

- Keys with other XML-invalid characters (`:`, `<`, spaces) are NOT
  sanitised — they will trigger a `DOMException` in
  `DOMDocument::createElement`. Documented in design.md.

---

### Requirement: Child-element creation with object-to-string fallback and safe text node (REQ-005)

`createChildElement(DOMDocument $dom, DOMElement $parent, string $tagName, $data): void` MUST attempt to create a `<$tagName>` element. If creation
fails it MUST return without mutation. Otherwise it MUST append the new
element to `$parent` and then:

- If `$data` is an array, recurse via `buildXmlElement` (REQ-004).
- If `$data` is an object that implements `__toString` AND is NOT an
  `IQueryBuilder` instance, coerce to string.
- If `$data` is any other object (no `__toString` or `IQueryBuilder`),
  replace with the literal `'[Object of class ' . get_class($data) . ']'`.
- Append a safe text node with the resulting string via
  `createSafeTextNode`.

`createSafeTextNode(DOMDocument $dom, string $text): \DOMNode` MUST decode
HTML entities **twice** via `html_entity_decode($text, ENT_QUOTES |
ENT_HTML5, 'UTF-8')` (first pass) then `html_entity_decode($decoded, ...)`
(second pass) before returning `$dom->createTextNode($decoded)`. The
DOM-side text-node creation will re-escape `<`, `>`, `&` on serialisation
to ensure well-formed XML output.

#### Scenario: array data recurses

- **GIVEN** child data `['inner' => 'value']`
- **WHEN** `createChildElement($dom, $parent, 'outer', $data)` runs
- **THEN** the output contains `<outer><inner>value</inner></outer>`

#### Scenario: object without __toString becomes placeholder

- **GIVEN** child data `new \stdClass()` (no `__toString`)
- **WHEN** `createChildElement(...)` runs
- **THEN** the appended text is `[Object of class stdClass]`

#### Scenario: IQueryBuilder is always replaced with placeholder

- **GIVEN** child data is an `IQueryBuilder` instance (which DOES implement `__toString`)
- **WHEN** `createChildElement(...)` runs
- **THEN** the appended text is `[Object of class <IQueryBuilder impl>]` NOT the SQL fragment

#### Scenario: double-entity-decode unwraps escaped HTML

- **GIVEN** text `'&amp;lt;b&amp;gt;hi&amp;lt;/b&amp;gt;'`
- **WHEN** `createSafeTextNode(...)` runs
- **THEN** the underlying text-node value is `'<b>hi</b>'` (DOM serialisation re-escapes to `&lt;b&gt;hi&lt;/b&gt;`)

#### Notes

- The double-decode behaviour is **observed**, not aspirational. The
  `createSafe…` naming oversells the safety property — see design.md.
  The on-the-wire XML remains well-formed because DOM-side serialisation
  re-escapes, but a downstream consumer that decodes XML and reuses the
  text in an HTML context could see attacker-controlled markup.
- The `IQueryBuilder` carve-out exists because a `QueryBuilder::__toString`
  emits a parametrised SQL fragment with placeholders — embedding that in
  the response would be a data-leak. The carve-out is hard-coded; other
  sensitive `__toString`-bearing types would slip through.


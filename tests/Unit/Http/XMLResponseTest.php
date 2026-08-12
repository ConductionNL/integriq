<?php

/**
 * Unit tests for OCA\OpenConnector\Http\XMLResponse.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Http
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Http;

use OCA\OpenConnector\Http\XMLResponse;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Covers all thirteen scenarios of openspec/specs/xml-response/spec.md.
 *
 * WHY THIS FILE EXISTS WHEN tests/Http/XMLResponseTest.php ALREADY DID
 * -------------------------------------------------------------------
 * It did not test this class. That file declares its OWN `MockResponse` and
 * its OWN `XMLResponse extends MockResponse` — a hand-written COPY of the
 * production class — and asserts against the copy, so the real
 * `OCA\OpenConnector\Http\XMLResponse` was never loaded by it. It also sits in
 * `tests/Http/`, which appears in no `<testsuite>` in either phpunit.xml or
 * phpunit-unit.xml (both list `tests/Unit` and `tests/Integration` only), so it
 * has never executed in CI either. Two independent reasons why the spec's
 * "covered by PHPUnit" waiver was false.
 *
 * Everything here drives the PUBLIC surface — `render()` and `arrayToXml()` —
 * so the private helpers (`buildXmlElement`, `createChildElement`,
 * `createSafeTextNode`) are exercised through the path production code uses,
 * rather than being poked directly through reflection. `getData()` is
 * `protected` and has no public route, so that ONE scenario uses reflection.
 *
 * NOTE ON THE CONSTRUCTOR'S `$path` ARGUMENT: passing a path ending in `.xml`
 * makes the constructor call `Response::getHeaders()`, which resolves an
 * IRequest from a live `\OC::$server` and is therefore unavailable in a bare
 * unit-test environment. No test here takes that branch; it is the reason the
 * Content-Disposition behaviour is not asserted in this file.
 */
class XMLResponseTest extends TestCase {

	/**
	 * Read the protected `getData()` return value.
	 *
	 * @param XMLResponse $response The response to inspect.
	 *
	 * @return array<string, mixed>
	 */
	private function callGetData(XMLResponse $response): array {
		$method = new \ReflectionMethod(XMLResponse::class, 'getData');
		$method->setAccessible(true);

		return $method->invoke($response);
	}//end callGetData()

	// -----------------------------------------------------------------------
	// REQ-001 — data accessor and render-callback override
	// -----------------------------------------------------------------------

	/**
	 * Scenario: getData wraps the stored payload.
	 *
	 * THEN the result is `['value' => ['foo' => 'bar']]`.
	 *
	 * @return void
	 */
	public function testGetDataWrapsTheStoredPayload(): void {
		$response = new XMLResponse(['foo' => 'bar']);

		$this->assertSame(['value' => ['foo' => 'bar']], $this->callGetData($response));

	}//end testGetDataWrapsTheStoredPayload()

	/**
	 * A string payload is wrapped as `['content' => $string]` before storage,
	 * so `getData()` returns it nested twice. This is the shape the render
	 * callback receives, so it is part of the contract rather than an
	 * implementation detail.
	 *
	 * @return void
	 */
	public function testGetDataWrapsAStringPayloadUnderContent(): void {
		$response = new XMLResponse('plain text');

		$this->assertSame(['value' => ['content' => 'plain text']], $this->callGetData($response));

	}//end testGetDataWrapsAStringPayloadUnderContent()

	/**
	 * Scenario: setRenderCallback returns self for chaining.
	 *
	 * THEN the return value is the same XMLResponse instance.
	 *
	 * @return void
	 */
	public function testSetRenderCallbackReturnsSelfForChaining(): void {
		$response = new XMLResponse(['a' => 1]);

		$this->assertSame($response, $response->setRenderCallback(static fn (array $d): string => '<a/>'));

	}//end testSetRenderCallbackReturnsSelfForChaining()

	/**
	 * The callback REPLACES the whole DOM path — `render()` returns its result
	 * verbatim, and hands it the `getData()` shape.
	 *
	 * Asserting the argument matters: a callback invoked with the raw payload
	 * instead of the wrapped one would still "return a string", and every
	 * shape-only assertion would pass.
	 *
	 * @return void
	 */
	public function testRenderCallbackReplacesTheDomPathAndReceivesTheWrappedData(): void {
		$seen = null;
		$response = new XMLResponse(['foo' => 'bar']);
		$response->setRenderCallback(
			function (array $data) use (&$seen): string {
				$seen = $data;
				return '<custom/>';
			}
		);

		$this->assertSame('<custom/>', $response->render());
		$this->assertSame(['value' => ['foo' => 'bar']], $seen);

	}//end testRenderCallbackReplacesTheDomPathAndReceivesTheWrappedData()

	// -----------------------------------------------------------------------
	// REQ-002 — top-level render with @root unwrap
	// -----------------------------------------------------------------------

	/**
	 * Scenario: @root convention drives the document element name.
	 *
	 * THEN the output XML begins `<envelope>` and contains `<body>hello</body>`.
	 *
	 * @return void
	 */
	public function testRootConventionDrivesTheDocumentElementName(): void {
		$xml = (new XMLResponse(['@root' => 'envelope', 'body' => 'hello']))->render();

		$this->assertStringContainsString('<envelope>', $xml);
		$this->assertStringContainsString('<body>hello</body>', $xml);
		$this->assertStringNotContainsString('<@root>', $xml);
		$this->assertStringNotContainsString('<root>', $xml);

	}//end testRootConventionDrivesTheDocumentElementName()

	/**
	 * Scenario: no @root falls back to <response><value>...</value></response>.
	 *
	 * @return void
	 */
	public function testNoRootFallsBackToResponseValueWrapper(): void {
		$xml = (new XMLResponse(['foo' => 'bar']))->render();

		$this->assertStringContainsString('<response>', $xml);
		$this->assertMatchesRegularExpression('/<value>\s*<foo>bar<\/foo>\s*<\/value>/', $xml);

	}//end testNoRootFallsBackToResponseValueWrapper()

	// -----------------------------------------------------------------------
	// REQ-003 — array-to-XML serialisation
	// -----------------------------------------------------------------------

	/**
	 * Scenario: explicit rootTag overrides @root.
	 *
	 * THEN the output document element is `<used>` not `<ignored>`.
	 *
	 * @return void
	 */
	public function testExplicitRootTagOverridesRootKey(): void {
		$xml = (new XMLResponse())->arrayToXml(['@root' => 'ignored', 'a' => 1], 'used');

		$this->assertStringContainsString('<used>', $xml);
		$this->assertStringNotContainsString('ignored', $xml);
		$this->assertStringContainsString('<a>1</a>', $xml);

	}//end testExplicitRootTagOverridesRootKey()

	/**
	 * Scenario: CR normalisation rewrites &#13; to &#xD;.
	 *
	 * THEN the output contains `&#xD;` but no `&#13;`.
	 *
	 * Downstream parsers that only recognise the hex form silently lose the
	 * carriage return otherwise, and a payload round-tripped through this
	 * response would differ from the one that went in.
	 *
	 * @return void
	 */
	public function testCarriageReturnIsNormalisedToTheHexEntity(): void {
		$xml = (new XMLResponse())->arrayToXml(['note' => "line1\r\nline2"]);

		$this->assertStringContainsString('&#xD;', $xml);
		$this->assertStringNotContainsString('&#13;', $xml);

	}//end testCarriageReturnIsNormalisedToTheHexEntity()

	// -----------------------------------------------------------------------
	// REQ-004 — recursive DOM walk
	// -----------------------------------------------------------------------

	/**
	 * Scenario: @attributes become element attributes.
	 *
	 * THEN the output is `<thing id="1" href="/foo"><name>bar</name></thing>`.
	 *
	 * @return void
	 */
	public function testAttributesBecomeElementAttributes(): void {
		$xml = (new XMLResponse())->arrayToXml(
			['@attributes' => ['id' => 1, 'href' => '/foo'], 'name' => 'bar'],
			'thing'
		);

		$this->assertStringContainsString('<thing id="1" href="/foo">', $xml);
		$this->assertStringContainsString('<name>bar</name>', $xml);
		// The marker key must not survive as a child element.
		$this->assertStringNotContainsString('attributes>', $xml);

	}//end testAttributesBecomeElementAttributes()

	/**
	 * A `#text` key becomes the element's text content, not a `<text>` child.
	 *
	 * @return void
	 */
	public function testTextKeyBecomesTheElementTextContent(): void {
		$xml = (new XMLResponse())->arrayToXml(['#text' => 'inline'], 'thing');

		$this->assertStringContainsString('inline', $xml);
		$this->assertStringNotContainsString('<#text>', $xml);
		$this->assertStringNotContainsString('<text>', $xml);

	}//end testTextKeyBecomesTheElementTextContent()

	/**
	 * Scenario: indexed array of arrays fans out sibling elements.
	 *
	 * THEN the output contains
	 * `<item><n>1</n></item><item><n>2</n></item><item><n>3</n></item>`.
	 *
	 * @return void
	 */
	public function testIndexedArrayOfArraysFansOutSiblingElements(): void {
		$xml = (new XMLResponse())->arrayToXml(
			['item' => [['n' => 1], ['n' => 2], ['n' => 3]]],
			'list'
		);

		$this->assertSame(3, substr_count($xml, '<item>'), 'one <item> per entry');
		foreach (['1', '2', '3'] as $n) {
			$this->assertStringContainsString('<n>' . $n . '</n>', $xml);
		}

	}//end testIndexedArrayOfArraysFansOutSiblingElements()

	/**
	 * Scenario: numeric key is rewritten to itemN.
	 *
	 * THEN the output contains `<item0>first</item0><item1>second</item1>`.
	 *
	 * A bare `<0>` is not a well-formed XML element name, so without the
	 * rewrite the document would be unparseable by any consumer.
	 *
	 * @return void
	 */
	public function testNumericKeyIsRewrittenToItemN(): void {
		$xml = (new XMLResponse())->arrayToXml(['0' => 'first', '1' => 'second'], 'list');

		$this->assertStringContainsString('<item0>first</item0>', $xml);
		$this->assertStringContainsString('<item1>second</item1>', $xml);
		// The document must actually parse.
		$this->assertNotFalse(simplexml_load_string($xml), 'rewritten output must be well-formed XML');

	}//end testNumericKeyIsRewrittenToItemN()

	// -----------------------------------------------------------------------
	// REQ-005 — child-element creation and safe text nodes
	// -----------------------------------------------------------------------

	/**
	 * Scenario: array data recurses.
	 *
	 * THEN the output contains `<outer><inner>value</inner></outer>`.
	 *
	 * @return void
	 */
	public function testArrayDataRecurses(): void {
		$xml = (new XMLResponse())->arrayToXml(['outer' => ['inner' => 'value']], 'doc');

		$this->assertMatchesRegularExpression('/<outer>\s*<inner>value<\/inner>\s*<\/outer>/', $xml);

	}//end testArrayDataRecurses()

	/**
	 * Scenario: object without __toString becomes placeholder.
	 *
	 * THEN the appended text is `[Object of class stdClass]`.
	 *
	 * @return void
	 */
	public function testObjectWithoutToStringBecomesPlaceholder(): void {
		$xml = (new XMLResponse())->arrayToXml(['thing' => new \stdClass()], 'doc');

		$this->assertStringContainsString('[Object of class stdClass]', $xml);

	}//end testObjectWithoutToStringBecomesPlaceholder()

	/**
	 * An object that DOES implement __toString is coerced normally — this is
	 * the control for the IQueryBuilder carve-out below. Without it, a test
	 * asserting "IQueryBuilder is replaced" would also pass against an
	 * implementation that replaced EVERY object, which is a different
	 * behaviour.
	 *
	 * @return void
	 */
	public function testObjectWithToStringIsCoercedToItsStringValue(): void {
		$stringable = new class {
			public function __toString(): string {
				return 'coerced-value';
			}
		};

		$xml = (new XMLResponse())->arrayToXml(['thing' => $stringable], 'doc');

		$this->assertStringContainsString('coerced-value', $xml);
		$this->assertStringNotContainsString('[Object of class', $xml);

	}//end testObjectWithToStringIsCoercedToItsStringValue()

	/**
	 * Scenario: IQueryBuilder is always replaced with placeholder.
	 *
	 * THEN the appended text is the placeholder, NOT the SQL fragment.
	 *
	 * THIS IS THE SECURITY-RELEVANT ONE. `QueryBuilder::__toString()` renders
	 * the SQL it has built. A response that serialised a leaked builder object
	 * would put the query — table names, column names, and any inlined literal
	 * — on the wire to whoever called the endpoint. The carve-out is what stops
	 * that, and it must hold precisely BECAUSE the object is stringable.
	 *
	 * @return void
	 */
	public function testQueryBuilderIsReplacedWithAPlaceholderAndNeverLeaksSql(): void {
		// THE DOUBLE MUST BE STRINGABLE OR THIS TEST PASSES FOR THE WRONG
		// REASON. The production check is
		//
		//   method_exists($data, '__toString') && !($data instanceof IQueryBuilder)
		//
		// so a plain `createMock(IQueryBuilder::class)` — which has NO
		// `__toString`, because the interface does not declare one — reaches
		// the placeholder through the "not stringable" fallback instead of
		// through the carve-out. It would go green against an implementation
		// that had no carve-out at all.
		//
		// Whether the interface declares `__toString` differs between the
		// pinned vendor/nextcloud/ocp and the real Nextcloud the CI suite runs
		// inside, so the double is built whichever way that environment allows
		// rather than assuming one of them.
		$sql = 'SELECT secret_column FROM oc_secrets';
		if (method_exists(IQueryBuilder::class, '__toString') === true) {
			$builder = $this->createMock(IQueryBuilder::class);
		} else {
			$builder = $this->getMockBuilder(IQueryBuilder::class)
				->addMethods(['__toString'])
				->getMockForAbstractClass();
		}

		$builder->method('__toString')->willReturn($sql);

		// The precondition, asserted rather than assumed.
		$this->assertTrue(
			method_exists($builder, '__toString'),
			'the double must be stringable, or this test proves nothing about the IQueryBuilder carve-out'
		);
		$this->assertInstanceOf(IQueryBuilder::class, $builder);
		$this->assertSame($sql, (string)$builder, 'the double really does render SQL when cast');

		$xml = (new XMLResponse())->arrayToXml(['query' => $builder], 'doc');

		$this->assertStringContainsString('[Object of class ', $xml);
		$this->assertStringNotContainsString('SELECT', $xml);
		$this->assertStringNotContainsString('oc_secrets', $xml);
		$this->assertStringNotContainsString('secret_column', $xml);

	}//end testQueryBuilderIsReplacedWithAPlaceholderAndNeverLeaksSql()

	/**
	 * Scenario: double-entity-decode unwraps escaped HTML.
	 *
	 * GIVEN text `'&amp;lt;b&amp;gt;hi&amp;lt;/b&amp;gt;'`
	 * THEN the underlying text-node value is `'<b>hi</b>'` (DOM serialisation
	 * re-escapes to `&lt;b&gt;hi&lt;/b&gt;`).
	 *
	 * This is OBSERVED behaviour, not aspirational, and the spec says so: the
	 * `createSafe…` naming oversells the safety property. Two decode passes
	 * turn a doubly-escaped payload back into live markup at the text-node
	 * level. The wire format stays well-formed because DOM re-escapes on
	 * serialisation, so the assertion here is exactly that pair of facts — a
	 * consumer that decodes the XML and reuses the text in an HTML context
	 * gets attacker-controlled markup, and this test is what makes that
	 * property visible rather than incidental.
	 *
	 * @return void
	 */
	public function testDoubleEntityDecodeUnwrapsEscapedHtml(): void {
		$xml = (new XMLResponse())->arrayToXml(
			['note' => '&amp;lt;b&amp;gt;hi&amp;lt;/b&amp;gt;'],
			'doc'
		);

		// On the wire the document is well-formed: the markup is escaped.
		$this->assertStringContainsString('&lt;b&gt;hi&lt;/b&gt;', $xml);

		// But once parsed, the text node holds live markup — the double decode.
		$parsed = simplexml_load_string($xml);
		$this->assertNotFalse($parsed, 'output must be well-formed XML');
		$this->assertSame('<b>hi</b>', (string)$parsed->note);

	}//end testDoubleEntityDecodeUnwrapsEscapedHtml()

	/**
	 * The default Content-Type is always set by the constructor.
	 *
	 * Read via reflection because `Response::getHeaders()` merges in headers
	 * derived from a live `\OC::$server`, which does not exist here.
	 *
	 * @return void
	 */
	public function testConstructorSetsTheXmlContentType(): void {
		$response = new XMLResponse(['a' => 1]);

		$property = new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers');
		$property->setAccessible(true);
		$headers = $property->getValue($response);

		$this->assertSame('application/xml; charset=utf-8', $headers['Content-Type']);

	}//end testConstructorSetsTheXmlContentType()

}//end class

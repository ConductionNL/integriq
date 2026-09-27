<?php

/**
 * Unit tests for the SLO JSONTag reader.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Adapters\Slo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-jsontag-responses-are-read-into-linked-arrays-req-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Adapters\Slo;

use OCA\Integriq\Adapters\Slo\JsonTagReader;
use OCA\Integriq\Adapters\Slo\SloCurriculumClientMock;
use OCA\Integriq\Exception\SloCurriculumException;
use PHPUnit\Framework\TestCase;

/**
 * The reader keeps type and id, turns links into references, copies strings verbatim.
 */
class JsonTagReaderTest extends TestCase {
	/**
	 * @return void
	 */
	public function testAnnotatedObjectKeepsTypeAndId(): void {
		$decoded = (new JsonTagReader())->decode('<object class="Niveau" id="/uuid/abc">{"title":"po"}');

		$this->assertSame(['@type' => 'Niveau', '@id' => '/uuid/abc', 'title' => 'po'], $decoded);
	}//end testAnnotatedObjectKeepsTypeAndId()

	/**
	 * @return void
	 */
	public function testLinkBecomesAReferenceThatResolves(): void {
		$reader = new JsonTagReader();
		$decoded = $reader->decode(
			'{"Niveau":[<object class="Niveau" id="/uuid/abc">{"title":"po"},<link>"/uuid/abc"]}'
		);

		$this->assertSame(['@link' => '/uuid/abc'], $decoded['Niveau'][1]);

		$index = $reader->indexById($decoded);
		$this->assertSame('po', $reader->resolve($decoded['Niveau'][1], $index)['title']);
		// The bare uuid resolves too.
		$this->assertSame('po', $reader->resolve(['@link' => 'abc'], $index)['title']);
	}//end testLinkBecomesAReferenceThatResolves()

	/**
	 * @return void
	 */
	public function testStringsAreCopiedVerbatimIncludingAngleBrackets(): void {
		$decoded = (new JsonTagReader())->decode('{"title":"a <object class=\"X\"> \"b\" \\\\ c"}');

		$this->assertSame('a <object class="X"> "b" \\ c', $decoded['title']);
	}//end testStringsAreCopiedVerbatimIncludingAngleBrackets()

	/**
	 * @return void
	 */
	public function testEmptyAnnotatedObjectAndOtherAnnotations(): void {
		$decoded = (new JsonTagReader())->decode(
			'{"e": <object class="X" id="/uuid/e">{ }, "d": <date>"2026-01-01", "n": 1.5, "b": false, "z": null}'
		);

		$this->assertSame(['@type' => 'X', '@id' => '/uuid/e'], $decoded['e']);
		$this->assertSame('2026-01-01', $decoded['d']);
		$this->assertSame(1.5, $decoded['n']);
		$this->assertFalse($decoded['b']);
		$this->assertNull($decoded['z']);
	}//end testEmptyAnnotatedObjectAndOtherAnnotations()

	/**
	 * @return void
	 */
	public function testPlainJsonIsValidJsonTag(): void {
		$this->assertSame([1, ['a' => [2, 3]], 'x'], (new JsonTagReader())->decode('[1, {"a": [2,3]}, "x"]'));
	}//end testPlainJsonIsValidJsonTag()

	/**
	 * @return void
	 */
	public function testUnresolvableLinkStaysAReference(): void {
		$reader = new JsonTagReader();

		$this->assertSame(['@link' => '/uuid/missing'], $reader->resolve(['@link' => '/uuid/missing'], []));
		$this->assertSame('plain', $reader->resolve('plain', []));
	}//end testUnresolvableLinkStaysAReference()

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function malformedProvider(): array {
		return [
			'object not closed' => ['<object class="X">{"a": 1'],
			'string not closed' => ['{"a": "open}'],
			'annotation not closed' => ['{"a": <link "x"}'],
		];
	}//end malformedProvider()

	/**
	 * @dataProvider malformedProvider
	 *
	 * @param string $body A malformed body.
	 *
	 * @return void
	 */
	public function testMalformedInputThrows(string $body): void {
		$this->expectException(SloCurriculumException::class);

		(new JsonTagReader())->decode($body);
	}//end testMalformedInputThrows()

	/**
	 * Every recorded body in the fixture parses.
	 *
	 * @return void
	 */
	public function testEveryRecordedBodyParses(): void {
		$reader = new JsonTagReader();
		$mock = new SloCurriculumClientMock();

		foreach ($mock->recordedKeys() as $key) {
			[$path, $queryString] = array_pad(explode('?', $key, 2), 2, '');
			parse_str($queryString, $query);
			$decoded = $reader->decode($mock->fetch($path, $query));
			$this->assertIsArray($decoded, $key);
		}

		$this->assertGreaterThanOrEqual(30, count($mock->recordedKeys()));
	}//end testEveryRecordedBodyParses()

	/**
	 * @return void
	 */
	public function testLastSegment(): void {
		$this->assertSame('abc', (new JsonTagReader())->lastSegment('/uuid/abc'));
		$this->assertSame('abc', (new JsonTagReader())->lastSegment('abc'));
	}//end testLastSegment()
}//end class

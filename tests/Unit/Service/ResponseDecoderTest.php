<?php

/**
 * Unit tests for ResponseDecoder.
 *
 * Runs against real publiccode.yml files (Amsterdam's signals-frontend at
 * v0.2 and OpenCatalogi at v0.4, fetched from raw.githubusercontent.com on
 * 2026-10-01), a real GitHub contents API answer, and a malformed file.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Exception\ResponseDecodeException;
use OCA\Integriq\Service\ResponseDecoder;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/sources-github-publiccode/specs/github-publiccode-source/spec.md#requirement-a-step-decodes-a-yaml-or-base64-response-req-ghp-002
 */
class ResponseDecoderTest extends TestCase {

	/**
	 * The decoder under test.
	 *
	 * @var ResponseDecoder
	 */
	private ResponseDecoder $decoder;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->decoder = new ResponseDecoder();
	}//end setUp()

	/**
	 * A real v0.2 file parses, with its nested description intact.
	 *
	 * @return void
	 */
	public function testParsesARealV02File(): void {
		$parsed = $this->decoder->decode(body: $this->fixture(name: 'publiccode-v0.2-amsterdam-signals-frontend.yml'), mode: 'yaml');

		$this->assertSame('0.2', $parsed['publiccodeYmlVersion']);
		$this->assertSame('Signalen frontend', $parsed['name']);
		$this->assertSame('2021-05-20', $parsed['releaseDate']);
		$this->assertContains('Gemeente Amsterdam', $parsed['usedBy']);
		$this->assertStringStartsWith('Signalen is an open source', $parsed['description']['en']['shortDescription']);

	}//end testParsesARealV02File()

	/**
	 * A real v0.4 file parses.
	 *
	 * @return void
	 */
	public function testParsesARealV04File(): void {
		$parsed = $this->decoder->decode(body: $this->fixture(name: 'publiccode-v0.4-conductionnl-opencatalogi.yml'), mode: 'yaml');

		$this->assertSame('0.4', $parsed['publiccodeYmlVersion']);
		$this->assertSame('OpenCatalogi', $parsed['name']);
		$this->assertSame('2026-05-26', $parsed['releaseDate']);

	}//end testParsesARealV04File()

	/**
	 * A malformed file throws with the parser's line, never returns an empty array.
	 *
	 * @return void
	 */
	public function testMalformedFileThrowsWithTheLine(): void {
		try {
			$this->decoder->decode(body: $this->fixture(name: 'publiccode-malformed.yml'), mode: 'yaml');
			$this->fail('A malformed file must not decode.');
		} catch (ResponseDecodeException $exception) {
			$this->assertSame('yaml', $exception->getMode());
			$this->assertStringContainsString('line 7', $exception->getReason());
		}

	}//end testMalformedFileThrowsWithTheLine()

	/**
	 * Unquoted dates come back as strings, not timestamps or objects.
	 *
	 * Without care Symfony returns an integer for `2024-01-31`, which a
	 * mapping onto a date field would store as 1706659200.
	 *
	 * @return void
	 */
	public function testUnquotedDatesStayStrings(): void {
		$parsed = $this->decoder->decode(body: "releaseDate: 2024-01-31\nchecked: 2024-01-31T10:15:00+02:00\n", mode: 'yaml');

		$this->assertSame('2024-01-31', $parsed['releaseDate']);
		$this->assertSame('2024-01-31T10:15:00+02:00', $parsed['checked']);

	}//end testUnquotedDatesStayStrings()

	/**
	 * Tags that build PHP objects, constants or enums are refused, not dropped.
	 *
	 * @return void
	 */
	public function testPhpTagsAreRefused(): void {
		$refused = 0;
		foreach (['a: !php/object O:8:"stdClass":0:{}', 'a: !php/const PHP_INT_MAX', 'a: !php/enum Foo::Bar', 'a: !custom x'] as $yaml) {
			try {
				$this->decoder->decode(body: $yaml, mode: 'yaml');
			} catch (ResponseDecodeException $exception) {
				$refused++;
			}
		}

		$this->assertSame(4, $refused);

	}//end testPhpTagsAreRefused()

	/**
	 * A contents API answer decodes to the file, whitespace in the base64 and all.
	 *
	 * @return void
	 */
	public function testBase64YamlReadsTheContentsEnvelope(): void {
		$parsed = $this->decoder->decode(body: $this->fixture(name: 'github-contents-v0.4-opencatalogi.json'), mode: 'base64+yaml');

		$this->assertSame('OpenCatalogi', $parsed['name']);
		$this->assertArrayNotHasKey('sha', $parsed);

	}//end testBase64YamlReadsTheContentsEnvelope()

	/**
	 * A bare base64 body decodes too.
	 *
	 * @return void
	 */
	public function testBase64ReadsABareBody(): void {
		$this->assertSame(['a' => 1], $this->decoder->decode(body: base64_encode('a: 1'), mode: 'base64+yaml'));
		$this->assertSame(['a' => 1], $this->decoder->decode(body: base64_encode('{"a":1}'), mode: 'base64+json'));

	}//end testBase64ReadsABareBody()

	/**
	 * Base64 that is not base64, and an envelope without content, are refused.
	 *
	 * @return void
	 */
	public function testBadBase64IsRefused(): void {
		$cases = [
			'%%% not base64 %%%',
			'{"name":"publiccode.yml"}',
			'{"content":"YTogMQ==","encoding":"utf-8"}',
		];
		$refused = 0;
		foreach ($cases as $body) {
			try {
				$this->decoder->decode(body: $body, mode: 'base64+yaml');
			} catch (ResponseDecodeException $exception) {
				$refused++;
			}
		}

		$this->assertSame(count($cases), $refused);

	}//end testBadBase64IsRefused()

	/**
	 * An empty body in a named mode is a failure, not an empty document.
	 *
	 * @return void
	 */
	public function testEmptyBodyIsRefusedInANamedMode(): void {
		$this->expectException(ResponseDecodeException::class);
		$this->expectExceptionMessage('the body is empty');

		$this->decoder->decode(body: "  \n", mode: 'yaml');

	}//end testEmptyBodyIsRefusedInANamedMode()

	/**
	 * A body over the size limit is refused before it is parsed.
	 *
	 * @return void
	 */
	public function testOversizedBodyIsRefused(): void {
		$this->expectException(ResponseDecodeException::class);
		$this->expectExceptionMessageMatches('/over the limit/');

		$this->decoder->decode(body: str_repeat('a', (ResponseDecoder::MAX_BYTES + 1)), mode: 'yaml');

	}//end testOversizedBodyIsRefused()

	/**
	 * Auto keeps today's behaviour and only reads YAML when the server says YAML.
	 *
	 * @return void
	 */
	public function testAutoReadsJsonAndAnnouncedYamlOnly(): void {
		$this->assertSame(['a' => 1], $this->decoder->decode(body: '{"a":1}'));
		$this->assertSame('a: 1', $this->decoder->decode(body: 'a: 1', contentType: 'text/plain; charset=utf-8'));
		$this->assertSame(['a' => 1], $this->decoder->decode(body: 'a: 1', contentType: 'application/x-yaml'));
		$this->assertSame('not json', $this->decoder->decode(body: 'not json'));

	}//end testAutoReadsJsonAndAnnouncedYamlOnly()

	/**
	 * Invalid JSON in json mode is refused.
	 *
	 * @return void
	 */
	public function testInvalidJsonIsRefusedInJsonMode(): void {
		$this->expectException(ResponseDecodeException::class);

		$this->decoder->decode(body: '{"a":', mode: 'json');

	}//end testInvalidJsonIsRefusedInJsonMode()

	/**
	 * An unknown mode is refused.
	 *
	 * @return void
	 */
	public function testUnknownModeIsRefused(): void {
		$this->expectException(ResponseDecodeException::class);

		$this->decoder->decode(body: 'a: 1', mode: 'yml');

	}//end testUnknownModeIsRefused()

	/**
	 * Header lookup ignores case, as HTTP/2 servers send lower case.
	 *
	 * @return void
	 */
	public function testHeaderValueIgnoresCase(): void {
		$headers = ['x-ratelimit-remaining' => ['0'], 'Content-Type' => 'text/yaml'];

		$this->assertSame('0', ResponseDecoder::headerValue(headers: $headers, name: 'X-RateLimit-Remaining'));
		$this->assertSame('text/yaml', ResponseDecoder::headerValue(headers: $headers, name: 'content-type'));
		$this->assertNull(ResponseDecoder::headerValue(headers: $headers, name: 'Retry-After'));

	}//end testHeaderValueIgnoresCase()

	/**
	 * Read a test fixture.
	 *
	 * @param string $name The file name under tests/fixtures/publiccode.
	 *
	 * @return string The file contents.
	 */
	private function fixture(string $name): string {
		return (string)file_get_contents(__DIR__ . '/../../fixtures/publiccode/' . $name);
	}//end fixture()
}//end class

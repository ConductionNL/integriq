<?php

/**
 * Unit tests for the request body parsing and response capture of FlowToken.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Helper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Helper;

use OCA\Integriq\Service\Helper\FlowToken;
use OCA\Integriq\Util\SafeXmlParser;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * A php:// stream double that serves a fixed body for php://input.
 *
 * Registered only for the duration of one FlowToken construction.
 */
class FlowTokenInputStream {

	/**
	 * The body php://input returns.
	 *
	 * @var string
	 */
	public static string $body = '';

	/**
	 * Stream context, set by PHP.
	 *
	 * @var resource|null
	 */
	public $context;

	/**
	 * Read position.
	 *
	 * @var integer
	 */
	private int $position = 0;

	/**
	 * Open the stream.
	 *
	 * @param string      $path       The URL.
	 * @param string      $mode       The mode.
	 * @param int         $options    The options.
	 * @param string|null $openedPath The opened path.
	 *
	 * @return bool
	 */
	public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool {
		$this->position = 0;
		return $path === 'php://input';
	}//end stream_open()

	/**
	 * Read from the body.
	 *
	 * @param int $count Bytes wanted.
	 *
	 * @return string
	 */
	public function stream_read(int $count): string {
		$chunk = substr(self::$body, $this->position, $count);
		$this->position += strlen($chunk);
		return $chunk;
	}//end stream_read()

	/**
	 * Whether the body is used up.
	 *
	 * @return bool
	 */
	public function stream_eof(): bool {
		return $this->position >= strlen(self::$body);
	}//end stream_eof()

	/**
	 * Stat the stream.
	 *
	 * @return array
	 */
	public function stream_stat(): array {
		return ['size' => strlen(self::$body)];
	}//end stream_stat()
}//end class

/**
 * Tests for how a flow token turns a request body and a response into arrays.
 */
#[CoversClass(FlowToken::class)]
#[UsesClass(SafeXmlParser::class)]
class FlowTokenBodyAndResponseTest extends TestCase {

	/**
	 * Build a token from a request whose php://input holds the given body.
	 *
	 * @param string $body        The raw body.
	 * @param string $contentType The Content-Type header.
	 * @param array  $params      The request parameters.
	 *
	 * @return FlowToken
	 */
	private function tokenFor(string $body, string $contentType, array $params = []): FlowToken {
		$request = $this->createMock(IRequest::class);
		$request->method('getMethod')->willReturn('POST');
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => ($name === 'Content-Type' ? $contentType : '')
		);
		$request->method('getParams')->willReturn($params);

		$backup = $_SERVER;
		$_SERVER = ['HTTP_CONTENT_TYPE' => $contentType];
		FlowTokenInputStream::$body = $body;
		stream_wrapper_unregister('php');
		stream_wrapper_register('php', FlowTokenInputStream::class);
		try {
			return new FlowToken(requestOriginal: $request, path: '/api/in');
		} finally {
			stream_wrapper_restore('php');
			$_SERVER = $backup;
		}
	}//end tokenFor()

	/**
	 * A JSON body is decoded and merged over the request parameters.
	 *
	 * @return void
	 */
	public function testAJsonBodyIsMergedOverTheParameters(): void {
		$token = $this->tokenFor(
			body: '{"name":"Ada","nested":{"a":1}}',
			contentType: 'application/json',
			params: ['name' => 'query', 'page' => '2']
		);

		$this->assertSame(
			[
				'method' => 'POST',
				'headers' => ['content_type' => 'application/json'],
				'parameters' => ['name' => 'Ada', 'page' => '2', 'nested' => ['a' => 1]],
				'path' => '/api/in',
			],
			$token->getRequestOriginal()
		);
		$this->assertSame($token->getRequestOriginal(), $token->getRequestAmended());
	}//end testAJsonBodyIsMergedOverTheParameters()

	/**
	 * An XML body sent as application/xml is converted to an array.
	 *
	 * @return void
	 */
	public function testAnXmlBodyIsConvertedToAnArray(): void {
		$token = $this->tokenFor(
			body: '<zaak><id>Z-1</id><omschrijving>Melding</omschrijving></zaak>',
			contentType: 'application/xml'
		);

		$this->assertSame(
			['id' => 'Z-1', 'omschrijving' => 'Melding'],
			$token->getRequestOriginal()['parameters']
		);
	}//end testAnXmlBodyIsConvertedToAnArray()

	/**
	 * Without a content type, a body that parses as XML is still read as XML.
	 *
	 * @return void
	 */
	public function testAnXmlBodyWithoutAContentTypeIsDetected(): void {
		$token = $this->tokenFor(body: '<a><b>1</b></a>', contentType: '');

		$this->assertSame(['b' => '1'], $token->getRequestOriginal()['parameters']);
	}//end testAnXmlBodyWithoutAContentTypeIsDetected()

	/**
	 * Malformed XML declared as XML falls back to the request parameters.
	 *
	 * @return void
	 */
	public function testMalformedXmlFallsBackToTheParameters(): void {
		$token = $this->tokenFor(body: '<a><b>', contentType: 'text/xml', params: ['q' => 'x']);

		$this->assertSame(['q' => 'x'], $token->getRequestOriginal()['parameters']);
	}//end testMalformedXmlFallsBackToTheParameters()

	/**
	 * A plain-text body is not parsed; the parameters stand alone.
	 *
	 * @return void
	 */
	public function testAPlainBodyLeavesOnlyTheParameters(): void {
		$token = $this->tokenFor(body: 'hello', contentType: 'text/plain', params: ['q' => 'x']);

		$this->assertSame(['q' => 'x'], $token->getRequestOriginal()['parameters']);
	}//end testAPlainBodyLeavesOnlyTheParameters()

	/**
	 * A response with data is captured with its data, headers, status and cookies.
	 *
	 * @return void
	 */
	public function testAResponseWithDataIsCaptured(): void {
		$response = $this->createMock(JSONResponse::class);
		$response->method('getData')->willReturn(['ok' => true]);
		$response->method('getHeaders')->willReturn(['X-Trace' => 't-1']);
		$response->method('getStatus')->willReturn(201);
		$response->method('getCookies')->willReturn(['session' => ['value' => 'abc']]);

		$token = new FlowToken(responseOriginal: $response);

		$this->assertSame(
			[
				'data' => ['ok' => true],
				'headers' => ['X-Trace' => 't-1'],
				'status' => 201,
				'cookies' => ['session' => ['value' => 'abc']],
			],
			$token->getResponseOriginal()
		);
		$this->assertSame($token->getResponseOriginal(), $token->getResponseAmended());
	}//end testAResponseWithDataIsCaptured()

	/**
	 * A response without a data accessor is captured with empty data.
	 *
	 * @return void
	 */
	public function testAResponseWithoutDataIsCapturedWithEmptyData(): void {
		$response = $this->createMock(Response::class);
		$response->method('getHeaders')->willReturn(['Content-Type' => 'text/plain']);
		$response->method('getStatus')->willReturn(204);
		$response->method('getCookies')->willReturn([]);

		$captured = (new FlowToken())->setResponseOriginal($response);

		$this->assertSame(
			[
				'data' => [],
				'headers' => ['Content-Type' => 'text/plain'],
				'status' => 204,
				'cookies' => [],
			],
			$captured
		);
	}//end testAResponseWithoutDataIsCapturedWithEmptyData()
}//end class

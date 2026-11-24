<?php

/**
 * Unit tests for the HTTP learning material file fetcher.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Adapters\LearningMaterial
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/edurep-source/spec.md#requirement-an-open-file-of-a-record-is-fetched-for-a-copy
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Adapters\LearningMaterial;

use OCA\Integriq\Adapters\LearningMaterial\LearningMaterialRefusedException;
use OCA\Integriq\Adapters\LearningMaterial\MaterialFileFetcher;
use OCA\Integriq\Adapters\LearningMaterial\MaterialFileFetcherHttp;
use OCA\Integriq\Adapters\LearningMaterial\MaterialFileFetcherMock;
use OCA\Integriq\Adapters\LearningMaterial\SourceUnavailableException;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Https only, a timeout, a size limit, and the media type from the answer.
 */
class MaterialFileFetcherHttpTest extends TestCase {
	/**
	 * @var array<string,mixed>
	 */
	private array $options = [];

	/**
	 * @param int $status The answer's status.
	 * @param array<string,string> $headers The answer's headers.
	 * @param string $body The answer's body.
	 *
	 * @return MaterialFileFetcherHttp The fetcher.
	 */
	private function fetcher(int $status, array $headers, string $body): MaterialFileFetcherHttp {
		$response = $this->createMock(originalClassName: IResponse::class);
		$response->method('getStatusCode')->willReturn(value: $status);
		$response->method('getHeader')->willReturnCallback(callback: fn (string $key): string => ($headers[$key] ?? ''));
		$response->method('getBody')->willReturn(value: $body);
		$client = $this->createMock(originalClassName: IClient::class);
		$client->method('get')->willReturnCallback(callback: function (string $uri, array $options) use ($response): IResponse {
			$this->options = $options;
			return $response;
		});
		$service = $this->createMock(originalClassName: IClientService::class);
		$service->method('newClient')->willReturn(value: $client);

		return new MaterialFileFetcherHttp(clientService: $service);
	}//end fetcher()

	/**
	 * @return void
	 */
	public function testAnAnsweredFileComesBackWithItsMediaTypeOverHttpsOnly(): void {
		$fetcher = $this->fetcher(status: 200, headers: ['Content-Type' => 'application/pdf; charset=binary'], body: 'bytes');
		$file    = $fetcher->fetch(url: 'https://x.nl/f.pdf', format: 'application/octet-stream');

		$this->assertSame(expected: ['content' => 'bytes', 'mimeType' => 'application/pdf'], actual: $file);
		$this->assertSame(expected: ['https'], actual: $this->options['allow_redirects']['protocols']);
		$this->assertSame(expected: MaterialFileFetcherHttp::TIMEOUT_SECONDS, actual: $this->options['timeout']);
		$plain = $this->fetcher(status: 200, headers: [], body: 'x')->fetch(url: 'https://x.nl/f', format: 'text/plain');
		$this->assertSame(expected: 'text/plain', actual: $plain['mimeType']);
	}//end testAnAnsweredFileComesBackWithItsMediaTypeOverHttpsOnly()

	/**
	 * @return void
	 */
	public function testAFileOverTheLimitIsRefused(): void {
		try {
			$fetcher = $this->fetcher(status: 200, headers: ['Content-Length' => (string)(MaterialFileFetcher::MAX_BYTES + 1)], body: '');
			$fetcher->fetch(url: 'https://x.nl/f.pdf', format: '');
			$this->fail(message: 'A file over the limit must be refused.');
		} catch (LearningMaterialRefusedException $refusal) {
			$this->assertSame(expected: 'file-too-large', actual: $refusal->refusalCode);
		}
	}//end testAFileOverTheLimitIsRefused()

	/**
	 * @return void
	 */
	public function testAnUnansweredFileIsSourceUnavailable(): void {
		$this->expectException(exception: SourceUnavailableException::class);
		$this->fetcher(status: 404, headers: [], body: '')->fetch(url: 'https://x.nl/f.pdf', format: '');
	}//end testAnUnansweredFileIsSourceUnavailable()

	/**
	 * @return void
	 */
	public function testATransportErrorIsSourceUnavailable(): void {
		$client = $this->createMock(originalClassName: IClient::class);
		$client->method('get')->willThrowException(exception: new RuntimeException(message: 'timeout'));
		$service = $this->createMock(originalClassName: IClientService::class);
		$service->method('newClient')->willReturn(value: $client);

		$this->expectException(exception: SourceUnavailableException::class);
		(new MaterialFileFetcherHttp(clientService: $service))->fetch(url: 'https://x.nl/f.pdf', format: '');
	}//end testATransportErrorIsSourceUnavailable()

	/**
	 * @return void
	 */
	public function testTheMockAnswersRecordedBytesTypedAsTheRecordSays(): void {
		$mock = new MaterialFileFetcherMock();

		$docx = $mock->fetch(url: 'https://x.nl/f.docx', format: 'application/msword');

		$this->assertSame(expected: MaterialFileFetcherMock::CONTENT, actual: $docx['content']);
		$this->assertSame(expected: 'application/msword', actual: $docx['mimeType']);
		$this->assertSame(expected: 'application/pdf', actual: $mock->fetch(url: 'https://x.nl/f', format: '')['mimeType']);
	}//end testTheMockAnswersRecordedBytesTypedAsTheRecordSays()
}//end class

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
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getHeader')->willReturnCallback(fn (string $key): string => ($headers[$key] ?? ''));
		$response->method('getBody')->willReturn($body);
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(function (string $uri, array $options) use ($response): IResponse {
			$this->options = $options;
			return $response;
		});
		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($client);

		return new MaterialFileFetcherHttp(clientService: $service);
	}//end fetcher()

	/**
	 * @return void
	 */
	public function testAnAnsweredFileComesBackWithItsMediaTypeOverHttpsOnly(): void {
		$file = $this->fetcher(200, ['Content-Type' => 'application/pdf; charset=binary'], 'bytes')->fetch(url: 'https://x.nl/f.pdf', format: 'application/octet-stream');

		$this->assertSame(['content' => 'bytes', 'mimeType' => 'application/pdf'], $file);
		$this->assertSame(['https'], $this->options['allow_redirects']['protocols']);
		$this->assertSame(MaterialFileFetcherHttp::TIMEOUT_SECONDS, $this->options['timeout']);
		$this->assertSame('text/plain', $this->fetcher(200, [], 'x')->fetch(url: 'https://x.nl/f', format: 'text/plain')['mimeType']);
	}//end testAnAnsweredFileComesBackWithItsMediaTypeOverHttpsOnly()

	/**
	 * @return void
	 */
	public function testAFileOverTheLimitIsRefused(): void {
		try {
			$this->fetcher(200, ['Content-Length' => (string)(MaterialFileFetcher::MAX_BYTES + 1)], '')->fetch(url: 'https://x.nl/f.pdf', format: '');
			$this->fail('A file over the limit must be refused.');
		} catch (LearningMaterialRefusedException $refusal) {
			$this->assertSame('file-too-large', $refusal->refusalCode);
		}
	}//end testAFileOverTheLimitIsRefused()

	/**
	 * @return void
	 */
	public function testAnUnansweredFileIsSourceUnavailable(): void {
		$this->expectException(SourceUnavailableException::class);
		$this->fetcher(404, [], '')->fetch(url: 'https://x.nl/f.pdf', format: '');
	}//end testAnUnansweredFileIsSourceUnavailable()

	/**
	 * @return void
	 */
	public function testATransportErrorIsSourceUnavailable(): void {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willThrowException(new RuntimeException('timeout'));
		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($client);

		$this->expectException(SourceUnavailableException::class);
		(new MaterialFileFetcherHttp(clientService: $service))->fetch(url: 'https://x.nl/f.pdf', format: '');
	}//end testATransportErrorIsSourceUnavailable()

	/**
	 * @return void
	 */
	public function testTheMockAnswersRecordedBytesTypedAsTheRecordSays(): void {
		$mock = new MaterialFileFetcherMock();

		$this->assertSame(MaterialFileFetcherMock::CONTENT, $mock->fetch(url: 'https://x.nl/f.docx', format: 'application/msword')['content']);
		$this->assertSame('application/msword', $mock->fetch(url: 'https://x.nl/f.docx', format: 'application/msword')['mimeType']);
		$this->assertSame('application/pdf', $mock->fetch(url: 'https://x.nl/f', format: '')['mimeType']);
	}//end testTheMockAnswersRecordedBytesTypedAsTheRecordSays()
}//end class

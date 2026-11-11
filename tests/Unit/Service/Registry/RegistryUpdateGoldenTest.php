<?php

/**
 * Golden tests for RegistryUpdateClient::postUpdate.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Registry
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Registry;

use OCA\Integriq\Service\Registry\RegistryUpdateClient;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the update POST: its URL, headers with and without the stored
 * credential, the body (also when it cannot be encoded), the status it
 * returns, and the warning and 0 on a failure.
 */
class RegistryUpdateGoldenTest extends TestCase {

	/**
	 * What happened.
	 *
	 * @var array<int, array>
	 */
	private array $seen = [];

	/**
	 * Post over the given credential and answer.
	 *
	 * @param string                $credential The stored credential.
	 * @param IResponse|\Throwable $answer     The answer or failure.
	 * @param array                 $payload    The payload.
	 *
	 * @return int The status.
	 */
	private function post(string $credential, IResponse|\Throwable $answer, array $payload): int {
		$this->seen = [];
		$client     = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(
			function (string $url, array $options) use ($answer): IResponse {
				$this->seen[] = ['post', $url, $options];
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}
		);
		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($client);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://nc.example'.$path);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->with('integriq', 'registry_subscription.connector_credential', '')->willReturn($credential);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->seen[] = ['warning', $message, $context];
			}
		);

		return (new RegistryUpdateClient($service, $urls, $config, $logger))->postUpdate('brp haal', $payload);

	}//end post()

	/**
	 * Every path.
	 *
	 * @return void
	 */
	public function testEveryPath(): void {
		$ok = $this->createMock(IResponse::class);
		$ok->method('getStatusCode')->willReturn(202);
		$url = 'https://nc.example/index.php/apps/openregister/api/registry/brp%20haal/updates';

		$this->assertSame(202, $this->post('dXNlcjpwYXNz', $ok, ['bsn' => '1']));
		$this->assertSame(
			[['post', $url, ['body' => '{"bsn":"1"}', 'headers' => ['Content-Type' => 'application/json', 'OCS-APIRequest' => 'true', 'Authorization' => 'Basic dXNlcjpwYXNz'], 'timeout' => 15]]],
			$this->seen
		);

		$this->assertSame(202, $this->post('', $ok, ['bad' => "\xB1\x31"]));
		$this->assertSame(
			[['post', $url, ['body' => '{}', 'headers' => ['Content-Type' => 'application/json', 'OCS-APIRequest' => 'true'], 'timeout' => 15]]],
			$this->seen
		);

		$this->assertSame(0, $this->post('', new RuntimeException('refused'), []));
		$this->assertSame(['warning', 'registry-subscription.update.failed', ['registry' => 'brp haal', 'error' => 'refused']], $this->seen[1]);
		$this->assertSame('[]', $this->seen[0][2]['body']);

	}//end testEveryPath()
}//end class

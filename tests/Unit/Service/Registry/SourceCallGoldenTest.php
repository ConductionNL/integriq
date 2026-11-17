<?php

/**
 * Golden tests for AbstractSourceSubscriptionProvider::callSource.
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

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\ConnectionStore;
use OCA\Integriq\Service\Registry\BrpVolgindicatieProvider;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Pins every outcome of a source call: no source, a transport failure, and
 * how status and body are read from the call log.
 */
class SourceCallGoldenTest extends TestCase {

	/**
	 * Calls seen and warnings logged.
	 *
	 * @var array<int, array>
	 */
	private array $seen = [];

	/**
	 * Call the provider's callSource over the given call log data or failure.
	 *
	 * @param array|null           $data    The call log's object, or null for no source.
	 * @param \Throwable|null      $failure What the call throws.
	 * @param array<string, mixed> $config  The call configuration.
	 *
	 * @return array The outcome.
	 */
	private function call(?array $data, ?\Throwable $failure=null, array $config=[]): array {
		$this->seen = [];
		$store      = $this->createMock(ConnectionStore::class);
		$store->method('findSourceBySlug')->willReturnCallback(
			function (string $slug) use ($data, $failure) {
				$this->seen[] = ['find', $slug];
				return ($data === null && $failure === null) ? null : $this->createMock(ObjectEntity::class);
			}
		);
		$calls = $this->createMock(CallService::class);
		$calls->method('call')->willReturnCallback(
			function ($source, string $endpoint, string $method, array $config) use ($data, $failure) {
				$this->seen[] = ['call', $endpoint, $method, $config];
				if ($failure !== null) {
					throw $failure;
				}

				$log = $this->createMock(ObjectEntity::class);
				$log->method('getObject')->willReturn($data);
				return $log;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->seen[] = ['warning', $message, $context];
			}
		);

		$provider = new BrpVolgindicatieProvider($store, $calls, $logger);
		$method   = new ReflectionMethod($provider, 'callSource');
		return $method->invoke($provider, '/x', 'POST', $config);

	}//end call()

	/**
	 * No source, and a transport failure.
	 *
	 * @return void
	 */
	public function testNoSourceAndTransportFailure(): void {
		$outcome = $this->call(null);
		$this->assertSame(['status' => 0, 'body' => [], 'error' => 'No source is configured under "brp-haalcentraal".'], $outcome);
		$this->assertCount(1, $this->seen);

		$outcome = $this->call(null, new RuntimeException('refused'), ['json' => ['a' => 1]]);
		$this->assertSame(['status' => 0, 'body' => [], 'error' => 'refused'], $outcome);
		$this->assertSame(
			[
				['find', 'brp-haalcentraal'],
				['call', '/x', 'POST', ['json' => ['a' => 1]]],
				['warning', 'registry-subscription.call.failed', ['registry' => 'brp', 'endpoint' => '/x', 'error' => 'refused']],
			],
			$this->seen
		);

	}//end testNoSourceAndTransportFailure()

	/**
	 * Status and body as the call log carries them.
	 *
	 * @return void
	 */
	public function testAnswers(): void {
		$cases = [
			[['statusCode' => 201, 'response' => ['body' => '{"id":"1"}']], ['status' => 201, 'body' => ['id' => '1'], 'error' => '']],
			[['statusCode' => '299', 'response' => ['body' => ['id' => 2]]], ['status' => 299, 'body' => ['id' => 2], 'error' => '']],
			[['statusCode' => 200, 'response' => ['body' => 'not json']], ['status' => 200, 'body' => [], 'error' => '']],
			[['statusCode' => 200], ['status' => 200, 'body' => [], 'error' => '']],
			[[], ['status' => 0, 'body' => [], 'error' => 'The source answered HTTP 0.']],
			[['statusCode' => 199, 'response' => ['body' => '{}']], ['status' => 199, 'body' => [], 'error' => 'The source answered HTTP 199.']],
			[['statusCode' => 300, 'response' => ['body' => '{"title":"T","detail":"D"}']], ['status' => 300, 'body' => ['title' => 'T', 'detail' => 'D'], 'error' => 'T']],
			[['statusCode' => 404, 'response' => ['body' => '{"detail":"D","error":"E"}']], ['status' => 404, 'body' => ['detail' => 'D', 'error' => 'E'], 'error' => 'D']],
			[['statusCode' => 500, 'response' => ['body' => '{"error":"E"}']], ['status' => 500, 'body' => ['error' => 'E'], 'error' => 'E']],
		];
		foreach ($cases as [$data, $expected]) {
			$this->assertSame($expected, $this->call($data), json_encode($data));
		}

	}//end testAnswers()
}//end class

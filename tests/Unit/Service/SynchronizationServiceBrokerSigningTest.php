<?php

/**
 * The engine asks the broker signing guard before it fetches anything.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/gateway-federated-api-discovery/specs/federated-api-inventory/spec.md#requirement-each-vendor-is-read-on-a-schedule-req-fedg-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\Synchronization\BrokerSigningGuard;
use OCA\Integriq\Service\SynchronizationLogService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Drives synchronize() with the real guard over the real seeded Amazon API Gateway set.
 */
class SynchronizationServiceBrokerSigningTest extends TestCase {

	/**
	 * Synchronization logs the run wrote, as saved.
	 *
	 * @var list<array>
	 */
	private array $logs = [];

	/**
	 * Run the seeded Amazon API Gateway discovery with the broker offering the given schemes.
	 *
	 * @param list<string> $brokerSchemes The signing schemes the broker offers.
	 * @param int    $calls      By-ref count of provider calls.
	 * @param int    $writes     By-ref count of object writes.
	 *
	 * @return array|null The run's log.
	 */
	private function runAmazonDiscovery(array $brokerSchemes, int &$calls, int &$writes): ?array {
		$path     = dirname(__DIR__, 3) . '/lib/Settings/register.d/aws-apigateway-discovery.json';
		$fragment = json_decode((string)file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
		$seeds    = [];
		foreach ($fragment['components']['objects'] as $object) {
			$self = $object['@self'];
			unset($object['@self']);
			$seeds[$self['schema']][$self['slug']] = $object;
		}

		// An administrator turned the source on; the seed ships it off.
		$seeds['source']['aws-apigateway-discovery']['isEnabled'] = true;

		$synchronization = $seeds['synchronization']['aws-apigateway-discovery'];
		$synchronization['id']       = 'sync-uuid-aws-discovery';
		$synchronization['uuid']     = 'sync-uuid-aws-discovery';
		$synchronization['targetId'] = '1/2';

		$orObjects = ObjectServiceMockBuilder::make($this);
		$orObjects->method('find')->willReturnCallback(
			function ($id, $register = null, $schema = null) use ($seeds) {
				// An object found by slug comes back with uuid-<slug>; a second lookup uses that.
				$id = preg_replace('/^uuid-/', '', (string)$id);
				if (isset($seeds[$schema][$id]) === false) {
					throw new \RuntimeException('Object not found: ' . $schema . '/' . $id);
				}

				return ObjectServiceMockBuilder::objectEntity($this, $seeds[$schema][$id], 'uuid-' . $id);
			}
		);
		$orObjects->method('findAll')->willReturn(['results' => [], 'total' => 0]);
		$orObjects->method('saveObject')->willReturnCallback(
			function ($object, ?string $register = null, ?string $schema = null, ...$rest) use (&$writes) {
				if ($schema !== 'synchronization_log') {
					$writes++;
				}

				return ObjectServiceMockBuilder::objectEntity($this, (array)$object, 'saved-uuid');
			}
		);

		$callService = $this->createMock(CallService::class);
		$callService->method('applyConfigDot')->willReturnArgument(0);
		$callService->method('call')->willReturnCallback(
			function () use (&$calls) {
				$calls++;
				throw new \RuntimeException('stop after the first provider call');
			}
		);

		$guard     = new BrokerSigningGuard(brokerSchemes: $brokerSchemes);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id) => ($id === BrokerSigningGuard::class ? $guard : null)
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);

		$logOrService = ObjectServiceMockBuilder::make($this);
		$logOrService->method('saveObject')->willReturnCallback(
			function ($object, ...$rest) {
				$this->logs[] = (array)$object;
				return ObjectServiceMockBuilder::objectEntity($this, (array)$object, 'log-uuid');
			}
		);
		$logService = new SynchronizationLogService($logOrService, $this->createMock(\OCP\IUserSession::class), $this->createMock(\OCP\ISession::class));

		$service = new SynchronizationService(
			$callService,
			$this->createMock(MappingService::class),
			$container,
			$orObjects,
			$this->createMock(ObjectService::class),
			$this->createMock(LoggerInterface::class),
			$logService,
			$appConfig,
			$this->createMock(\OCA\Integriq\Service\SynchronizationApprovalGate::class)
		);

		try {
			return $service->synchronize(synchronization: $synchronization);
		} catch (\Throwable $e) {
			return ['exception' => $e->getMessage()];
		}
	}//end runAmazonDiscovery()

	/**
	 * While the broker cannot sign SigV4 the run sends no request, writes nothing, and its log says what it waits on.
	 *
	 * @return void
	 */
	public function testWithoutBrokerSigningNoRequestIsSent(): void {
		$calls  = 0;
		$writes = 0;

		$result = $this->runAmazonDiscovery(brokerSchemes: [], calls: $calls, writes: $writes);

		$this->assertSame(0, $calls, 'Amazon was called.');
		$this->assertSame(0, $writes, 'Something was written.');
		$this->assertStringContainsString('cannot sign AWS Signature Version 4 yet', (string)($result['exception'] ?? ''));
		$messages = array_column($this->logs, 'message');
		$this->assertNotEmpty(array_filter($messages, fn ($m): bool => str_contains((string)$m, 'waits on OpenRegister')), 'No saved log says what the run waits on.');
	}//end testWithoutBrokerSigningNoRequestIsSent()

	/**
	 * Once the broker offers aws-sigv4, the run reaches Amazon.
	 *
	 * @return void
	 */
	public function testWithBrokerSigningTheRunFetches(): void {
		$calls  = 0;
		$writes = 0;

		$this->runAmazonDiscovery(brokerSchemes: ['aws-sigv4'], calls: $calls, writes: $writes);

		$this->assertGreaterThan(0, $calls);
	}//end testWithBrokerSigningTheRunFetches()
}//end class

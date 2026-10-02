<?php

/**
 * The engine asks the run prerequisite guard before it fetches anything.
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
 * @spec openspec/changes/connectors-course-marketplace/specs/course-marketplace-connectors/spec.md#requirement-a-providers-catalogue-arrives-in-learniq-as-draft-courses-that-launch-through-lti-req-cmkt-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\Synchronization\RunPrerequisiteGuard;
use OCA\Integriq\Service\SynchronizationLogService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Drives synchronize() with the real guard over the real seeded Udemy Business set.
 */
class SynchronizationServiceRunPrerequisiteTest extends TestCase {

	/**
	 * Synchronization logs the run wrote, as saved.
	 *
	 * @var list<array>
	 */
	private array $logs = [];

	/**
	 * Run the seeded Udemy Business course synchronization with the placement mapping as given.
	 *
	 * @param string $deployment The placement mapping's openconnectorDeploymentId.
	 * @param int    $calls      By-ref count of provider calls.
	 * @param int    $writes     By-ref count of object writes.
	 *
	 * @return array|null The run's log.
	 */
	private function run(string $deployment, int &$calls, int &$writes): ?array {
		$path     = dirname(__DIR__, 3) . '/lib/Settings/register.d/course-marketplace-connectors.json';
		$fragment = json_decode((string)file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
		$seeds    = [];
		foreach ($fragment['components']['objects'] as $object) {
			$self = $object['@self'];
			unset($object['@self']);
			$seeds[$self['schema']][$self['slug']] = $object;
		}

		$seeds['mapping']['course-marketplace-udemy-business-placement']['mapping']['openconnectorDeploymentId'] = $deployment;

		$synchronization = $seeds['synchronization']['course-marketplace-udemy-business-course'];
		$synchronization['id']       = 'sync-uuid-udemy-course';
		$synchronization['uuid']     = 'sync-uuid-udemy-course';
		$synchronization['sourceId'] = 'source-uuid-udemy';
		$synchronization['targetId'] = '1/2';

		$orObjects = ObjectServiceMockBuilder::make($this);
		$orObjects->method('find')->willReturnCallback(
			function ($id, $register = null, $schema = null) use ($seeds) {
				if (isset($seeds[$schema][$id]) === false) {
					throw new \RuntimeException('Object not found');
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

		$guard     = new RunPrerequisiteGuard(objectService: $orObjects);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id) => ($id === RunPrerequisiteGuard::class ? $guard : null)
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
	}//end run()

	/**
	 * Without a deployment the run calls no provider, writes nothing, and its log names the deployment.
	 *
	 * @return void
	 */
	public function testWithoutADeploymentNothingIsFetchedOrWritten(): void {
		$calls  = 0;
		$writes = 0;

		$result = $this->run(deployment: '', calls: $calls, writes: $writes);

		$this->assertSame(0, $calls, 'The provider was called.');
		$this->assertSame(0, $writes, 'Something was written.');
		$this->assertStringContainsString('lti_deployment', (string)($result['exception'] ?? ''));
		$messages = array_column($this->logs, 'message');
		$this->assertNotEmpty(array_filter($messages, fn ($m): bool => str_contains((string)$m, 'lti_deployment')), 'No saved log names the deployment.');
	}//end testWithoutADeploymentNothingIsFetchedOrWritten()

	/**
	 * With the deployment set, the run reaches the provider.
	 *
	 * @return void
	 */
	public function testWithTheDeploymentSetTheRunFetches(): void {
		$calls  = 0;
		$writes = 0;

		$this->run(deployment: 'deployment-uuid-1', calls: $calls, writes: $writes);

		$this->assertGreaterThan(0, $calls);
	}//end testWithTheDeploymentSetTheRunFetches()
}//end class

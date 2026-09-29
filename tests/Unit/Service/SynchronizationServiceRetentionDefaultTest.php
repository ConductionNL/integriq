<?php

/**
 * Retention defaults of the synchronization writers.
 *
 * On an install without the `retention` app config key, synchronization run
 * logs and contract logs expired after 3 days, while the settings read told
 * the administrator 30 and 90 days (integriq#2210). These tests pin the
 * writers and the settings read to the 30 days both log schemas declare
 * (`x-openregister-archival.retention.default` P30D).
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-one-resolver-supplies-retention-with-the-schemas-defaults-req-adef-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use DateTime;
use DateTimeInterface;
use OCA\Integriq\Service\SynchronizationApprovalGate;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\Helper\FlowToken;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\SettingsService;
use OCA\Integriq\Service\SynchronizationContractLogService;
use OCA\Integriq\Service\SynchronizationLogService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The synchronization writers stamp the retention the settings read reports.
 */
class SynchronizationServiceRetentionDefaultTest extends TestCase {

	/**
	 * Thirty days in seconds.
	 */
	private const THIRTY_DAYS = 2592000;

	/**
	 * Tolerance, in seconds, for the clock moving while a test runs.
	 */
	private const SLACK = 120;

	/**
	 * An app config, with or without the `retention` key.
	 *
	 * @param string|null $retention The stored `retention` JSON, or null when the key is unset.
	 *
	 * @return IAppConfig
	 */
	private function appConfig(?string $retention): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn($retention !== null);
		$appConfig->method('getValueString')->willReturn($retention ?? '');

		return $appConfig;
	}//end appConfig()

	/**
	 * Build the service over the given log services.
	 *
	 * @param IAppConfig $appConfig The app config.
	 * @param SynchronizationLogService $logService The run-log writer.
	 * @param SynchronizationContractLogService|null $contractLogService The contract-log writer, served from the container.
	 *
	 * @return SynchronizationService
	 */
	private function service(
		IAppConfig $appConfig,
		SynchronizationLogService $logService,
		?SynchronizationContractLogService $contractLogService = null,
	): SynchronizationService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id) => ($id === SynchronizationContractLogService::class) ? $contractLogService : null
		);

		$callService = $this->createMock(CallService::class);
		$callService->method('applyConfigDot')->willReturnArgument(0);

		return new SynchronizationService(
			$callService,
			$this->createMock(MappingService::class),
			$container,
			ObjectServiceMockBuilder::make($this),
			$this->createMock(ObjectService::class),
			$this->createMock(LoggerInterface::class),
			$logService,
			$appConfig,
			$this->createMock(SynchronizationApprovalGate::class),
		);
	}//end service()

	/**
	 * A run-log writer that records the log it is handed and stops the run there.
	 *
	 * @param array|null $captured Receives the log array.
	 *
	 * @return SynchronizationLogService
	 */
	private function capturingLogService(?array &$captured): SynchronizationLogService {
		$logService = $this->createMock(SynchronizationLogService::class);
		$logService->method('createFromArray')->willReturnCallback(
			static function (array $object) use (&$captured) {
				$captured = $object;
				throw new RuntimeException('run log captured');
			}
		);

		return $logService;
	}//end capturingLogService()

	/**
	 * Run a synchronization up to the moment its run log is built.
	 *
	 * @param SynchronizationService $service The service.
	 *
	 * @return void
	 */
	private function runUntilTheLogIsBuilt(SynchronizationService $service): void {
		try {
			$service->synchronize(
				synchronization: [
					'id' => 'sync-retention',
					'sourceId' => 'source-retention',
					'sourceType' => 'api',
					'targetType' => 'register/schema',
					'targetId' => '1/2',
					'sourceConfig' => [],
				]
			);
			$this->fail('The run should have stopped at the captured run log.');
		} catch (RuntimeException $e) {
			$this->assertSame('run log captured', $e->getMessage());
		}
	}//end runUntilTheLogIsBuilt()

	/**
	 * Seconds from now until the given expiry.
	 *
	 * @param mixed $expires A DateTimeInterface or an ISO 8601 string.
	 *
	 * @return int
	 */
	private function secondsAhead(mixed $expires): int {
		if (is_string($expires) === true) {
			$expires = new DateTime($expires);
		}

		$this->assertInstanceOf(DateTimeInterface::class, $expires);

		return ($expires->getTimestamp() - time());
	}//end secondsAhead()

	/**
	 * What the settings read reports for an unset `retention` key.
	 *
	 * @return array<string,int>
	 */
	private function reportedRetention(): array {
		$settings = new SettingsService(
			$this->createMock(IDBConnection::class),
			$this->appConfig(retention: null),
			$this->createMock(LoggerInterface::class)
		);

		return $settings->getSettings()['retention'];
	}//end reportedRetention()

	/**
	 * With no `retention` key, a synchronization log expires 30 days out, the
	 * value the settings read reports. It was 3 days.
	 *
	 * @return void
	 */
	public function testSyncLogExpiresThirtyDaysOutWithoutRetentionKey(): void {
		$captured = null;
		$service = $this->service(
			appConfig: $this->appConfig(retention: null),
			logService: $this->capturingLogService($captured)
		);

		$this->runUntilTheLogIsBuilt($service);

		$ahead = $this->secondsAhead($captured['expires'] ?? null);
		$this->assertEqualsWithDelta(self::THIRTY_DAYS, $ahead, self::SLACK, 'A synchronization log must expire 30 days out, not ' . round($ahead / 86400, 1) . ' days.');
		$this->assertSame(self::THIRTY_DAYS * 1000, $this->reportedRetention()['syncLogRetention']);

	}//end testSyncLogExpiresThirtyDaysOutWithoutRetentionKey()

	/**
	 * With no `retention` key, a contract log is handed an `expires` 30 days
	 * out, the default its schema declares and the value the settings read
	 * now reports. It was handed an `expiry` key the schema does not have,
	 * 3 days out, and so got the contract log writer's own 3-day default.
	 *
	 * @return void
	 */
	public function testContractLogExpiresThirtyDaysOutWithoutRetentionKey(): void {
		$captured = null;
		$contractLogService = $this->createMock(SynchronizationContractLogService::class);
		$contractLogService->method('createFromArray')->willReturnCallback(
			static function (array $object) use (&$captured) {
				$captured = $object;
				throw new RuntimeException('contract log captured');
			}
		);

		$logService = $this->createMock(SynchronizationLogService::class);
		$service = $this->service(
			appConfig: $this->appConfig(retention: null),
			logService: $logService,
			contractLogService: $contractLogService
		);

		$flowToken = new FlowToken();
		$object = ['id' => 'origin-1'];
		try {
			$service->synchronizeContract(
				synchronizationContract: ['id' => 'contract-retention'],
				flowToken: $flowToken,
				synchronization: ['id' => 'sync-retention', 'sourceConfig' => []],
				object: $object
			);
			$this->fail('The contract run should have stopped at the captured contract log.');
		} catch (RuntimeException $e) {
			$this->assertSame('contract log captured', $e->getMessage());
		}

		$this->assertArrayHasKey('expires', (array)$captured, 'The contract log must carry the schema\'s `expires` field.');
		$ahead = $this->secondsAhead($captured['expires']);
		$this->assertEqualsWithDelta(self::THIRTY_DAYS, $ahead, self::SLACK, 'A contract log must expire 30 days out, not ' . round($ahead / 86400, 1) . ' days.');
		$this->assertSame(self::THIRTY_DAYS * 1000, $this->reportedRetention()['syncContractLogRetention']);

	}//end testContractLogExpiresThirtyDaysOutWithoutRetentionKey()

	/**
	 * A retention an administrator set through `occ`, in milliseconds, still
	 * applies unchanged.
	 *
	 * @return void
	 */
	public function testConfiguredRetentionStillApplies(): void {
		$captured = null;
		$service = $this->service(
			appConfig: $this->appConfig(retention: json_encode(['syncLogRetention' => 86400000])),
			logService: $this->capturingLogService($captured)
		);

		$this->runUntilTheLogIsBuilt($service);

		$this->assertEqualsWithDelta(86400, $this->secondsAhead($captured['expires'] ?? null), self::SLACK);

	}//end testConfiguredRetentionStillApplies()
}//end class

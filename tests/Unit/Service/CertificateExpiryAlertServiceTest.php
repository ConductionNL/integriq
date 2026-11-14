<?php

/**
 * Unit tests for CertificateExpiryAlertService.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/brp-connection-monitor-from-pipelinq/specs/connection-run-monitoring/spec.md#requirement-an-expiring-certificate-opens-an-alert-req-crun-008
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Integriq\Service\CertificateExpiryAlertService;
use OCA\Integriq\Service\ConnectionCertificateReader;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * One alert per source, threshold and certificate; renewal re-arms.
 */
#[CoversClass(CertificateExpiryAlertService::class)]
final class CertificateExpiryAlertServiceTest extends TestCase {

	private const NOW = '2026-11-13T09:00:00+01:00';

	private const SOURCE = 'b2c3d4e5-0000-4000-8000-0000000b0b0b';

	private const EXPIRES = '2026-12-12T09:00:00+01:00';

	/**
	 * In-memory objects per schema.
	 *
	 * @var array<string, array<int, ObjectEntity>>
	 */
	private array $store = [];

	/**
	 * Every save: [schema, object, uuid].
	 *
	 * @var array<int, array{0: string, 1: array, 2: ?string}>
	 */
	private array $saves = [];

	/**
	 * What the certificate reader answers per source name.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $certificates = [];

	/**
	 * Build the service over the in-memory store; saved alerts land in the store.
	 *
	 * @return CertificateExpiryAlertService
	 */
	private function makeService(): CertificateExpiryAlertService {
		$objects = $this->createMock(OrObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config): array {
				$filters = $config['filters'];
				$rows = [];
				foreach (($this->store[$filters['schema']] ?? []) as $entity) {
					$body = $entity->getObject();
					$match = true;
					foreach ($filters as $key => $value) {
						if (in_array($key, ['register', 'schema'], true) === false && ($body[$key] ?? null) !== $value) {
							$match = false;
						}
					}

					if ($match === true) {
						$rows[] = $entity;
					}
				}

				return $rows;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, $register = null, $schema = null, $uuid = null): ObjectEntity {
				$this->saves[] = [(string)$schema, $object, $uuid];
				$id = ($uuid ?? 'alert-' . count($this->saves));
				$entity = ObjectServiceMockBuilder::objectEntity($this, $object, $id);
				$kept = [];
				foreach (($this->store[(string)$schema] ?? []) as $existing) {
					if ($existing->getUuid() !== $id) {
						$kept[] = $existing;
					}
				}

				$kept[] = $entity;
				$this->store[(string)$schema] = $kept;
				return $entity;
			}
		);

		$reader = $this->createMock(ConnectionCertificateReader::class);
		$reader->method('certificate')->willReturnCallback(
			fn (array $data): array => ($this->certificates[(string)($data['name'] ?? '')] ?? ['configured' => false, 'none' => true])
		);

		return new CertificateExpiryAlertService($objects, $reader, $this->createMock(LoggerInterface::class));
	}//end makeService()

	/**
	 * A source named BRP with the given thresholds whose certificate has the given days left.
	 *
	 * @param int        $daysLeft   Days left.
	 * @param array|null $thresholds The configured certificateExpiryDays, or null for none.
	 * @param string     $expiresAt  The certificate's expiry.
	 *
	 * @return void
	 */
	private function brpSource(int $daysLeft, ?array $thresholds = null, string $expiresAt = self::EXPIRES): void {
		$source = ['name' => 'BRP', 'isEnabled' => true];
		if ($thresholds !== null) {
			$source['alertThresholds'] = ['certificateExpiryDays' => $thresholds];
		}

		$this->store['source'] = [ObjectServiceMockBuilder::objectEntity($this, $source, self::SOURCE)];
		$this->certificates['BRP'] = ['configured' => true, 'keptIn' => 'keepiq', 'expiresAt' => $expiresAt, 'daysLeft' => $daysLeft];
	}//end brpSource()

	/**
	 * The alerts saved as new.
	 *
	 * @return array<int, array>
	 */
	private function opened(): array {
		$opened = [];
		foreach ($this->saves as [$schema, $object, $uuid]) {
			if ($schema === 'connection_alert' && $uuid === null) {
				$opened[] = $object;
			}
		}

		return $opened;
	}//end opened()

	/**
	 * Twenty-nine days before expiry, two runs open one alert for the 30-day threshold.
	 *
	 * @return void
	 */
	public function testThirtyDaysBeforeExpiryTheGroupIsToldOnce(): void {
		$this->brpSource(daysLeft: 29);
		$service = $this->makeService();

		$first = $service->evaluate(new DateTimeImmutable(self::NOW));
		$second = $service->evaluate(new DateTimeImmutable(self::NOW));

		$this->assertSame(['opened' => 1, 'cleared' => 0], $first);
		$this->assertSame(['opened' => 0, 'cleared' => 0], $second);
		$alerts = $this->opened();
		$this->assertCount(1, $alerts);
		$this->assertSame('certificateExpiry', $alerts[0]['rule']);
		$this->assertSame(30, $alerts[0]['threshold']);
		$this->assertSame(29, $alerts[0]['count']);
		$this->assertSame(self::SOURCE, $alerts[0]['subject']);
		$this->assertSame('BRP', $alerts[0]['subjectName']);
		$this->assertSame(self::EXPIRES, $alerts[0]['expiresAt']);
		$this->assertSame('open', $alerts[0]['state']);
		$this->assertSame([], RegisterSchemaValidator::errors('connection_alert', $alerts[0]));
	}//end testThirtyDaysBeforeExpiryTheGroupIsToldOnce()

	/**
	 * Moving from the 30-day into the 14-day window opens the 14-day alert.
	 *
	 * @return void
	 */
	public function testEachThresholdWarnsOnce(): void {
		$this->brpSource(daysLeft: 29);
		$service = $this->makeService();
		$service->evaluate(new DateTimeImmutable(self::NOW));

		$this->certificates['BRP']['daysLeft'] = 13;
		$service->evaluate(new DateTimeImmutable(self::NOW));
		$service->evaluate(new DateTimeImmutable(self::NOW));

		$this->assertSame([30, 14], array_column($this->opened(), 'threshold'));
	}//end testEachThresholdWarnsOnce()

	/**
	 * A certificate first seen five days before expiry warns once, for the tightest threshold.
	 *
	 * @return void
	 */
	public function testFirstSeenLateWarnsOnlyForTheTightestThreshold(): void {
		$this->brpSource(daysLeft: 5);

		$this->makeService()->evaluate(new DateTimeImmutable(self::NOW));

		$this->assertSame([7], array_column($this->opened(), 'threshold'));
	}//end testFirstSeenLateWarnsOnlyForTheTightestThreshold()

	/**
	 * Outside every threshold nothing opens.
	 *
	 * @return void
	 */
	public function testFarFromExpiryNothingOpens(): void {
		$this->brpSource(daysLeft: 31);

		$outcome = $this->makeService()->evaluate(new DateTimeImmutable(self::NOW));

		$this->assertSame(['opened' => 0, 'cleared' => 0], $outcome);
		$this->assertSame([], $this->saves);
	}//end testFarFromExpiryNothingOpens()

	/**
	 * The source's own thresholds replace the defaults.
	 *
	 * @return void
	 */
	public function testConfiguredThresholdsReplaceTheDefaults(): void {
		$this->brpSource(daysLeft: 50, thresholds: [60, 'x', 0, 21]);

		$this->makeService()->evaluate(new DateTimeImmutable(self::NOW));

		$this->assertSame([60], array_column($this->opened(), 'threshold'));
		$this->assertSame([60, 21], CertificateExpiryAlertService::thresholdsOf(['alertThresholds' => ['certificateExpiryDays' => [21, 60, 60, -3]]]));
		$this->assertSame([30, 14, 7], CertificateExpiryAlertService::thresholdsOf([]));
	}//end testConfiguredThresholdsReplaceTheDefaults()

	/**
	 * A renewed certificate clears the old alert and warns again on its own thresholds.
	 *
	 * @return void
	 */
	public function testARenewedCertificateClearsTheOldAlertAndRearms(): void {
		$this->brpSource(daysLeft: 6);
		$service = $this->makeService();
		$service->evaluate(new DateTimeImmutable(self::NOW));

		$this->certificates['BRP']['expiresAt'] = '2027-01-10T09:00:00+01:00';
		$this->certificates['BRP']['daysLeft'] = 28;
		$outcome = $service->evaluate(new DateTimeImmutable(self::NOW));

		$this->assertSame(['opened' => 1, 'cleared' => 1], $outcome);
		$this->assertSame([7, 30], array_column($this->opened(), 'threshold'));
		$cleared = array_values(array_filter($this->saves, static fn (array $save): bool => $save[2] !== null));
		$this->assertSame('cleared', $cleared[0][1]['state']);
		$this->assertSame(self::EXPIRES, $cleared[0][1]['expiresAt']);
	}//end testARenewedCertificateClearsTheOldAlertAndRearms()

	/**
	 * Unknown expiry, no certificate, or a disabled source opens nothing.
	 *
	 * @return void
	 */
	public function testUnknownExpiryOrDisabledSourceOpensNothing(): void {
		$this->store['source'] = [
			ObjectServiceMockBuilder::objectEntity($this, ['name' => 'Unknown'], 'src-unknown'),
			ObjectServiceMockBuilder::objectEntity($this, ['name' => 'Plain'], 'src-plain'),
			ObjectServiceMockBuilder::objectEntity($this, ['name' => 'Off', 'isEnabled' => false], 'src-off'),
		];
		$this->certificates['Unknown'] = ['configured' => true, 'keptIn' => 'keepiq', 'expiresAt' => null, 'daysLeft' => null];
		$this->certificates['Off'] = ['configured' => true, 'expiresAt' => self::EXPIRES, 'daysLeft' => 3];

		$outcome = $this->makeService()->evaluate(new DateTimeImmutable(self::NOW));

		$this->assertSame(['opened' => 0, 'cleared' => 0], $outcome);
		$this->assertSame([], $this->saves);
	}//end testUnknownExpiryOrDisabledSourceOpensNothing()
}//end class

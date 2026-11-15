<?php

/**
 * Cross-tenant refusal tests for the service reads a user request reaches:
 * regression suites, the connection monitor, record provenance, the run
 * summary, the promotion target source and the DSO activity overview.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/integriq-tenant-scoping/specs/tenant-scoping/spec.md#requirement-a-user-only-reaches-their-own-organisations-integriq-objects-req-ten-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Integriq\Service\ConnectionCertificateReader;
use OCA\Integriq\Service\ConnectionMonitorService;
use OCA\Integriq\Service\Dso\DsoActivityMapper;
use OCA\Integriq\Service\Dso\DsoActivityTable;
use OCA\Integriq\Service\Dso\DsoUnmappedActivities;
use OCA\Integriq\Service\EnvironmentService;
use OCA\Integriq\Service\MappingServiceInterface;
use OCA\Integriq\Service\Mtls\MtlsConfigResolver;
use OCA\Integriq\Service\Portal\ActivityMappingTableInterface;
use OCA\Integriq\Service\Portal\ActivityOverview;
use OCA\Integriq\Service\RecordProvenanceService;
use OCA\Integriq\Service\RegressionSuiteService;
use OCA\Integriq\Service\RunSummaryService;
use OCA\Integriq\Tests\Helpers\ForeignOrganisation;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Another organisation's objects are not reached through these services.
 *
 * @covers \OCA\Integriq\Service\RegressionSuiteService
 * @covers \OCA\Integriq\Service\ConnectionMonitorService
 * @covers \OCA\Integriq\Service\RecordProvenanceService
 * @covers \OCA\Integriq\Service\RunSummaryService
 * @covers \OCA\Integriq\Service\EnvironmentService
 * @covers \OCA\Integriq\Service\Portal\ActivityOverview
 * @uses   \OCA\Integriq\Service\ConnectionCertificateReader
 * @uses   \OCA\Integriq\Service\Dso\DsoActivityTable
 * @uses   \OCA\Integriq\Service\Dso\DsoActivityMapper
 * @uses   \OCA\Integriq\Service\Dso\DsoUnmappedActivities
 * @uses   \OCA\Integriq\Service\Regression\OutputComparator
 */
class UserReachableReadsTenantScopingTest extends TestCase {

	/**
	 * An object as another organisation's would be stored.
	 *
	 * @param string               $uuid The uuid.
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return ObjectEntity
	 */
	private static function foreign(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);
		$entity->setOrganisation('other-organisation');

		return $entity;
	}//end foreign()

	/**
	 * A translator that formats the source string.
	 *
	 * @return IL10N&MockObject
	 */
	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text, array $params = []): string => vsprintf($text, $params));
		$l10n->method('n')->willReturnCallback(
			fn (string $one, string $many, int $count): string => str_replace('%n', (string)$count, ($count === 1 ? $one : $many))
		);

		return $l10n;
	}//end l10n()

	/**
	 * A suite run over another organisation's cases replays none of them.
	 *
	 * @return void
	 */
	public function testARegressionSuiteDoesNotReplayAnotherOrganisationsCases(): void {
		$objects = $this->createMock(ORObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			ForeignOrganisation::findAll(
				[self::foreign('case-1', ['name' => 'theirs', 'subjectType' => 'mapping', 'subjectId' => 'lowercase-keys', 'input' => ['A' => 1], 'expectedOutput' => ['a' => 1]])]
			)
		);
		$objects->method('saveObject')->willReturnCallback(
			static function ($object = []): ObjectEntity {
				$entity = new ObjectEntity();
				$entity->setUuid('run-1');
				$entity->setObject($object);
				return $entity;
			}
		);
		$mappings = $this->createMock(MappingServiceInterface::class);
		$mappings->expects($this->never())->method('executeMapping');

		$run = (new RegressionSuiteService(objectService: $objects, mappingService: $mappings))
			->run(subjectType: 'mapping', subjectId: 'lowercase-keys', userId: 'alice');

		$this->assertSame(0, $run['total']);
		$this->assertSame([], $run['failures']);
	}//end testARegressionSuiteDoesNotReplayAnotherOrganisationsCases()

	/**
	 * A case naming another organisation's synchronization fails without running its mapping.
	 *
	 * @return void
	 */
	public function testARegressionCaseDoesNotRunAnotherOrganisationsSynchronization(): void {
		$objects = $this->createMock(ORObjectService::class);
		$objects->method('find')->willReturnCallback(
			ForeignOrganisation::find(self::foreign('sync-theirs', ['sourceTargetMapping' => 'their-mapping']))
		);
		$mappings = $this->createMock(MappingServiceInterface::class);
		$mappings->expects($this->never())->method('executeMapping');

		$failure = (new RegressionSuiteService(objectService: $objects, mappingService: $mappings))->runCase(
			case: ['id' => 'case-1', 'name' => 'mine', 'subjectType' => 'synchronization', 'subjectId' => 'sync-theirs', 'input' => [], 'expectedOutput' => []]
		);

		$this->assertNotNull($failure);
		$this->assertSame('case-1', $failure['caseId']);
		$this->assertSame([], $failure['differences']);
		$this->assertSame('Object not found in the active organisation', $failure['error']);
	}//end testARegressionCaseDoesNotRunAnotherOrganisationsSynchronization()

	/**
	 * The connection monitor has no report for another organisation's source, by uuid or by slug.
	 *
	 * @return void
	 */
	public function testTheConnectionMonitorHasNoReportForAnotherOrganisationsSource(): void {
		$source = self::foreign('source-theirs', ['name' => 'Their source', 'slug' => 'theirs']);
		$objects = $this->createMock(ORObjectService::class);
		$objects->method('find')->willReturnCallback(ForeignOrganisation::find($source));
		$objects->method('findAll')->willReturnCallback(ForeignOrganisation::findAll([$source]));
		$l10n = $this->l10n();
		$service = new ConnectionMonitorService(
			$objects,
			new ConnectionCertificateReader($this->createMock(MtlsConfigResolver::class), $this->createMock(IEventDispatcher::class), $l10n, $this->createMock(LoggerInterface::class)),
			$l10n
		);

		$this->assertNull($service->report(sourceRef: 'source-theirs', now: new DateTimeImmutable('2026-11-14T12:00:00+00:00')));
		$this->assertNull($service->report(sourceRef: 'theirs', now: new DateTimeImmutable('2026-11-14T12:00:00+00:00')));
	}//end testTheConnectionMonitorHasNoReportForAnotherOrganisationsSource()

	/**
	 * A record another organisation's synchronization wrote shows no provenance.
	 *
	 * @return void
	 */
	public function testRecordProvenanceDoesNotRevealAnotherOrganisationsContract(): void {
		$objects = $this->createMock(ORObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			ForeignOrganisation::findAll([self::foreign('contract-theirs', ['targetId' => 'record-1', 'synchronization' => 'sync-theirs', 'originId' => 'their-origin'])])
		);
		$objects->method('find')->willReturnCallback(ForeignOrganisation::find(self::foreign('sync-theirs', ['name' => 'Their sync'])));

		$provenance = (new RecordProvenanceService($objects, $this->l10n()))->provenance(recordId: 'record-1');

		$this->assertSame(['synced' => false, 'none' => true], $provenance);
	}//end testRecordProvenanceDoesNotRevealAnotherOrganisationsContract()

	/**
	 * The run summary of a source counts none of another organisation's runs.
	 *
	 * @return void
	 */
	public function testTheRunSummaryCountsNoneOfAnotherOrganisationsRuns(): void {
		$objects = $this->createMock(ORObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			ForeignOrganisation::findAll(
				[self::foreign('run-theirs', ['sourceId' => 'source-theirs', 'status' => 'failed', 'startedAt' => '2026-09-28T14:00:00+02:00', 'found' => 10, 'created' => 0, 'updated' => 0, 'invalid' => 0])]
			)
		);

		$summary = (new RunSummaryService($objects))->summarise(
			sourceId: 'source-theirs',
			from: new DateTimeImmutable('2026-09-26'),
			to: new DateTimeImmutable('2026-09-28')
		);

		$this->assertCount(3, $summary['days']);
		$this->assertSame(0, array_sum(array_column($summary['days'], 'runs')));
		$this->assertSame([], $summary['runs']);
	}//end testTheRunSummaryCountsNoneOfAnotherOrganisationsRuns()

	/**
	 * An environment naming another organisation's source does not resolve it as a promotion target.
	 *
	 * @return void
	 */
	public function testAPromotionTargetDoesNotResolveAnotherOrganisationsSource(): void {
		$objects = $this->createMock(ORObjectService::class);
		$objects->method('find')->willReturnCallback(ForeignOrganisation::find(self::foreign('source-theirs', ['name' => 'Their acceptance'])));

		$this->assertNull((new EnvironmentService($objects))->resolveSource(sourceRef: 'source-theirs'));
	}//end testAPromotionTargetDoesNotResolveAnotherOrganisationsSource()

	/**
	 * The activity overview dates no row by another organisation's verzoeken.
	 *
	 * @return void
	 */
	public function testTheActivityOverviewIgnoresAnotherOrganisationsVerzoeken(): void {
		$row = new ObjectEntity();
		$row->setUuid('kap');
		$row->setObject(['activityId' => 'kap', 'activityName' => 'Kappen', 'caseTypes' => [['reference' => 'OV']]]);
		$verzoek = self::foreign(
			'verzoek-theirs',
			[
				'verzoekId' => 'v1',
				'status' => 'mapped',
				'receivedAt' => '2026-10-06T09:00:00+00:00',
				'mappedActivities' => [['activityId' => 'kap', 'mapped' => true, 'mappingRow' => 'kap']],
			]
		);

		$objects = $this->createMock(ORObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			static function (array $config = [], bool $_rbac = true, bool $_multitenancy = true) use ($row, $verzoek): array {
				if (($config['filters']['schema'] ?? null) === ActivityMappingTableInterface::SCHEMA) {
					return ['results' => [$row]];
				}

				if (($config['filters']['activityUnmapped'] ?? null) === true || $_multitenancy === true) {
					return ['results' => []];
				}

				return ['results' => [$verzoek]];
			}
		);
		$table = new DsoActivityTable($objects);

		$rows = (new ActivityOverview($objects, $table, new DsoUnmappedActivities($objects, $table, new DsoActivityMapper($table))))->rows()['results'];

		$this->assertSame(['kap'], array_column($rows, 'id'));
		$this->assertNull($rows[0]['lastReceivedAt']);
	}//end testTheActivityOverviewIgnoresAnotherOrganisationsVerzoeken()

}//end class

<?php

/**
 * Edge cases around DSO activity mapping: the mapper reading its own table,
 * samenloop rules for other activities, unreadable activiteiten in the
 * summary, and an unmapped activity named only on a later sighting.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Dso
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dso-activity-mapping-table/specs/dso-omgevingsloket/spec.md#requirement-administrators-maintain-the-activity-table-on-the-admin-settings-page-req-dso-012
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Dso;

use OCA\Integriq\Service\Dso\DsoActivityMapper;
use OCA\Integriq\Service\Dso\DsoActivityTable;
use OCA\Integriq\Service\Dso\DsoRequestTranslator;
use OCA\Integriq\Service\Dso\DsoSamenloop;
use OCA\Integriq\Service\Dso\DsoUnmappedActivities;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The branches the mapping suites do not reach.
 */
#[CoversClass(DsoActivityMapper::class)]
#[CoversClass(DsoSamenloop::class)]
#[CoversClass(DsoRequestTranslator::class)]
#[CoversClass(DsoUnmappedActivities::class)]
#[UsesClass(DsoActivityTable::class)]
class DsoActivityMappingEdgeTest extends TestCase {

	/**
	 * Without a prepared index the mapper reads the active rows of its table, once per call.
	 *
	 * @return void
	 */
	public function testIsMappedReadsTheActiveRowsWithoutAnIndex(): void {
		$table = $this->createMock(DsoActivityTable::class);
		$table->expects($this->exactly(2))->method('activeRows')->willReturn([['activityId' => 'kap'], ['imowId' => 'nl.imow-gm0363.activiteit.Bouwen']]);
		$mapper = new DsoActivityMapper($table);

		$this->assertTrue($mapper->isMapped(activity: ['activityId' => ' kap ']));
		$this->assertFalse($mapper->isMapped(activity: ['activityId' => 'sloop']));

	}//end testIsMappedReadsTheActiveRowsWithoutAnIndex()

	/**
	 * A prepared index is used as given, and the table is not read.
	 *
	 * @return void
	 */
	public function testIsMappedUsesAPreparedIndexWithoutReadingTheTable(): void {
		$table = $this->createMock(DsoActivityTable::class);
		$table->expects($this->never())->method('activeRows');
		$mapper = new DsoActivityMapper($table);
		$index = $mapper->index(rows: [['imowId' => 'imow-1']]);

		$this->assertTrue($mapper->isMapped(activity: ['imowId' => 'imow-1'], index: $index));
		$this->assertFalse($mapper->isMapped(activity: ['activityId' => 'imow-1'], index: $index));

	}//end testIsMappedUsesAPreparedIndexWithoutReadingTheTable()

	/**
	 * Only a rule naming the other activity counts; unreadable rules and rules for a third activity are skipped.
	 *
	 * @return void
	 */
	public function testOnlyARuleForTheOtherActivityDecides(): void {
		$samenloop = new DsoSamenloop();
		$one = [
			'imowId' => 'A',
			'samenloopRules' => [
				'not-a-rule',
				['withImowId' => 'C', 'strategy' => DsoSamenloop::DEELZAKEN],
				['withImowId' => ' B ', 'strategy' => DsoSamenloop::GECOMBINEERD],
			],
		];
		$other = ['imowId' => 'B'];

		$this->assertSame(DsoSamenloop::GECOMBINEERD, $samenloop->decide(rows: [$one, $other]));
		$this->assertSame(DsoSamenloop::GECOMBINEERD, $samenloop->decide(rows: [$other, $one]));

	}//end testOnlyARuleForTheOtherActivityDecides()

	/**
	 * A rule for the other activity without a strategy splits into deelzaken, and wins over a combining one.
	 *
	 * @return void
	 */
	public function testARuleWithoutStrategySplitsAndWins(): void {
		$samenloop = new DsoSamenloop();
		$one = ['imowId' => 'A', 'samenloopRules' => [['withImowId' => 'B', 'strategy' => DsoSamenloop::GECOMBINEERD]]];
		$other = ['imowId' => 'B', 'samenloopStrategy' => DsoSamenloop::GECOMBINEERD, 'samenloopRules' => [['withImowId' => 'A']]];

		$this->assertSame(DsoSamenloop::DEELZAKEN, $samenloop->decide(rows: [$one, $other]));

	}//end testARuleWithoutStrategySplitsAndWins()

	/**
	 * Without an identifier on either side, no rule applies and the row strategies decide.
	 *
	 * @return void
	 */
	public function testRowsWithoutIdentifiersFallBackToTheirOwnStrategies(): void {
		$samenloop = new DsoSamenloop();
		$combine = ['samenloopStrategy' => DsoSamenloop::GECOMBINEERD, 'samenloopRules' => [['withImowId' => '', 'strategy' => DsoSamenloop::DEELZAKEN]]];

		$this->assertSame(DsoSamenloop::GECOMBINEERD, $samenloop->decide(rows: [$combine, $combine]));
		$this->assertSame(DsoSamenloop::DEELZAKEN, $samenloop->decide(rows: [$combine, ['imowId' => '']]));

	}//end testRowsWithoutIdentifiersFallBackToTheirOwnStrategies()

	/**
	 * An activiteit that is not a structure is left out of the summary.
	 *
	 * @return void
	 */
	public function testAnUnreadableActiviteitIsLeftOutOfTheSummary(): void {
		$translated = (new DsoRequestTranslator())->translate(request: [
			'verzoekId' => 'dso-9',
			'type' => 'aanvraag',
			'activiteiten' => ['Kappen', ['omschrijving' => 'Bouwen'], 42],
		]);

		$this->assertSame('Bouwen', $translated['mappedSummary']);

	}//end testAnUnreadableActiviteitIsLeftOutOfTheSummary()

	/**
	 * Only unreadable activiteiten and no project description give the documented fallback.
	 *
	 * @return void
	 */
	public function testOnlyUnreadableActiviteitenGiveTheFallbackSummary(): void {
		$translated = (new DsoRequestTranslator())->translate(request: [
			'verzoekId' => 'dso-10',
			'type' => 'melding',
			'activiteiten' => ['Kappen', null],
		]);

		$this->assertSame('Verzoek zonder activiteitomschrijving.', $translated['mappedSummary']);

	}//end testOnlyUnreadableActiviteitenGiveTheFallbackSummary()

	/**
	 * An unmapped activity first seen by imow id alone takes its id and name from a later sighting.
	 *
	 * @return void
	 */
	public function testAGroupTakesItsIdAndNameFromALaterSighting(): void {
		$verzoek = function (string $receivedAt, array $entry): ObjectEntity {
			$entity = new ObjectEntity();
			$entity->setUuid('v-' . $receivedAt);
			$entity->setObject(['activityUnmapped' => true, 'receivedAt' => $receivedAt, 'mappedActivities' => [$entry + ['mapped' => false]]]);

			return $entity;
		};
		$verzoeken = [
			$verzoek('2026-03-02', ['imowId' => 'imow-1']),
			$verzoek('2026-03-01', ['imowId' => 'imow-1', 'code' => 'kap', 'description' => 'Kappen']),
			$verzoek('2026-02-01', ['imowId' => 'imow-1', 'activityId' => 'other', 'activityName' => 'Other name']),
		];

		$objectService = $this->getMockBuilder(ORObjectService::class)->disableOriginalConstructor()->getMock();
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config = []): array => ['results' => (($config['filters']['schema'] ?? null) === 'dso_verzoek' ? $verzoeken : [])]
		);
		$table = new DsoActivityTable($objectService);

		$list = (new DsoUnmappedActivities($objectService, $table, new DsoActivityMapper($table)))->list();

		$this->assertSame(
			[['imowId' => 'imow-1', 'activityId' => 'kap', 'activityName' => 'Kappen', 'count' => 3, 'lastSeen' => '2026-03-02']],
			$list['activities']
		);
		$this->assertSame(0, $list['withoutIdentifier']);
		$this->assertSame(3, $list['scanned']);

	}//end testAGroupTakesItsIdAndNameFromALaterSighting()
}//end class

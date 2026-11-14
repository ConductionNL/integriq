<?php

/**
 * Behaviour tests for ActivityOverview with its collaborators mocked.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Portal;

use OCA\Integriq\Service\Portal\ActivityMappingTableInterface;
use OCA\Integriq\Service\Portal\ActivityOverview;
use OCA\Integriq\Service\Portal\UnmappedActivitiesInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\TestCase;

/**
 * The verzoeken read, the last arrival per row and the unmapped rows.
 *
 * @covers \OCA\Integriq\Service\Portal\ActivityOverview
 */
class ActivityOverviewBehaviourTest extends TestCase {

	/**
	 * The arguments of every findAll call.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * A stored verzoek.
	 *
	 * @param array<string, mixed> $data The verzoek's object.
	 *
	 * @return ObjectEntity
	 */
	private static function verzoek(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($data);
		return $entity;
	}//end verzoek()

	/**
	 * A mapped activity entry pointing at a row.
	 *
	 * @param string $rowId The mapping row id.
	 *
	 * @return array<string, mixed>
	 */
	private static function hit(string $rowId): array {
		return ['mappingRow' => $rowId, 'mapped' => true];
	}//end hit()

	/**
	 * The overview over the given rows, read answer and unmapped activities.
	 *
	 * @param array<int, array<string, mixed>> $tableRows The mapping rows.
	 * @param mixed                            $answer    What findAll answers.
	 * @param array<int, array<string, mixed>> $unmapped  The unmapped activities.
	 *
	 * @return array<int, array<string, mixed>> The overview rows.
	 */
	private function rows(array $tableRows, mixed $answer, array $unmapped=[]): array {
		$objects = $this->createMock(ORObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config=[], bool $_rbac=true, bool $_multitenancy=true) use ($answer): mixed {
				$this->calls[] = ['config' => $config, '_rbac' => $_rbac, '_multitenancy' => $_multitenancy];
				return $answer;
			}
		);
		$table = $this->createMock(ActivityMappingTableInterface::class);
		$table->expects($this->once())->method('allRows')->willReturn($tableRows);
		$list = $this->createMock(UnmappedActivitiesInterface::class);
		$list->expects($this->once())->method('list')->willReturn(['activities' => $unmapped]);

		$result = (new ActivityOverview($objects, $table, $list))->rows();

		$this->assertSame(['results'], array_keys($result));
		return $result['results'];
	}//end rows()

	/**
	 * The latest verzoeken of the integriq register are read once, newest
	 * first, without RBAC or multitenancy.
	 *
	 * @return void
	 */
	public function testTheVerzoekenAreReadOnceAsAnEngineRead(): void {
		$this->rows([], ['results' => []]);

		$this->assertCount(1, $this->calls);
		$this->assertSame(
			[
				'filters' => ['register' => 'integriq', 'schema' => 'dso_verzoek'],
				'limit' => 500,
				'sort' => ['receivedAt' => 'DESC'],
			],
			$this->calls[0]['config']
		);
		$this->assertFalse($this->calls[0]['_rbac']);
		$this->assertFalse($this->calls[0]['_multitenancy']);
	}//end testTheVerzoekenAreReadOnceAsAnEngineRead()

	/**
	 * A row keeps its own fields and gets the newest arrival among the
	 * verzoeken that matched it, whatever order they are read in.
	 *
	 * @return void
	 */
	public function testARowGetsItsNewestArrivalWhateverTheReadOrder(): void {
		$answer = ['results' => [
			self::verzoek(['receivedAt' => '2026-02-01T00:00:00+00:00', 'mappedActivities' => [self::hit('r-1')]]),
			self::verzoek(['receivedAt' => '2026-04-01T00:00:00+00:00', 'mappedActivities' => [self::hit('r-1')]]),
			self::verzoek(['receivedAt' => '2026-03-01T00:00:00+00:00', 'mappedActivities' => [self::hit('r-1')]]),
		]];

		$rows = $this->rows([['id' => 'r-1', 'activityName' => 'Bouwen', 'caseTypes' => [['reference' => 'OV']]]], $answer);

		$this->assertSame(
			[['id' => 'r-1', 'activityName' => 'Bouwen', 'caseTypes' => [['reference' => 'OV']], 'lastReceivedAt' => '2026-04-01T00:00:00+00:00', 'unmapped' => false]],
			$rows
		);
	}//end testARowGetsItsNewestArrivalWhateverTheReadOrder()

	/**
	 * One verzoek can mark several rows; a row nothing matched gets null.
	 *
	 * @return void
	 */
	public function testOneVerzoekMarksEveryRowItMatched(): void {
		$answer = ['results' => [self::verzoek(['receivedAt' => '2026-05-05', 'mappedActivities' => [self::hit('a'), self::hit('b')]])]];

		$rows = $this->rows([['id' => 'a'], ['id' => 'b'], ['id' => 'c']], $answer);

		$this->assertSame(['2026-05-05', '2026-05-05', null], array_column($rows, 'lastReceivedAt'));
	}//end testOneVerzoekMarksEveryRowItMatched()

	/**
	 * A numeric row id matches the string mappingRow a verzoek stores.
	 *
	 * @return void
	 */
	public function testANumericRowIdMatchesItsStringReference(): void {
		$rows = $this->rows([['id' => 7]], ['results' => [self::verzoek(['receivedAt' => '2026-01-01', 'mappedActivities' => [self::hit('7')]])]]);

		$this->assertSame('2026-01-01', $rows[0]['lastReceivedAt']);
		$this->assertSame(7, $rows[0]['id']);
	}//end testANumericRowIdMatchesItsStringReference()

	/**
	 * Only entries that are arrays, mapped strictly true and name a row count.
	 *
	 * @return void
	 */
	public function testOnlyStrictlyMappedEntriesWithARowCount(): void {
		$answer = ['results' => [
			self::verzoek(['receivedAt' => '2026-06-01', 'mappedActivities' => [
				['mappingRow' => 'r', 'mapped' => false],
				['mappingRow' => 'r', 'mapped' => 'yes'],
				['mappingRow' => 'r', 'mapped' => 1],
				['mappingRow' => 'r'],
				['mappingRow' => '', 'mapped' => true],
				['mapped' => true],
				'r',
			]]),
			self::verzoek(['receivedAt' => '2026-07-01', 'mappedActivities' => 'r']),
			self::verzoek(['receivedAt' => '2026-08-01']),
		]];

		$rows = $this->rows([['id' => 'r'], ['id' => '']], $answer);

		$this->assertNull($rows[0]['lastReceivedAt']);
		$this->assertNull($rows[1]['lastReceivedAt']);
	}//end testOnlyStrictlyMappedEntriesWithARowCount()

	/**
	 * A verzoek without receivedAt never sets or replaces an arrival.
	 *
	 * @return void
	 */
	public function testAVerzoekWithoutReceivedAtLeavesTheArrivalAlone(): void {
		$answer = ['results' => [
			self::verzoek(['mappedActivities' => [self::hit('a'), self::hit('b')]]),
			self::verzoek(['receivedAt' => '2026-01-01', 'mappedActivities' => [self::hit('b')]]),
			self::verzoek(['mappedActivities' => [self::hit('b')]]),
		]];

		$rows = $this->rows([['id' => 'a'], ['id' => 'b']], $answer);

		$this->assertSame([null, '2026-01-01'], array_column($rows, 'lastReceivedAt'));
	}//end testAVerzoekWithoutReceivedAtLeavesTheArrivalAlone()

	/**
	 * Anything in the answer that is not an ObjectEntity is left out.
	 *
	 * @return void
	 */
	public function testAnswerItemsThatAreNotEntitiesAreIgnored(): void {
		$answer = ['results' => [
			['receivedAt' => '2027-01-01', 'mappedActivities' => [self::hit('a')]],
			(object)['receivedAt' => '2027-01-01', 'mappedActivities' => [self::hit('a')]],
			self::verzoek(['receivedAt' => '2026-01-01', 'mappedActivities' => [self::hit('a')]]),
		]];

		$rows = $this->rows([['id' => 'a']], $answer);

		$this->assertSame('2026-01-01', $rows[0]['lastReceivedAt']);
	}//end testAnswerItemsThatAreNotEntitiesAreIgnored()

	/**
	 * An answer that is a plain list, without a results key, is read as is.
	 *
	 * @return void
	 */
	public function testAPlainListAnswerIsReadAsTheVerzoeken(): void {
		$rows = $this->rows([['id' => 'a']], [self::verzoek(['receivedAt' => '2026-09-09', 'mappedActivities' => [self::hit('a')]])]);

		$this->assertSame('2026-09-09', $rows[0]['lastReceivedAt']);
	}//end testAPlainListAnswerIsReadAsTheVerzoeken()

	/**
	 * An unmapped activity follows the rows, keyed on its imowId when it has
	 * one and on its activityId when not, with its count and last sighting.
	 *
	 * @return void
	 */
	public function testUnmappedActivitiesFollowKeyedOnImowIdElseActivityId(): void {
		$unmapped = [
			['imowId' => 'nl.imow-gm0363.activiteit.9', 'activityId' => 'graven', 'activityName' => 'Graven', 'count' => 3, 'lastSeen' => '2026-02-02'],
			['imowId' => '', 'activityId' => 'dempen', 'activityName' => 'Dempen', 'count' => 1, 'lastSeen' => null],
		];

		$rows = $this->rows([['id' => 'r-1']], ['results' => []], $unmapped);

		$this->assertSame(
			[
				['id' => 'r-1', 'lastReceivedAt' => null, 'unmapped' => false],
				[
					'id' => 'unmapped:imowId:nl.imow-gm0363.activiteit.9',
					'imowId' => 'nl.imow-gm0363.activiteit.9',
					'activityId' => 'graven',
					'activityName' => 'Graven',
					'caseTypes' => [],
					'unmapped' => true,
					'waiting' => 3,
					'lastReceivedAt' => '2026-02-02',
				],
				[
					'id' => 'unmapped:activityId:dempen',
					'imowId' => '',
					'activityId' => 'dempen',
					'activityName' => 'Dempen',
					'caseTypes' => [],
					'unmapped' => true,
					'waiting' => 1,
					'lastReceivedAt' => null,
				],
			],
			$rows
		);
	}//end testUnmappedActivitiesFollowKeyedOnImowIdElseActivityId()

	/**
	 * A row's stored lastReceivedAt or unmapped is overwritten by the scan.
	 *
	 * @return void
	 */
	public function testTheScanOverridesStaleFieldsOnARow(): void {
		$rows = $this->rows([['id' => 'a', 'lastReceivedAt' => '1999-01-01', 'unmapped' => true]], ['results' => []]);

		$this->assertSame([['id' => 'a', 'lastReceivedAt' => null, 'unmapped' => false]], $rows);
	}//end testTheScanOverridesStaleFieldsOnARow()
}//end class

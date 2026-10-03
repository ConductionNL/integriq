<?php

/**
 * The custom parameter reader on the rows a live contract store can hold.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Lti
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/connectors-course-marketplace/specs/course-marketplace-connectors/spec.md#requirement-a-providers-catalogue-arrives-in-learniq-as-draft-courses-that-launch-through-lti-req-cmkt-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Lti;

use OCA\Integriq\Service\Lti\LtiCustomParameterReader;
use OCA\Integriq\Service\SynchronizationContractService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Rows that cannot name a course give no custom parameter, and the next
 * contract of the same placement is still read.
 */
class LtiCustomParameterReaderTest extends TestCase {

	/**
	 * A launch without a placement asks the store nothing.
	 *
	 * @return void
	 */
	public function testNoPlacementAsksNothing(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->expects($this->never())->method('findAll');

		$this->assertSame([], $this->reader(objectService: $objectService)->forPlacement(placementId: ''));

	}//end testNoPlacementAsksNothing()

	/**
	 * A contract without a synchronization id, one whose synchronization is
	 * gone, one whose lookup throws, one without an origin id and one whose
	 * synchronization declares a non-text name are all skipped; the next
	 * contract that names a course answers.
	 *
	 * @return void
	 */
	public function testContractsThatCannotNameACourseAreSkipped(): void {
		$declaring = ['targetConfig' => [LtiCustomParameterReader::ORIGIN_ID_PARAMETER => 'course_id']];
		$objectService = $this->store(
			contracts: [
				['synchronizationId' => '', 'originId' => 'c-0'],
				['synchronizationId' => 'gone', 'originId' => 'c-1'],
				['synchronizationId' => 'throws', 'originId' => 'c-2'],
				['synchronizationId' => 'declaring', 'originId' => ''],
				['synchronizationId' => 'not-text', 'originId' => 'c-4'],
				['synchronizationId' => 'declaring', 'originId' => 'c-5'],
			],
			synchronizations: [
				'declaring' => $declaring,
				'not-text' => ['targetConfig' => [LtiCustomParameterReader::ORIGIN_ID_PARAMETER => ['course_id']]],
			]
		);

		$this->assertSame(['course_id' => 'c-5'], $this->reader(objectService: $objectService)->forPlacement(placementId: 'placement-7'));

	}//end testContractsThatCannotNameACourseAreSkipped()

	/**
	 * When no contract names a course the answer is empty.
	 *
	 * @return void
	 */
	public function testNoCourseNoParameter(): void {
		$objectService = $this->store(contracts: [['synchronizationId' => 'gone', 'originId' => 'c-1']], synchronizations: []);

		$this->assertSame([], $this->reader(objectService: $objectService)->forPlacement(placementId: 'placement-7'));

	}//end testNoCourseNoParameter()

	/**
	 * The reader over the real contract service.
	 *
	 * @param ObjectService $objectService OpenRegister.
	 *
	 * @return LtiCustomParameterReader
	 */
	private function reader(ObjectService $objectService): LtiCustomParameterReader {
		return new LtiCustomParameterReader(new SynchronizationContractService($objectService), $objectService, new NullLogger());

	}//end reader()

	/**
	 * OpenRegister answering from contract rows and synchronizations; the
	 * synchronization id `throws` makes the lookup throw.
	 *
	 * @param list<array<string, string>>          $contracts        Contract rows of placement-7.
	 * @param array<string, array<string, mixed>> $synchronizations Synchronizations by id.
	 *
	 * @return ObjectService
	 */
	private function store(array $contracts, array $synchronizations): ObjectService {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config = []) use ($contracts): array {
				$this->assertSame('placement-7', $config['filters']['targetId'] ?? null);
				return [
					'results' => array_map(
						fn (array $row, int $index): ObjectEntity => $this->entity(uuid: 'contract-' . $index, data: $row),
						$contracts,
						array_keys($contracts)
					),
				];
			}
		);
		$objectService->method('find')->willReturnCallback(
			function ($id) use ($synchronizations): ?ObjectEntity {
				if ($id === 'throws') {
					throw new \RuntimeException('store unavailable');
				}

				if (isset($synchronizations[$id]) === false) {
					return null;
				}

				return $this->entity(uuid: (string)$id, data: $synchronizations[$id]);
			}
		);

		return $objectService;

	}//end store()

	/**
	 * An ObjectEntity carrying data.
	 *
	 * @param string $uuid The uuid.
	 * @param array  $data The object data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);

		return $entity;

	}//end entity()
}//end class

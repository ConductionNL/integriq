<?php

/**
 * Unit tests: a suite run replays every case, records one run, and calls nothing else.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-a-suite-run-replays-every-case-without-calling-the-source-or-writing-req-irt-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Integriq\Service\CallServiceInterface;
use OCA\Integriq\Service\MappingServiceInterface;
use OCA\Integriq\Service\RegressionSuiteService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The suite runner.
 */
class RegressionSuiteServiceTest extends TestCase {

	/**
	 * Stored cases: uuid => data.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $cases = [];

	/**
	 * Every saveObject call: [schema, object].
	 *
	 * @var array<int,array{0:string,1:array<string,mixed>}>
	 */
	private array $saves = [];

	/**
	 * The object service double.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objectService;

	/**
	 * The mapping service double.
	 *
	 * @var MappingServiceInterface&MockObject
	 */
	private MappingServiceInterface $mappings;

	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				$entities = [];
				foreach ($this->cases as $uuid => $data) {
					$entity = new ObjectEntity();
					$entity->setUuid($uuid);
					$entity->setObject($data);
					$entities[] = $entity;
				}

				return ['results' => $entities];
			}
		);
		$this->objectService->method('saveObject')->willReturnCallback(
			function ($object = [], $register = null, $schema = null): ObjectEntity {
				$this->saves[] = [(string)$schema, $object];
				$entity = new ObjectEntity();
				$entity->setUuid('run-1');
				$entity->setObject($object);
				return $entity;
			}
		);
		$this->mappings = $this->createMock(MappingServiceInterface::class);
	}

	/**
	 * The service under test.
	 *
	 * @return RegressionSuiteService
	 */
	private function service(): RegressionSuiteService {
		return new RegressionSuiteService(objectService: $this->objectService, mappingService: $this->mappings);
	}//end service()

	/**
	 * A mapping with a passing and a failing case records total 2, passed 1, failed 1 and the diff.
	 *
	 * @return void
	 */
	public function testAPassingAndAFailingCaseAreCountedWithTheDiff(): void {
		$this->cases = [
			'c1' => ['name' => 'keeps name', 'subjectType' => 'mapping', 'subjectId' => 'lowercase-keys', 'input' => ['Name' => 'A'], 'expectedOutput' => ['name' => 'A']],
			'c2' => ['name' => 'keeps status', 'subjectType' => 'mapping', 'subjectId' => 'lowercase-keys', 'input' => ['Status' => 'open'], 'expectedOutput' => ['status' => 'open']],
			'other' => ['name' => 'other mapping', 'subjectType' => 'mapping', 'subjectId' => 'passthrough', 'input' => [], 'expectedOutput' => []],
		];
		$this->mappings->expects($this->exactly(2))->method('executeMapping')->willReturnCallback(
			static function ($mapping, array $input): array {
				TestCase::assertSame('lowercase-keys', $mapping);
				$output = array_change_key_case($input);
				if (isset($output['status']) === true) {
					$output['status'] = 'closed';
				}

				return $output;
			}
		);

		$run = $this->service()->run(subjectType: 'mapping', subjectId: 'lowercase-keys', userId: 'alice');

		$this->assertSame(expected: 2, actual: $run['total']);
		$this->assertSame(expected: 1, actual: $run['passed']);
		$this->assertSame(expected: 1, actual: $run['failed']);
		$this->assertSame(
			expected: [['caseId' => 'c2', 'caseName' => 'keeps status', 'differences' => [['path' => 'status', 'expected' => 'open', 'actual' => 'closed']]]],
			actual: $run['failures']
		);
		$this->assertSame(expected: 'run-1', actual: $run['id']);
		$this->assertSame(expected: 'alice', actual: $run['runBy']);
		$this->assertCount(expectedCount: 1, haystack: $this->saves);
		$this->assertSame(expected: 'integration_test_run', actual: $this->saves[0][0]);
	}//end testAPassingAndAFailingCaseAreCountedWithTheDiff()

	/**
	 * A synchronization case runs its mapping only: no call is made and nothing but the run is saved.
	 *
	 * @return void
	 */
	public function testASynchronizationCaseCallsNothingAndSavesOnlyTheRun(): void {
		$this->cases = [
			'c1' => ['name' => 'creates a zaak', 'subjectType' => 'synchronization', 'subjectId' => 'sync-1', 'input' => ['id' => 7], 'expectedOutput' => ['identificatie' => '7']],
		];
		$synchronization = new ObjectEntity();
		$synchronization->setObject(['sourceTargetMapping' => 'zaak-mapping', 'sourceId' => 'live-source']);
		$this->objectService->expects($this->once())->method('find')->with('sync-1')->willReturn($synchronization);
		$this->mappings->expects($this->once())->method('executeMapping')->with('zaak-mapping', ['id' => 7])->willReturn(['identificatie' => '7']);
		$run = $this->service()->run(subjectType: 'synchronization', subjectId: 'sync-1', userId: 'alice');

		$this->assertSame(expected: [1, 1, 0], actual: [$run['total'], $run['passed'], $run['failed']]);
		$this->assertSame(expected: ['integration_test_run'], actual: array_column($this->saves, 0));

		// The runner cannot reach a source: it is given no outbound call service at all.
		$types = array_map(
			static fn (\ReflectionParameter $parameter): string => (string)$parameter->getType(),
			(new \ReflectionMethod(RegressionSuiteService::class, '__construct'))->getParameters()
		);
		$this->assertNotContains(CallServiceInterface::class, $types);
		$this->assertNotContains(\OCA\Integriq\Service\CallService::class, $types);
	}//end testASynchronizationCaseCallsNothingAndSavesOnlyTheRun()

	/**
	 * An ignored path that alone differs passes the case.
	 *
	 * @return void
	 */
	public function testAnIgnoredPathPasses(): void {
		$case = ['subjectType' => 'mapping', 'subjectId' => 'm', 'input' => [], 'expectedOutput' => ['name' => 'x', 'dateModified' => '2026-01-01'], 'ignoredPaths' => ['dateModified']];
		$this->mappings->method('executeMapping')->willReturn(['name' => 'x', 'dateModified' => '2026-11-13']);

		$this->assertNull($this->service()->runCase(case: $case));
	}//end testAnIgnoredPathPasses()

	/**
	 * A case that cannot run fails with the error, and a synchronization without a mapping is one.
	 *
	 * @return void
	 */
	public function testACaseThatCannotRunFailsWithItsError(): void {
		$this->mappings->method('executeMapping')->willThrowException(new RuntimeException('Mapping "gone" could not be resolved through OpenRegister.'));
		$this->assertSame(
			expected: ['caseId' => 'c9', 'caseName' => 'gone', 'differences' => [], 'error' => 'Mapping "gone" could not be resolved through OpenRegister.'],
			actual: $this->service()->runCase(case: ['id' => 'c9', 'name' => 'gone', 'subjectType' => 'mapping', 'subjectId' => 'gone'])
		);

		$synchronization = new ObjectEntity();
		$synchronization->setObject(['name' => 'no mapping']);
		$this->objectService->method('find')->willReturn($synchronization);
		$this->assertSame(
			expected: 'Synchronization sync-2 has no source-to-target mapping to test.',
			actual: $this->service()->runCase(case: ['subjectType' => 'synchronization', 'subjectId' => 'sync-2'])['error']
		);
	}//end testACaseThatCannotRunFailsWithItsError()

	/**
	 * An unknown subject type is refused before anything is read or written.
	 *
	 * @return void
	 */
	public function testAnUnknownSubjectTypeIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		try {
			$this->service()->run(subjectType: 'rule', subjectId: 'x', userId: 'alice');
		} finally {
			$this->assertSame(expected: [], actual: $this->saves);
		}
	}//end testAnUnknownSubjectTypeIsRefused()
}//end class

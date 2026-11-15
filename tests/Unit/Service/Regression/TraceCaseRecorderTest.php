<?php

/**
 * Unit tests: a trace step becomes a test case.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Regression
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-a-test-case-can-be-recorded-from-a-traced-execution-req-irt-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Regression;

use InvalidArgumentException;
use OCA\Integriq\Service\ExecutionTraceService;
use OCA\Integriq\Service\Regression\TraceCaseRecorder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OutOfBoundsException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * What the recorder saves, and when it refuses.
 */
class TraceCaseRecorderTest extends TestCase {

	/**
	 * The trace service double.
	 *
	 * @var ExecutionTraceService&MockObject
	 */
	private ExecutionTraceService $traces;

	/**
	 * The object service double.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objectService;

	/**
	 * What saveObject received: [register, schema, object].
	 *
	 * @var list<array{0:mixed,1:mixed,2:array<string,mixed>}>
	 */
	private array $saves = [];

	protected function setUp(): void {
		parent::setUp();
		$this->saves = [];
		$this->traces = $this->createMock(ExecutionTraceService::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('saveObject')->willReturnCallback(
			function ($object = [], $register = null, $schema = null): ObjectEntity {
				$this->saves[] = [$register, $schema, $object];
				$entity = new ObjectEntity();
				$entity->setUuid('case-1');
				$entity->setObject($object);
				return $entity;
			}
		);
	}

	/**
	 * Let the trace service answer this trace data, or nothing.
	 *
	 * @param array<string,mixed>|null $trace The trace data, or null for a miss.
	 *
	 * @return TraceCaseRecorder
	 */
	private function recorder(?array $trace): TraceCaseRecorder {
		$entity = null;
		if ($trace !== null) {
			$entity = new ObjectEntity();
			$entity->setUuid('3f2c1a9e-0000-4000-8000-000000000001');
			$entity->setObject($trace);
		}

		$this->traces->method('find')->with('3f2c1a9e-0000-4000-8000-000000000001')->willReturn($entity);

		return new TraceCaseRecorder(traces: $this->traces, objectService: $this->objectService);
	}//end recorder()

	/**
	 * A trace started by a synchronization, with a rule step and a synchronization step.
	 *
	 * @return array<string,mixed>
	 */
	private static function syncTrace(): array {
		return [
			'entryPoint' => 'sync',
			'entryPointId' => 'sync-uuid',
			'steps' => [
				['order' => 1, 'type' => 'rule', 'name' => 'Check', 'input' => [], 'output' => []],
				[
					'order' => 2,
					'type' => 'synchronization',
					'name' => 'Contacts',
					'input' => ['Name' => 'Ada', 'authorization' => '***REDACTED***'],
					'output' => ['name' => 'Ada'],
				],
				['order' => 3, 'type' => 'mapping', 'name' => 'lowercase-keys', 'input' => ['A' => 1], 'output' => ['a' => 1]],
			],
		];
	}//end syncTrace()

	/**
	 * A mapping step becomes a case for that mapping whose input and expected output equal the snapshot.
	 *
	 * @return void
	 */
	public function testAMappingStepBecomesACaseEqualToItsSnapshot(): void {
		$case = $this->recorder(trace: self::syncTrace())->record(
			traceId: '3f2c1a9e-0000-4000-8000-000000000001',
			stepOrder: 3
		);

		$this->assertCount(1, $this->saves);
		[$register, $schema, $saved] = $this->saves[0];
		$this->assertSame('integriq', $register);
		$this->assertSame('integration_test_case', $schema);
		$this->assertSame(
			[
				'name' => 'lowercase-keys (trace 3f2c1a9e, step 3)',
				'subjectType' => 'mapping',
				'subjectId' => 'lowercase-keys',
				'input' => ['A' => 1],
				'expectedOutput' => ['a' => 1],
				'ignoredPaths' => [],
			],
			$saved
		);
		$this->assertSame('case-1', $case['id']);
		$this->assertSame([], $case['redactedPaths']);
	}//end testAMappingStepBecomesACaseEqualToItsSnapshot()

	/**
	 * A synchronization step belongs to the synchronization the trace started from, and names its redacted field.
	 *
	 * @return void
	 */
	public function testASynchronizationStepNamesTheTracesSynchronizationAndItsRedactedField(): void {
		$case = $this->recorder(trace: self::syncTrace())->record(
			traceId: '3f2c1a9e-0000-4000-8000-000000000001',
			stepOrder: 2
		);

		$this->assertSame('synchronization', $case['subjectType']);
		$this->assertSame('sync-uuid', $case['subjectId']);
		$this->assertSame(['Name' => 'Ada', 'authorization' => '***REDACTED***'], $case['input']);
		$this->assertSame(['input.authorization'], $case['redactedPaths']);
	}//end testASynchronizationStepNamesTheTracesSynchronizationAndItsRedactedField()

	/**
	 * Edited values replace the snapshot's, so a replaced redacted field is no longer reported.
	 *
	 * @return void
	 */
	public function testEditsReplaceTheSnapshot(): void {
		$case = $this->recorder(trace: self::syncTrace())->record(
			traceId: '3f2c1a9e-0000-4000-8000-000000000001',
			stepOrder: 2,
			edits: [
				'name' => '  Ada becomes a contact ',
				'description' => 'Recorded from production',
				'input' => ['Name' => 'Ada', 'authorization' => 'Bearer test'],
				'ignoredPaths' => ['dateModified', '', 7],
			]
		);

		[, , $saved] = $this->saves[0];
		$this->assertSame('Ada becomes a contact', $saved['name']);
		$this->assertSame('Recorded from production', $saved['description']);
		$this->assertSame(['Name' => 'Ada', 'authorization' => 'Bearer test'], $saved['input']);
		$this->assertSame(['name' => 'Ada'], $saved['expectedOutput']);
		$this->assertSame(['dateModified'], $saved['ignoredPaths']);
		$this->assertSame([], $case['redactedPaths']);
	}//end testEditsReplaceTheSnapshot()

	/**
	 * A missing trace and a missing step are both out of bounds, and nothing is saved.
	 *
	 * @return void
	 */
	public function testAMissingTraceIsOutOfBounds(): void {
		$this->expectException(OutOfBoundsException::class);
		$this->expectExceptionMessage('Trace 3f2c1a9e-0000-4000-8000-000000000001 does not exist.');

		try {
			$this->recorder(trace: null)->record(traceId: '3f2c1a9e-0000-4000-8000-000000000001', stepOrder: 1);
		} finally {
			$this->assertSame([], $this->saves);
		}
	}//end testAMissingTraceIsOutOfBounds()

	/**
	 * A step order the trace does not have is out of bounds.
	 *
	 * @return void
	 */
	public function testAMissingStepIsOutOfBounds(): void {
		$this->expectException(OutOfBoundsException::class);
		$this->expectExceptionMessage('The trace has no step 9.');

		$this->recorder(trace: self::syncTrace())->record(traceId: '3f2c1a9e-0000-4000-8000-000000000001', stepOrder: 9);
	}//end testAMissingStepIsOutOfBounds()

	/**
	 * A rule step is not recorded.
	 *
	 * @return void
	 */
	public function testARuleStepIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('not from a rule step');

		try {
			$this->recorder(trace: self::syncTrace())->record(traceId: '3f2c1a9e-0000-4000-8000-000000000001', stepOrder: 1);
		} finally {
			$this->assertSame([], $this->saves);
		}
	}//end testARuleStepIsRefused()

	/**
	 * A synchronization step in a trace that no synchronization started names no subject.
	 *
	 * @return void
	 */
	public function testASynchronizationStepOutsideASyncTraceIsRefused(): void {
		$trace = self::syncTrace();
		$trace['entryPoint'] = 'endpoint';

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Step 2 does not name the synchronization that ran.');

		$this->recorder(trace: $trace)->record(traceId: '3f2c1a9e-0000-4000-8000-000000000001', stepOrder: 2);
	}//end testASynchronizationStepOutsideASyncTraceIsRefused()

	/**
	 * Redacted values are found at every depth, list indexes included.
	 *
	 * @return void
	 */
	public function testRedactedPathsAreFoundAtEveryDepth(): void {
		$this->assertSame(
			['headers.authorization', 'contacts.1.token'],
			TraceCaseRecorder::redactedPaths(
				data: [
					'headers' => ['authorization' => '***REDACTED***', 'accept' => 'json'],
					'contacts' => [['token' => 'visible'], ['token' => '***REDACTED***']],
					'count' => 2,
				]
			)
		);
	}//end testRedactedPathsAreFoundAtEveryDepth()
}//end class

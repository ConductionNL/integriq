<?php

/**
 * Tests for SubmissionDrain.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\OpenFormulieren
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/open-formulieren-submits-into-its-case/tasks.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\OpenFormulieren;

use OCA\Integriq\Service\OpenFormulieren\DestinationSubmitterInterface;
use OCA\Integriq\Service\OpenFormulieren\OpenFormulierenConnection;
use OCA\Integriq\Service\OpenFormulieren\SubmissionDrain;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\FormSubmitRefusedException;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Draining the staged submissions into their cases.
 */
#[CoversClass(SubmissionDrain::class)]
class SubmissionDrainTest extends TestCase {

	/**
	 * The saves the drain made: object and options.
	 *
	 * @var list<array{object: array<string, mixed>, options: ObjectSaveOptions}>
	 */
	private array $saved = [];

	/**
	 * The drain over one received and one failed submission.
	 *
	 * @param DestinationSubmitterInterface $submitter The submit path.
	 * @param OpenFormulierenConnection     $connection The connection.
	 *
	 * @return SubmissionDrain
	 */
	private function drain(DestinationSubmitterInterface $submitter, OpenFormulierenConnection $connection): SubmissionDrain {
		$objects = $this->getMockBuilder(ObjectService::class)->disableOriginalConstructor()->getMock();
		$objects->method('findAll')->willReturnCallback(
			fn (array $config): array => [
				'results' => [
					$this->submission(uuid: 'sub-' . $config['filters']['status'], status: $config['filters']['status']),
					'not-an-entity',
				],
			]
		);
		$objects->method('saveObject')->willReturnCallback(
			function ($object, $register = null, $schema = null, $uuid = null, $rbac = true, $tenancy = true, $silent = false, $validation = true, $options = null): ObjectEntity {
				$this->saved[] = ['object' => $object, 'options' => $options];
				return new ObjectEntity();
			}
		);

		return new SubmissionDrain($objects, $submitter, $connection);
	}//end drain()

	/**
	 * A staged submission.
	 *
	 * @param string $uuid   Its uuid.
	 * @param string $status Its status.
	 *
	 * @return ObjectEntity
	 */
	private function submission(string $uuid, string $status): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject(['formSlug' => 'melding', 'submissionUuid' => 'of-' . $status, 'rawValues' => ['a' => 1], 'status' => $status]);

		return $entity;
	}//end submission()

	/**
	 * The connection, running as its account when asked.
	 *
	 * @param bool $used Whether the drain must run as the account.
	 *
	 * @return OpenFormulierenConnection
	 */
	private function connection(bool $used): OpenFormulierenConnection {
		$connection = $this->getMockBuilder(OpenFormulierenConnection::class)->disableOriginalConstructor()->getMock();
		$account = $this->createMock(IUser::class);
		$connection->expects($used === true ? $this->once() : $this->never())->method('resolveAccount')->willReturn($account);
		$connection->method('runAs')->willReturnCallback(
			function (IUser $as, callable $operation) use ($account): mixed {
				$this->assertSame($account, $as);
				return $operation();
			}
		);

		return $connection;
	}//end connection()

	/**
	 * A dry run lists both pending submissions and writes nothing.
	 *
	 * @return void
	 */
	public function testADryRunListsThePendingSubmissions(): void {
		$submitter = $this->createMock(DestinationSubmitterInterface::class);
		$submitter->expects($this->never())->method('submit');

		$report = $this->drain($submitter, $this->connection(used: false))->run(apply: false, limit: 10);

		$this->assertSame(2, $report['pending']);
		$this->assertSame(['sub-received', 'sub-failed'], array_column($report['rows'], 'uuid'));
		$this->assertSame([], $this->saved);
	}//end testADryRunListsThePendingSubmissions()

	/**
	 * Apply submits each with its submission id as key, marks a created one handed off, and reports a refused one.
	 *
	 * @return void
	 */
	public function testApplySubmitsMarksAndReports(): void {
		$submitter = $this->createMock(DestinationSubmitterInterface::class);
		$submitter->expects($this->exactly(2))->method('submit')->willReturnCallback(
			static function (string $formSlug, string $submissionId, array $values, string $hash): array {
				if ($submissionId === 'of-failed') {
					throw new FormSubmitRefusedException('caseType missing', 422);
				}

				return ['reference' => '2026-0001', 'id' => 'case-1'];
			}
		);

		$report = $this->drain($submitter, $this->connection(used: true))->run(apply: true, limit: 10);

		$this->assertSame([2, 1, 1], [$report['pending'], $report['drained'], $report['failed']]);
		$this->assertSame(['created 2026-0001', 'refused 422: caseType missing'], array_column($report['rows'], 'outcome'));
		$this->assertCount(1, $this->saved);
		$this->assertSame('handed_off', $this->saved[0]['object']['status']);
		$this->assertSame(['reference' => '2026-0001', 'id' => 'case-1'], $this->saved[0]['object']['targetCase']);
		$this->assertSame('sub-received', $this->saved[0]['options']->uuid);
		$this->assertSame('openformulieren_submission', $this->saved[0]['options']->target->schema);
	}//end testApplySubmitsMarksAndReports()
}//end class

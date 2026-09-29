<?php

/**
 * The approval_request payloads the change set preview writes, validated
 * against the merged register schema.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\ApprovalService;
use OCA\Integriq\Service\SynchronizationApprovalGate;
use OCA\Integriq\Service\Synchronization\ChangeSetBuilder;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Every write of the preview must be one OpenRegister accepts, or the gate
 * cannot store a change set live.
 *
 * @spec openspec/changes/connectors-inavigator-case-types/specs/synchronization-engine/spec.md#requirement-a-gated-run-stores-its-change-set-on-the-approval-request-req-inav-003
 */
class ApprovalServiceChangeSetPayloadTest extends TestCase {

	private const SYNC_ID = '5b0f3f0e-2c4b-4f0a-9d5e-1f2a3b4c5d6e';

	/**
	 * @var \PHPUnit\Framework\MockObject\MockObject
	 */
	private $objectService;

	/**
	 * @var array<int, array> Every object handed to saveObject().
	 */
	private array $saved = [];

	private ApprovalService $service;

	private SynchronizationApprovalGate $gate;

	/**
	 * Set up an ApprovalService over a capturing object service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectService = ObjectServiceMockBuilder::make($this);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, ...$rest) {
				$this->saved[] = $object;
				return ObjectServiceMockBuilder::objectEntity($this, $object, 'approval-' . count($this->saved));
			}
		);

		$this->service = new ApprovalService(
			$this->objectService,
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(INotificationManager::class),
			$this->createMock(IURLGenerator::class),
			$this->createMock(LoggerInterface::class),
		);

		$this->gate = new SynchronizationApprovalGate($this->objectService, $this->createMock(IUserSession::class), $this->service);

	}//end setUp()

	/**
	 * The change set a case type re-import builds.
	 *
	 * @return array
	 */
	private function changeSet(): array {
		return (new ChangeSetBuilder())->build(
			entries: [
				['originId' => 'zt-new', 'targetId' => null, 'mapped' => ['omschrijving' => 'Parkeervergunning'], 'existing' => null],
				['originId' => 'zt-changed', 'targetId' => 'target-2', 'mapped' => ['omschrijving' => 'Nieuw'], 'existing' => ['omschrijving' => 'Oud']],
			],
			removed: [['originId' => 'zt-gone', 'targetId' => 'target-9']],
			removalsAllowed: true
		);
	}//end changeSet()

	/**
	 * A paused run stores its change set and fingerprint, and the register
	 * accepts the payload.
	 *
	 * @return void
	 */
	public function testThePausedRequestWithItsChangeSetIsAcceptedByTheRegister(): void {
		$changeSet = $this->changeSet();

		$this->gate->suspendForSynchronization(
			synchronizationId: self::SYNC_ID,
			approverGroup: 'admin',
			onReject: 'error',
			onTimeout: 'error',
			ttlSeconds: 3600,
			changeSet: $changeSet
		);

		$stored = $this->saved[0];
		$this->assertSame($changeSet, $stored['snapshot']['changeSet']);
		$this->assertSame($changeSet['fingerprint'], $stored['fingerprint']);
		$this->assertSame([], RegisterSchemaValidator::errors('approval_request', $stored));
	}//end testThePausedRequestWithItsChangeSetIsAcceptedByTheRegister()

	/**
	 * A superseded request records `superseded` and the new request, and the
	 * register accepts it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connectors-inavigator-case-types/specs/synchronization-engine/spec.md#requirement-accepting-writes-the-previewed-change-set-or-asks-again-req-inav-004
	 */
	public function testASupersededRequestIsAcceptedByTheRegister(): void {
		$approved = ObjectServiceMockBuilder::objectEntity(
			$this,
			[
				'status' => 'approved',
				'synchronizationId' => self::SYNC_ID,
				'approverGroup' => 'admin',
				'onReject' => 'error',
				'onTimeout' => 'error',
				'expiresAt' => '2026-09-30T09:00:00+00:00',
				'approvedAt' => '2026-09-29T09:00:00+00:00',
				'resumeResult' => 'success',
				'fingerprint' => $this->changeSet()['fingerprint'],
			],
			'approval-previewed'
		);

		$this->gate->markSuperseded(approvalRequest: $approved, supersededBy: '7c1d2e3f-4a5b-4c6d-8e7f-9a0b1c2d3e4f');

		$stored = $this->saved[0];
		$this->assertSame('superseded', $stored['resumeResult']);
		$this->assertSame('7c1d2e3f-4a5b-4c6d-8e7f-9a0b1c2d3e4f', $stored['supersededBy']);
		$this->assertNotEmpty($stored['consumedAt']);
		$this->assertSame([], RegisterSchemaValidator::errors('approval_request', $stored));
	}//end testASupersededRequestIsAcceptedByTheRegister()
}//end class

<?php

/**
 * Cross-tenant refusal tests for the synchronization, contract, dead-letter
 * and job controllers.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
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

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\JobsController;
use OCA\Integriq\Controller\SyncDeadLetterController;
use OCA\Integriq\Controller\SynchronizationContractsController;
use OCA\Integriq\Controller\SynchronizationsController;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\JobService;
use OCA\Integriq\Service\SyncItemDeadLetterService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Tests\Helpers\ForeignOrganisation;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A user of organisation A asks for objects of organisation B: every lookup
 * answers 404 and nothing runs, changes or is deleted.
 *
 * @spec openspec/changes/integriq-tenant-scoping/specs/tenant-scoping/spec.md#requirement-a-user-only-reaches-their-own-organisations-integriq-objects-req-ten-001
 */
class SynchronizationTenantScopingTest extends TestCase {

	/**
	 * @var OrObjectService&MockObject
	 */
	private $objects;

	/**
	 * @var IL10N&MockObject
	 */
	private $l;

	/**
	 * @var IUserSession&MockObject
	 */
	private $session;

	/**
	 * Set up an ObjectService that holds only another organisation's object.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$foreign = ObjectServiceMockBuilder::objectEntity($this, ['name' => 'organisation-b'], 'uuid-b');
		$this->objects = $this->createMock(originalClassName: OrObjectService::class);
		$this->objects->method('find')->willReturnCallback(ForeignOrganisation::find($foreign));
		$this->objects->expects($this->never())->method('saveObject');
		$this->objects->expects($this->never())->method('deleteObject');

		$this->l = $this->createMock(originalClassName: IL10N::class);
		$this->l->method('t')->willReturnArgument(0);

		$this->session = $this->createMock(originalClassName: IUserSession::class);
		$this->session->method('getUser')->willReturn($this->createMock(originalClassName: IUser::class));
	}//end setUp()

	/**
	 * Testing, running, resetting the cursor of, or deleting a log of another
	 * organisation's synchronization answers 404 and runs nothing.
	 *
	 * @return void
	 */
	public function testSynchronizationOfAnotherOrganisationIsRefused(): void {
		$service = $this->createMock(originalClassName: SynchronizationService::class);
		$service->expects($this->never())->method('synchronize');

		$controller = new SynchronizationsController(
			appName: 'integriq',
			request: $this->createMock(originalClassName: IRequest::class),
			orObjectService: $this->objects,
			synchronizationService: $service,
			l: $this->l,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			userSession: $this->session,
			actionAuth: $this->createMock(originalClassName: ActionAuthService::class)
		);

		$this->assertSame(expected: 404, actual: $controller->test('uuid-b')->getStatus());
		$this->assertSame(expected: 404, actual: $controller->run('uuid-b')->getStatus());
		$this->assertSame(expected: 404, actual: $controller->resetCursor('uuid-b')->getStatus());
		$this->assertSame(expected: 404, actual: $controller->deleteLog(7)->getStatus());
	}//end testSynchronizationOfAnotherOrganisationIsRefused()

	/**
	 * Activating, deactivating or executing another organisation's contract
	 * answers 404.
	 *
	 * @return void
	 */
	public function testContractOfAnotherOrganisationIsRefused(): void {
		$controller = new SynchronizationContractsController(
			appName: 'integriq',
			request: $this->createMock(originalClassName: IRequest::class),
			orObjectService: $this->objects,
			l: $this->l,
			userSession: $this->session,
			actionAuth: $this->createMock(originalClassName: ActionAuthService::class)
		);

		$this->assertSame(expected: 404, actual: $controller->activate('uuid-b')->getStatus());
		$this->assertSame(expected: 404, actual: $controller->deactivate('uuid-b')->getStatus());
		$this->assertSame(expected: 404, actual: $controller->execute('uuid-b')->getStatus());
	}//end testContractOfAnotherOrganisationIsRefused()

	/**
	 * Opening another organisation's dead letter answers 404.
	 *
	 * @return void
	 */
	public function testDeadLetterOfAnotherOrganisationIsRefused(): void {
		$controller = new SyncDeadLetterController(
			appName: 'integriq',
			request: $this->createMock(originalClassName: IRequest::class),
			orObjectService: $this->objects,
			syncItemDeadLetterService: $this->createMock(originalClassName: SyncItemDeadLetterService::class),
			l: $this->l,
			userSession: $this->session
		);

		$this->assertSame(expected: 404, actual: $controller->show('uuid-b')->getStatus());
	}//end testDeadLetterOfAnotherOrganisationIsRefused()

	/**
	 * Running or testing another organisation's job answers 404 and executes nothing.
	 *
	 * @return void
	 */
	public function testJobOfAnotherOrganisationIsRefused(): void {
		$jobs = $this->createMock(originalClassName: JobService::class);
		$jobs->expects($this->never())->method('executeJob');

		$controller = new JobsController(
			appName: 'integriq',
			request: $this->createMock(originalClassName: IRequest::class),
			orObjectService: $this->objects,
			jobService: $jobs,
			l: $this->l,
			userSession: $this->session,
			actionAuth: $this->createMock(originalClassName: ActionAuthService::class)
		);

		$this->assertSame(expected: 404, actual: $controller->run('uuid-b')->getStatus());
		$this->assertSame(expected: 404, actual: $controller->test('uuid-b')->getStatus());
	}//end testJobOfAnotherOrganisationIsRefused()

}//end class

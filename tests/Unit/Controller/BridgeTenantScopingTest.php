<?php

/**
 * Cross-tenant refusal tests for the Forms and Tables bridge editors.
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

use OCA\Integriq\Controller\FormsBridgeController;
use OCA\Integriq\Controller\TablesBridgeController;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\Forms\FormsSyncAdapter;
use OCA\Integriq\Service\Tables\TablesSyncAdapter;
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
 * The synchronization editor never lists the forms, questions, tables or
 * columns behind another organisation's source.
 *
 * @spec openspec/changes/integriq-tenant-scoping/specs/tenant-scoping/spec.md#requirement-a-user-only-reaches-their-own-organisations-integriq-objects-req-ten-001
 */
class BridgeTenantScopingTest extends TestCase {

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
	 * Set up an ObjectService that holds only another organisation's source.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$foreign = ObjectServiceMockBuilder::objectEntity($this, ['name' => 'organisation-b'], 'source-b');
		$this->objects = $this->createMock(originalClassName: OrObjectService::class);
		$this->objects->method('find')->willReturnCallback(ForeignOrganisation::find($foreign));

		$this->l = $this->createMock(originalClassName: IL10N::class);
		$this->l->method('t')->willReturnArgument(0);

		$this->session = $this->createMock(originalClassName: IUserSession::class);
		$this->session->method('getUser')->willReturn($this->createMock(originalClassName: IUser::class));
	}//end setUp()

	/**
	 * Listing forms or questions behind another organisation's source answers 404.
	 *
	 * @return void
	 */
	public function testFormsBehindASourceOfAnotherOrganisationAreRefused(): void {
		$adapter = $this->createMock(originalClassName: FormsSyncAdapter::class);
		$adapter->expects($this->never())->method('listFormsForEditor');
		$adapter->expects($this->never())->method('listQuestionsForEditor');

		$controller = new FormsBridgeController(
			appName: 'integriq',
			request: $this->createMock(originalClassName: IRequest::class),
			formsSyncAdapter: $adapter,
			orObjectService: $this->objects,
			l: $this->l,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			userSession: $this->session,
			actionAuth: $this->createMock(originalClassName: ActionAuthService::class)
		);

		$this->assertSame(expected: 404, actual: $controller->forms('source-b')->getStatus());
		$this->assertSame(expected: 404, actual: $controller->questions(3, 'source-b')->getStatus());
	}//end testFormsBehindASourceOfAnotherOrganisationAreRefused()

	/**
	 * Listing tables or columns behind another organisation's source answers 404.
	 *
	 * @return void
	 */
	public function testTablesBehindASourceOfAnotherOrganisationAreRefused(): void {
		$adapter = $this->createMock(originalClassName: TablesSyncAdapter::class);
		$adapter->expects($this->never())->method('listTablesForEditor');
		$adapter->expects($this->never())->method('listColumnsForEditor');

		$controller = new TablesBridgeController(
			appName: 'integriq',
			request: $this->createMock(originalClassName: IRequest::class),
			tablesSyncAdapter: $adapter,
			orObjectService: $this->objects,
			l: $this->l,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			userSession: $this->session,
			actionAuth: $this->createMock(originalClassName: ActionAuthService::class)
		);

		$this->assertSame(expected: 404, actual: $controller->tables('source-b')->getStatus());
		$this->assertSame(expected: 404, actual: $controller->columns(3, 'source-b')->getStatus());
	}//end testTablesBehindASourceOfAnotherOrganisationAreRefused()

}//end class

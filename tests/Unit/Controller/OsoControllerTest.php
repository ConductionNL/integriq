<?php

/**
 * Unit tests for OsoController.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-09-29-integriq-adapter-oso/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\OsoController;
use OCA\Integriq\Exception\OsoProviderException;
use OCA\Integriq\Exception\OsoTranslationException;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\OsoService;
use OCA\Integriq\Service\Intake\WebhookGate;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the OSO export push endpoint and the signed inbound import/retour receivers.
 *
 * @spec openspec/specs/oso-adapter/spec.md#requirement-req-004-push-export-signed-inbound-import-and-signed-export-retour
 */
class OsoControllerTest extends TestCase {

	/**
	 * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $request;

	/**
	 * @var OsoService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $osoService;

	/**
	 * @var WebhookGate|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $gate;

	/**
	 * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $userSession;

	/**
	 * @var ActionAuthService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $actionAuth;

	/**
	 * @var IL10N|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $l;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $logger;

	/**
	 * @var OsoController
	 */
	private OsoController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->osoService = $this->createMock(OsoService::class);
		$this->gate = $this->createMock(WebhookGate::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->actionAuth = $this->createMock(ActionAuthService::class);
		$this->l = $this->createMock(IL10N::class);
		$this->l->method('t')->willReturnArgument(0);
		$this->logger = $this->createMock(LoggerInterface::class);

		$user = $this->createMock(IUser::class);
		$this->userSession->method('getUser')->willReturn($user);

		$this->controller = $this->buildController();

	}//end setUp()

	/**
	 * Build a controller instance wired to the current mocks.
	 *
	 * @return OsoController
	 */
	private function buildController(): OsoController {
		return new OsoController(
			'integriq',
			$this->request,
			$this->osoService,
			$this->gate,
			$this->userSession,
			$this->actionAuth,
			$this->l,
			$this->logger
		);

	}//end buildController()

	/**
	 * An unauthenticated caller gets 401 without reaching the OSO service.
	 *
	 * @return void
	 */
	public function testExportRequiresAuthentication(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn(null);
		$this->controller = $this->buildController();

		$this->osoService->expects($this->never())->method('sendExport');

		$response = $this->controller->export();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testExportRequiresAuthentication()

	/**
	 * A missing kenmerk is rejected 400 before the service is called.
	 *
	 * @return void
	 */
	public function testExportRequiresKenmerk(): void {
		$this->request->method('getParams')->willReturn([]);

		$this->osoService->expects($this->never())->method('sendExport');

		$response = $this->controller->export();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('missing_fields', $response->getData()['error']);

	}//end testExportRequiresKenmerk()

	/**
	 * A valid export request returns the service's result verbatim.
	 *
	 * @return void
	 */
	public function testExportReturnsResult(): void {
		$this->request->method('getParams')->willReturn(['kenmerk' => 'k1', 'payload' => []]);

		$this->osoService->expects($this->once())
			->method('sendExport')
			->willReturn(['ref' => 'MOCK-OSO-1', 'direction' => 'export', 'status' => 'sent']);

		$response = $this->controller->export();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['ref' => 'MOCK-OSO-1', 'direction' => 'export', 'status' => 'sent'], $response->getData());

	}//end testExportReturnsResult()

	/**
	 * An OsoTranslationException maps to 400 `invalid_export`.
	 *
	 * @return void
	 */
	public function testExportMapsTranslationExceptionTo400(): void {
		$this->request->method('getParams')->willReturn(['kenmerk' => 'k1', 'payload' => []]);

		$this->osoService->method('sendExport')->willThrowException(
			new OsoTranslationException(message: 'Required field "targetSchoolBrin" is missing or empty.')
		);

		$response = $this->controller->export();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('invalid_export', $response->getData()['error']);

	}//end testExportMapsTranslationExceptionTo400()

	/**
	 * When no OSO source is configured, the endpoint reports a clean 503 `not_configured`.
	 *
	 * @return void
	 */
	public function testExportReportsNotConfiguredCleanly(): void {
		$this->request->method('getParams')->willReturn(['kenmerk' => 'k1', 'payload' => []]);

		$this->osoService->method('sendExport')->willThrowException(
			new OsoProviderException(message: 'No active OSO source is configured (register "integriq", schema "source", type "oso", isEnabled=true). Configure one before using the OSO bridge.')
		);

		$response = $this->controller->export();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('not_configured', $response->getData()['error']);

	}//end testExportReportsNotConfiguredCleanly()





}//end class

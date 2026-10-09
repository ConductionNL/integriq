<?php

/**
 * Unit tests for RodController.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-09-29-integriq-adapter-rod/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\RodController;
use OCA\Integriq\Exception\RodProviderException;
use OCA\Integriq\Exception\RodTranslationException;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\RodService;
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
 * Tests for the ROD push (berichten) endpoint and the signed inbound retour receiver.
 *
 * @spec openspec/specs/rod-adapter/spec.md#requirement-req-004-push-endpoint-and-signed-retour-receiver
 */
class RodControllerTest extends TestCase {

	/**
	 * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $request;

	/**
	 * @var RodService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $rodService;

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
	 * @var RodController
	 */
	private RodController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->rodService = $this->createMock(RodService::class);
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
	 * @return RodController
	 */
	private function buildController(): RodController {
		return new RodController(
			'integriq',
			$this->request,
			$this->rodService,
			$this->gate,
			$this->userSession,
			$this->actionAuth,
			$this->l,
			$this->logger
		);

	}//end buildController()

	/**
	 * An unauthenticated caller gets 401 without reaching the ROD service.
	 *
	 * @return void
	 */
	public function testBerichtenRequiresAuthentication(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn(null);
		$this->controller = $this->buildController();

		$this->rodService->expects($this->never())->method('sendBericht');

		$response = $this->controller->berichten();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testBerichtenRequiresAuthentication()

	/**
	 * A missing required field (`berichtsoort`/`kenmerk`) is rejected 400 before the service is called.
	 *
	 * @return void
	 */
	public function testBerichtenRequiresBerichtsoortAndKenmerk(): void {
		$this->request->method('getParams')->willReturn(['berichtsoort' => 'inschrijving']);

		$this->rodService->expects($this->never())->method('sendBericht');

		$response = $this->controller->berichten();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('missing_fields', $response->getData()['error']);

	}//end testBerichtenRequiresBerichtsoortAndKenmerk()

	/**
	 * A valid push request returns the service's result verbatim.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/rod-adapter/spec.md#scenario-a-valid-push-request-returns-a-ref-and-status
	 */
	public function testBerichtenReturnsResult(): void {
		$this->request->method('getParams')->willReturn(['berichtsoort' => 'inschrijving', 'kenmerk' => 'k1', 'payload' => []]);

		$this->rodService->expects($this->once())
			->method('sendBericht')
			->willReturn(['ref' => 'MOCK-ROD-1', 'berichtsoort' => 'inschrijving', 'status' => 'sent']);

		$response = $this->controller->berichten();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['ref' => 'MOCK-ROD-1', 'berichtsoort' => 'inschrijving', 'status' => 'sent'], $response->getData());

	}//end testBerichtenReturnsResult()

	/**
	 * A RodTranslationException (incomplete payload) maps to 400 `invalid_bericht`.
	 *
	 * @return void
	 */
	public function testBerichtenMapsTranslationExceptionTo400(): void {
		$this->request->method('getParams')->willReturn(['berichtsoort' => 'inschrijving', 'kenmerk' => 'k1', 'payload' => []]);

		$this->rodService->method('sendBericht')->willThrowException(
			new RodTranslationException(message: 'Required field "leerjaar" is missing or empty.')
		);

		$response = $this->controller->berichten();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('invalid_bericht', $response->getData()['error']);

	}//end testBerichtenMapsTranslationExceptionTo400()

	/**
	 * When no ROD source is configured, the endpoint reports a clean 503 `not_configured`.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/rod-adapter/spec.md#scenario-a-push-request-with-no-active-source-returns-not_configured
	 */
	public function testBerichtenReportsNotConfiguredCleanly(): void {
		$this->request->method('getParams')->willReturn(['berichtsoort' => 'inschrijving', 'kenmerk' => 'k1', 'payload' => []]);

		$this->rodService->method('sendBericht')->willThrowException(
			new RodProviderException(message: 'No active ROD source is configured (register "integriq", schema "source", type "rod", isEnabled=true). Configure one before using the ROD bridge.')
		);

		$response = $this->controller->berichten();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('not_configured', $response->getData()['error']);

	}//end testBerichtenReportsNotConfiguredCleanly()

	/**
	 * A generic transport failure maps to 502.
	 *
	 * @return void
	 */
	public function testBerichtenMapsProviderFailureTo502(): void {
		$this->request->method('getParams')->willReturn(['berichtsoort' => 'inschrijving', 'kenmerk' => 'k1', 'payload' => []]);

		$this->rodService->method('sendBericht')->willThrowException(
			new RodProviderException(message: 'DUO ROD endpoint responded with HTTP 503.')
		);

		$response = $this->controller->berichten();

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		$this->assertSame('rod_send_failed', $response->getData()['error']);

	}//end testBerichtenMapsProviderFailureTo502()




}//end class

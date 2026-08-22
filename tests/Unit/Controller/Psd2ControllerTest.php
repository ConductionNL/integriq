<?php

/**
 * Unit tests for Psd2Controller.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/psd2-ais-bank-feed-connector/tasks.md#task-3
 * @spec openspec/changes/psd2-ais-bank-feed-connector/tasks.md#task-4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\Psd2Controller;
use OCA\Integriq\Exception\Psd2ProviderException;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\BankfeedSyncService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the PSD2 SCA connect/callback + account discovery endpoints.
 *
 * @spec openspec/changes/psd2-ais-bank-feed-connector/specs/psd2-ais-bank-feed-connector/spec.md#requirement-redirect-based-sca-consent-flow-req-002
 */
class Psd2ControllerTest extends TestCase {

	/**
	 * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $request;

	/**
	 * @var BankfeedSyncService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $syncService;

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
	 * @var Psd2Controller
	 */
	private Psd2Controller $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->syncService = $this->createMock(BankfeedSyncService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->actionAuth = $this->createMock(ActionAuthService::class);
		$this->l = $this->createMock(IL10N::class);
		$this->l->method('t')->willReturnArgument(0);
		$this->logger = $this->createMock(LoggerInterface::class);

		$user = $this->createMock(IUser::class);
		$this->userSession->method('getUser')->willReturn($user);

		$this->controller = new Psd2Controller(
			'integriq',
			$this->request,
			$this->syncService,
			$this->userSession,
			$this->actionAuth,
			$this->l,
			$this->logger
		);

	}//end setUp()

	/**
	 * Build a controller whose session has no user.
	 *
	 * @return Psd2Controller
	 */
	private function anonymousController(): Psd2Controller {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		return new Psd2Controller(
			'integriq',
			$this->request,
			$this->syncService,
			$session,
			$this->actionAuth,
			$this->l,
			$this->logger
		);

	}//end anonymousController()

	/**
	 * All three endpoints refuse unauthenticated callers with 401 before any service call.
	 *
	 * @return void
	 */
	public function testEndpointsRequireAuthentication(): void {
		$controller = $this->anonymousController();

		$this->syncService->expects($this->never())->method('connect');
		$this->syncService->expects($this->never())->method('finaliseConsent');
		$this->syncService->expects($this->never())->method('discoverAccounts');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->connect('s', 'i', 'https://r')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->callback('REQ-1')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->discoverAccounts('conn-1')->getStatus());

	}//end testEndpointsRequireAuthentication()

	/**
	 * connect with missing parameters is a 400 without reaching the service.
	 *
	 * @return void
	 */
	public function testConnectRejectsMissingParametersWith400(): void {
		$this->syncService->expects($this->never())->method('connect');

		$response = $this->controller->connect('', '', '');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('missing_parameters', $response->getData()['error']);

	}//end testConnectRejectsMissingParametersWith400()

	/**
	 * connect returns the bank SCA redirect URL from the service — REQ-002.
	 *
	 * @return void
	 */
	public function testConnectReturnsScaRedirectUrl(): void {
		$this->syncService->method('connect')->willReturn(
			[
				'redirectUrl' => 'https://bank.example/sca/REQ-1',
				'reference' => 'REQ-1',
				'connectionId' => 'conn-1',
			]
		);

		$response = $this->controller->connect('bank-aggregator-sandbox', 'SANDBOX_BANK', 'https://nc.example/return');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('https://bank.example/sca/REQ-1', $response->getData()['redirectUrl']);
		$this->assertSame('REQ-1', $response->getData()['reference']);

	}//end testConnectReturnsScaRedirectUrl()

	/**
	 * A provider failure on connect maps to a descriptive 502, never a 500.
	 *
	 * @return void
	 */
	public function testConnectMapsProviderExceptionToBadGateway(): void {
		$this->syncService->method('connect')
			->willThrowException(new Psd2ProviderException(message: 'aggregator unreachable'));

		$response = $this->controller->connect('slug', 'BANK', 'https://r');

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		$this->assertSame('psd2_connect_failed', $response->getData()['error']);

	}//end testConnectMapsProviderExceptionToBadGateway()

	/**
	 * callback without a ref is a 400.
	 *
	 * @return void
	 */
	public function testCallbackRejectsMissingReference(): void {
		$this->syncService->expects($this->never())->method('finaliseConsent');

		$response = $this->controller->callback('');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testCallbackRejectsMissingReference()

	/**
	 * A callback with an unknown/replayed reference is rejected 400 and never
	 * redirects anywhere — CSRF/mix-up + open-redirect defence (REQ-002).
	 *
	 * @return void
	 */
	public function testCallbackRejectsUnknownReferenceWithoutRedirect(): void {
		$this->syncService->method('finaliseConsent')
			->willThrowException(new Psd2ProviderException(message: 'Unknown consent reference'));

		$response = $this->controller->callback('REQ-EVIL');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('psd2_callback_rejected', $response->getData()['error']);

	}//end testCallbackRejectsUnknownReferenceWithoutRedirect()

	/**
	 * A successful callback redirects the browser to the redirectUrl registered
	 * at connect time, carrying the connectionId — REQ-002.
	 *
	 * @return void
	 */
	public function testCallbackRedirectsToRegisteredReturnUrl(): void {
		$this->syncService->method('finaliseConsent')->willReturn(
			['redirectUrl' => 'https://nc.example/apps/shillinq/psd2/return', 'connectionId' => 'conn-1']
		);

		$response = $this->controller->callback('REQ-1');

		$this->assertInstanceOf(RedirectResponse::class, $response);
		$this->assertSame(
			'https://nc.example/apps/shillinq/psd2/return?connectionId=conn-1&consent=granted',
			$response->getRedirectURL()
		);

	}//end testCallbackRedirectsToRegisteredReturnUrl()

	/**
	 * discoverAccounts returns the recorded account set — REQ-003.
	 *
	 * @return void
	 */
	public function testDiscoverAccountsReturnsAccounts(): void {
		$accounts = [
			['iban' => 'NL00BANK0000000001', 'bic' => 'BANKNL2A', 'currency' => 'EUR', 'aggregatorAccountId' => 'ACC-1'],
		];
		$this->syncService->method('discoverAccounts')->willReturn($accounts);

		$response = $this->controller->discoverAccounts('conn-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($accounts, $response->getData()['accounts']);

	}//end testDiscoverAccountsReturnsAccounts()

	/**
	 * A discovery failure maps to a descriptive 502 with the localized prefix.
	 *
	 * @return void
	 */
	public function testDiscoverAccountsMapsFailureToBadGateway(): void {
		$this->syncService->method('discoverAccounts')
			->willThrowException(new Psd2ProviderException(message: 'connection not active'));

		$response = $this->controller->discoverAccounts('conn-1');

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		$this->assertSame('discovery_failed', $response->getData()['error']);
		$this->assertStringContainsString('Account discovery failed', $response->getData()['message']);

	}//end testDiscoverAccountsMapsFailureToBadGateway()
}//end class

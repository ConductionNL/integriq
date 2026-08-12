<?php

/**
 * Unit tests for ZgwVersionTranslateController.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/zgw-version-translation/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Controller;

use OCA\OpenConnector\Controller\ZgwVersionTranslateController;
use OCA\OpenConnector\Exception\ZgwLiteralLeakException;
use OCA\OpenConnector\Exception\ZgwUnknownResourceException;
use OCA\OpenConnector\Exception\ZgwUnknownVersionException;
use OCA\OpenConnector\Exception\ZgwVersionNotImplementedException;
use OCA\OpenConnector\Service\ActionAuthService;
use OCA\OpenConnector\Service\ZgwVersionNegotiationService;
use OCA\OpenConnector\Service\ZgwVersionTranslationService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for `POST /api/zgw-translate` — the orphaned-capability rule
 * (route wired AND test-proven invocation of ZgwVersionTranslationService).
 *
 * @spec openspec/changes/zgw-version-translation/specs/zgw-version-translation/spec.md#requirement-rest-surface-for-sibling-apps-and-external-consumers-req-003
 */
class ZgwVersionTranslateControllerTest extends TestCase {

	/**
	 * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $request;

	/**
	 * @var ZgwVersionTranslationService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $translationService;

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
	 * @var ZgwVersionTranslateController
	 */
	private ZgwVersionTranslateController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getHeader')->willReturn('');
		$this->translationService = $this->createMock(ZgwVersionTranslationService::class);
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
	 * @return ZgwVersionTranslateController
	 */
	private function buildController(): ZgwVersionTranslateController {
		return new ZgwVersionTranslateController(
			'openconnector',
			$this->request,
			$this->translationService,
			new ZgwVersionNegotiationService(),
			$this->userSession,
			$this->actionAuth,
			$this->l,
			$this->logger
		);

	}//end buildController()

	/**
	 * @return void
	 */
	public function testTranslateRequiresAuthentication(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn(null);
		$this->controller = $this->buildController();

		$this->translationService->expects($this->never())->method('translate');

		$response = $this->controller->translate();

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
	}//end testTranslateRequiresAuthentication()

	/**
	 * @return void
	 */
	public function testTranslateRequiresResourceAndPayload(): void {
		$this->request->method('getParams')->willReturn(['resource' => 'zaak']);

		$this->translationService->expects($this->never())->method('translate');

		$response = $this->controller->translate();

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		$this->assertSame(expected: 'missing_fields', actual: $response->getData()['error']);
	}//end testTranslateRequiresResourceAndPayload()

	/**
	 * A valid request returns the translation service's result verbatim,
	 * proving the route actually invokes it (orphaned-capability rule).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/zgw-version-translation/specs/zgw-version-translation/spec.md#scenario-a-valid-translate-request-returns-the-translated-payload
	 */
	public function testTranslateReturnsTranslatedPayload(): void {
		$this->request->method('getParams')->willReturn(
			[
				'resource' => 'status',
				'fromVersion' => '1.0',
				'toVersion' => '1.6',
				'payload' => ['zaak' => 'https://host/zaken/abc'],
			]
		);

		$this->translationService->expects($this->once())
			->method('translate')
			->with('status', '1.0', '1.6', ['zaak' => 'https://host/zaken/abc'])
			->willReturn(['zaak' => 'https://host/zaken/abc', 'betrokkeneType' => 'natuurlijk_persoon']);

		$response = $this->controller->translate();

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$data = $response->getData();
		$this->assertSame(expected: 'status', actual: $data['resource']);
		$this->assertSame(expected: '1.0', actual: $data['fromVersion']);
		$this->assertSame(expected: '1.6', actual: $data['toVersion']);
		$this->assertSame(
			expected: ['zaak' => 'https://host/zaken/abc', 'betrokkeneType' => 'natuurlijk_persoon'],
			actual: $data['payload']
		);
	}//end testTranslateReturnsTranslatedPayload()

	/**
	 * @return void
	 */
	public function testTranslateReturnsUnknownResourceAs400(): void {
		$this->request->method('getParams')->willReturn(['resource' => 'besluittype', 'payload' => []]);

		$this->translationService->method('translate')->willThrowException(
			new ZgwUnknownResourceException(message: 'unknown')
		);

		$response = $this->controller->translate();

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		$this->assertSame(expected: 'unknown_resource', actual: $response->getData()['error']);
	}//end testTranslateReturnsUnknownResourceAs400()

	/**
	 * @return void
	 */
	public function testTranslateReturnsUnknownVersionAs400(): void {
		$this->request->method('getParams')->willReturn(
			['resource' => 'status', 'toVersion' => '0.9', 'payload' => []]
		);

		$this->translationService->method('translate')->willThrowException(
			new ZgwUnknownVersionException(message: 'unknown')
		);

		$response = $this->controller->translate();

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		$this->assertSame(expected: 'unknown_version', actual: $response->getData()['error']);
	}//end testTranslateReturnsUnknownVersionAs400()

	/**
	 * @return void
	 */
	public function testTranslateReturnsNotImplementedAs501(): void {
		$this->request->method('getParams')->willReturn(
			['resource' => 'status', 'toVersion' => '2.0', 'payload' => []]
		);

		$this->translationService->method('translate')->willThrowException(
			new ZgwVersionNotImplementedException(message: 'not implemented')
		);

		$response = $this->controller->translate();

		$this->assertSame(expected: Http::STATUS_NOT_IMPLEMENTED, actual: $response->getStatus());
		$this->assertSame(expected: 'not_implemented', actual: $response->getData()['error']);
	}//end testTranslateReturnsNotImplementedAs501()

	/**
	 * @return void
	 */
	public function testTranslateReturnsLiteralLeakAs422(): void {
		$this->request->method('getParams')->willReturn(
			['resource' => 'zaak', 'toVersion' => '1.6', 'payload' => ['vertrouwelijkheidaanduiding' => 'top-secret']]
		);

		$this->translationService->method('translate')->willThrowException(
			new ZgwLiteralLeakException(message: 'leaked')
		);

		$response = $this->controller->translate();

		$this->assertSame(expected: Http::STATUS_UNPROCESSABLE_ENTITY, actual: $response->getStatus());
		$this->assertSame(expected: 'literal_leak', actual: $response->getData()['error']);
	}//end testTranslateReturnsLiteralLeakAs422()
}//end class

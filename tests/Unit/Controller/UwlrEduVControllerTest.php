<?php

/**
 * Unit tests for UwlrEduVController.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/integriq-adapter-uwlr-eduv/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\UwlrEduVController;
use OCA\Integriq\Exception\UwlrEduVProviderException;
use OCA\Integriq\Exception\UwlrEduVTranslationException;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\UwlrEduVService;
use OCA\Integriq\Service\WebhookSignatureService;
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
 * Tests for the four UWLR/Edu-V/Basispoort/Entree-content push/sync
 * endpoints and the shared signed inbound retour receiver.
 *
 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#requirement-req-008-pushsync-endpoints-and-a-shared-signed-retour-endpoint
 */
class UwlrEduVControllerTest extends TestCase {

	/**
	 * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $request;

	/**
	 * @var UwlrEduVService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $uwlrEduVService;

	/**
	 * @var WebhookSignatureService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $signatureService;

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
	 * @var UwlrEduVController
	 */
	private UwlrEduVController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->uwlrEduVService = $this->createMock(UwlrEduVService::class);
		$this->signatureService = $this->createMock(WebhookSignatureService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->actionAuth = $this->createMock(ActionAuthService::class);
		$this->l = $this->createMock(IL10N::class);
		$this->l->method('t')->willReturnCallback(
			function (string $text, array $params = []): string {
				if ($params === []) {
					return $text;
				}

				return vsprintf($text, $params);
			}
		);
		$this->logger = $this->createMock(LoggerInterface::class);

		$user = $this->createMock(IUser::class);
		$this->userSession->method('getUser')->willReturn($user);

		$this->controller = $this->buildController();

	}//end setUp()

	/**
	 * Build a controller instance wired to the current mocks.
	 *
	 * @return UwlrEduVController
	 */
	private function buildController(): UwlrEduVController {
		return new UwlrEduVController(
			'integriq',
			$this->request,
			$this->uwlrEduVService,
			$this->signatureService,
			$this->userSession,
			$this->actionAuth,
			$this->l,
			$this->logger
		);

	}//end buildController()

	/**
	 * An unauthenticated caller gets 401 without reaching the service, on every send endpoint.
	 *
	 * @return void
	 */
	public function testEachSendEndpointRequiresAuthentication(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn(null);
		$this->controller = $this->buildController();

		$this->uwlrEduVService->expects($this->never())->method('sendUwlrExport');
		$this->uwlrEduVService->expects($this->never())->method('sendEduVExport');
		$this->uwlrEduVService->expects($this->never())->method('syncBasispoort');
		$this->uwlrEduVService->expects($this->never())->method('syncEntreeContent');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->uwlr()->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->eduV()->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->basispoort()->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->entreeContent()->getStatus());

	}//end testEachSendEndpointRequiresAuthentication()

	/**
	 * A missing kenmerk is rejected 400 before the service is called.
	 *
	 * @return void
	 */
	public function testUwlrRequiresKenmerk(): void {
		$this->request->method('getParams')->willReturn([]);

		$this->uwlrEduVService->expects($this->never())->method('sendUwlrExport');

		$response = $this->controller->uwlr();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('missing_fields', $response->getData()['error']);

	}//end testUwlrRequiresKenmerk()

	/**
	 * A valid UWLR export request returns the service's result verbatim.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#scenario-the-uwlr-export-endpoint-returns-a-ref-on-success
	 */
	public function testUwlrReturnsResult(): void {
		$this->request->method('getParams')->willReturn(['kenmerk' => 'k1', 'subtype' => 'pupil', 'payload' => []]);

		$this->uwlrEduVService->expects($this->once())
			->method('sendUwlrExport')
			->willReturn(['ref' => 'MOCK-UWLREDUV-1', 'target' => 'uwlr', 'status' => 'sent']);

		$response = $this->controller->uwlr();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['ref' => 'MOCK-UWLREDUV-1', 'target' => 'uwlr', 'status' => 'sent'], $response->getData());

	}//end testUwlrReturnsResult()

	/**
	 * A valid Edu-V export request returns the service's result verbatim.
	 *
	 * @return void
	 */
	public function testEduVReturnsResult(): void {
		$this->request->method('getParams')->willReturn(['kenmerk' => 'k1', 'dataService' => 'onderwijsdeelnemers', 'payload' => []]);

		$this->uwlrEduVService->expects($this->once())
			->method('sendEduVExport')
			->willReturn(['ref' => 'MOCK-UWLREDUV-2', 'target' => 'edu-v', 'status' => 'sent']);

		$response = $this->controller->eduV();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testEduVReturnsResult()

	/**
	 * A valid Basispoort sync request returns the service's result verbatim.
	 *
	 * @return void
	 */
	public function testBasispoortReturnsResult(): void {
		$this->request->method('getParams')->willReturn(['kenmerk' => 'k1', 'payload' => []]);

		$this->uwlrEduVService->expects($this->once())
			->method('syncBasispoort')
			->willReturn(['ref' => 'MOCK-UWLREDUV-3', 'target' => 'basispoort', 'status' => 'sent']);

		$response = $this->controller->basispoort();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testBasispoortReturnsResult()

	/**
	 * A valid Entree content sync request returns the service's result verbatim.
	 *
	 * @return void
	 */
	public function testEntreeContentReturnsResult(): void {
		$this->request->method('getParams')->willReturn(['kenmerk' => 'k1', 'payload' => []]);

		$this->uwlrEduVService->expects($this->once())
			->method('syncEntreeContent')
			->willReturn(['ref' => 'MOCK-UWLREDUV-4', 'target' => 'entree-content', 'status' => 'sent']);

		$response = $this->controller->entreeContent();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testEntreeContentReturnsResult()

	/**
	 * A UwlrEduVTranslationException maps to 400 `invalid_export`.
	 *
	 * @return void
	 */
	public function testUwlrMapsTranslationExceptionTo400(): void {
		$this->request->method('getParams')->willReturn(['kenmerk' => 'k1', 'subtype' => 'pupil', 'payload' => []]);

		$this->uwlrEduVService->method('sendUwlrExport')->willThrowException(
			new UwlrEduVTranslationException(message: 'Required field "eckId" is missing or empty.')
		);

		$response = $this->controller->uwlr();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('invalid_export', $response->getData()['error']);

	}//end testUwlrMapsTranslationExceptionTo400()

	/**
	 * When no UWLR/Edu-V source is configured, the endpoint reports a clean 503 `not_configured`.
	 *
	 * @return void
	 */
	public function testUwlrReportsNotConfiguredCleanly(): void {
		$this->request->method('getParams')->willReturn(['kenmerk' => 'k1', 'subtype' => 'pupil', 'payload' => []]);

		$this->uwlrEduVService->method('sendUwlrExport')->willThrowException(
			new UwlrEduVProviderException(message: 'No active UWLR/Edu-V source is configured (register "integriq", schema "source", type "uwlr-eduv", isEnabled=true). Configure one before using this bridge.')
		);

		$response = $this->controller->uwlr();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('not_configured', $response->getData()['error']);

	}//end testUwlrReportsNotConfiguredCleanly()

	/**
	 * No source configured at all fails the inbound retour webhook closed (401).
	 *
	 * @return void
	 */
	public function testRetourWithNoSourceConfiguredReturns401(): void {
		$this->uwlrEduVService->method('resolveActiveSource')
			->willThrowException(new UwlrEduVProviderException(message: 'no source'));
		$this->signatureService->expects($this->never())->method('verify');

		$response = $this->controller->retour();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testRetourWithNoSourceConfiguredReturns401()

	/**
	 * An unsigned/tampered retour request is rejected 401 before any state change.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#scenario-an-unsigned-retour-is-rejected-before-processing
	 */
	public function testRetourInvalidSignatureReturns401BeforeAnySideEffect(): void {
		$source = new ObjectEntity();
		$source->setObject(['configuration' => ['webhookSignature' => ['secret' => 'whsec_test']]]);
		$this->uwlrEduVService->method('resolveActiveSource')->willReturn($source);
		$this->signatureService->method('verify')->willReturn(false);

		$this->uwlrEduVService->expects($this->never())->method('receiveReturn');

		$response = $this->controller->retour();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('invalid signature', $response->getData()['error']);

	}//end testRetourInvalidSignatureReturns401BeforeAnySideEffect()

	/**
	 * A verified retour request is routed to receiveReturn() and always acknowledges receipt.
	 *
	 * @return void
	 */
	public function testRetourVerifiedIsRoutedAndAcknowledged(): void {
		$source = new ObjectEntity();
		$source->setObject(['configuration' => ['webhookSignature' => ['secret' => 'whsec_test']]]);
		$this->uwlrEduVService->method('resolveActiveSource')->willReturn($source);
		$this->signatureService->method('verify')->willReturn(true);

		$this->uwlrEduVService->expects($this->once())->method('receiveReturn');

		$response = $this->controller->retour();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['received']);

	}//end testRetourVerifiedIsRoutedAndAcknowledged()

	/**
	 * A processing exception after a verified signature never surfaces as a 500.
	 *
	 * @return void
	 */
	public function testRetourNeverCrashesOnProcessingException(): void {
		$source = new ObjectEntity();
		$source->setObject(['configuration' => ['webhookSignature' => ['secret' => 'whsec_test']]]);
		$this->uwlrEduVService->method('resolveActiveSource')->willReturn($source);
		$this->signatureService->method('verify')->willReturn(true);
		$this->uwlrEduVService->method('receiveReturn')->willThrowException(new RuntimeException('boom'));

		$response = $this->controller->retour();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['received']);

	}//end testRetourNeverCrashesOnProcessingException()
}//end class

<?php

/**
 * Unit tests for OpenFormulierenController.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/open-formulieren-intake/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\OpenFormulierenController;
use OCA\Integriq\Exception\OpenFormulierenException;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\OpenFormulierenIntakeService;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\HandoffException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the signed inbound submission webhook, status read, and the
 * authenticated handoff-trigger endpoint.
 *
 * @spec openspec/changes/open-formulieren-intake/specs/open-formulieren-intake/spec.md
 */
class OpenFormulierenControllerTest extends TestCase {

	/** @var IRequest|\PHPUnit\Framework\MockObject\MockObject */
	private $request;

	/** @var OpenFormulierenIntakeService|\PHPUnit\Framework\MockObject\MockObject */
	private $intakeService;

	/** @var WebhookSignatureService|\PHPUnit\Framework\MockObject\MockObject */
	private $signatureService;

	/** @var IUserSession|\PHPUnit\Framework\MockObject\MockObject */
	private $userSession;

	/** @var ActionAuthService|\PHPUnit\Framework\MockObject\MockObject */
	private $actionAuth;

	/** @var IL10N|\PHPUnit\Framework\MockObject\MockObject */
	private $l;

	/** @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject */
	private $logger;

	private OpenFormulierenController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->intakeService = $this->createMock(OpenFormulierenIntakeService::class);
		$this->signatureService = $this->createMock(WebhookSignatureService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->actionAuth = $this->createMock(ActionAuthService::class);
		$this->l = $this->createMock(IL10N::class);
		$this->l->method('t')->willReturnArgument(0);
		$this->logger = $this->createMock(LoggerInterface::class);

		$user = $this->createMock(IUser::class);
		$this->userSession->method('getUser')->willReturn($user);

		$this->controller = $this->buildController();

	}//end setUp()

	private function buildController(): OpenFormulierenController {
		return new OpenFormulierenController(
			'openconnector',
			$this->request,
			$this->intakeService,
			$this->signatureService,
			$this->userSession,
			$this->actionAuth,
			$this->l,
			$this->logger
		);

	}//end buildController()

	/**
	 * @spec openspec/changes/open-formulieren-intake/specs/open-formulieren-intake/spec.md#scenario-no-active-source-configured-fails-closed
	 */
	public function testInboundWithNoSourceConfiguredReturns401(): void {
		$this->intakeService->method('resolveActiveSource')->willThrowException(
			new OpenFormulierenException('no source')
		);
		$this->intakeService->expects($this->never())->method('ingest');

		$response = $this->controller->inbound();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testInboundWithNoSourceConfiguredReturns401()

	/**
	 * @spec openspec/changes/open-formulieren-intake/specs/open-formulieren-intake/spec.md#scenario-invalid-signature-is-rejected
	 */
	public function testInboundInvalidSignatureReturns401BeforeAnySideEffect(): void {
		$source = new ObjectEntity();
		$source->setObject(['configuration' => ['webhookSignature' => ['secret' => 'whsec_test']]]);
		$this->intakeService->method('resolveActiveSource')->willReturn($source);
		$this->signatureService->method('verify')->willReturn(false);

		$this->intakeService->expects($this->never())->method('ingest');

		$response = $this->controller->inbound();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('invalid signature', $response->getData()['error']);

	}//end testInboundInvalidSignatureReturns401BeforeAnySideEffect()

	/**
	 * @spec openspec/changes/open-formulieren-intake/specs/open-formulieren-intake/spec.md#scenario-missing-signature-is-rejected
	 */
	public function testInboundMissingSignatureReturns401(): void {
		$source = new ObjectEntity();
		$source->setObject(['configuration' => ['webhookSignature' => ['secret' => 'whsec_test']]]);
		$this->intakeService->method('resolveActiveSource')->willReturn($source);
		$this->request->method('getHeader')->willReturn('');
		$this->signatureService->method('verify')->willReturn(false);

		$this->intakeService->expects($this->never())->method('ingest');

		$response = $this->controller->inbound();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testInboundMissingSignatureReturns401()

	/**
	 * @spec openspec/changes/open-formulieren-intake/specs/open-formulieren-intake/spec.md#scenario-valid-signature-is-accepted
	 */
	public function testInboundValidSignatureIngestsSubmission(): void {
		$source = new ObjectEntity();
		$source->setObject(['configuration' => ['webhookSignature' => ['secret' => 'whsec_test']]]);
		$this->intakeService->method('resolveActiveSource')->willReturn($source);
		$this->signatureService->method('verify')->willReturn(true);
		$this->request->method('getParams')->willReturn(
			[
				'form' => ['slug' => 'vergunning-aanvraag', 'uuid' => 'form-uuid-1'],
				'submission' => ['uuid' => 'of-sub-1'],
				'values' => ['aanvraagType' => 'kapvergunning'],
			]
		);

		$submission = new ObjectEntity();
		$submission->setUuid('sub-1');
		$submission->setObject(['status' => 'mapped']);

		$this->intakeService->expects($this->once())
			->method('ingest')
			->with('vergunning-aanvraag', 'form-uuid-1', ['uuid' => 'of-sub-1'], ['aanvraagType' => 'kapvergunning'], [], null)
			->willReturn($submission);

		$response = $this->controller->inbound();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('sub-1', $response->getData()['id']);

	}//end testInboundValidSignatureIngestsSubmission()

	public function testInboundMissingFormSlugReturns400(): void {
		$source = new ObjectEntity();
		$source->setObject(['configuration' => ['webhookSignature' => ['secret' => 'whsec_test']]]);
		$this->intakeService->method('resolveActiveSource')->willReturn($source);
		$this->signatureService->method('verify')->willReturn(true);
		$this->request->method('getParams')->willReturn(['values' => []]);

		$this->intakeService->expects($this->never())->method('ingest');

		$response = $this->controller->inbound();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testInboundMissingFormSlugReturns400()

	public function testStatusRequiresAuthentication(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn(null);
		$this->controller = $this->buildController();

		$response = $this->controller->status('sub-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testStatusRequiresAuthentication()

	public function testHandoffRequiresAuthentication(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn(null);
		$this->controller = $this->buildController();

		$this->intakeService->expects($this->never())->method('handoff');

		$response = $this->controller->handoff('sub-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testHandoffRequiresAuthentication()

	/**
	 * @spec openspec/changes/open-formulieren-intake/specs/open-formulieren-intake/spec.md#scenario-authenticated-handoff-succeeds
	 */
	public function testHandoffSucceedsUnderCallerSession(): void {
		$this->intakeService->expects($this->once())
			->method('handoff')
			->with('sub-1')
			->willReturn(['status' => 'executed', 'target' => ['uuid' => 'case-1'], 'correlationId' => 'corr-1']);

		$response = $this->controller->handoff('sub-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('executed', $response->getData()['status']);

	}//end testHandoffSucceedsUnderCallerSession()

	/**
	 * @spec openspec/changes/open-formulieren-intake/specs/open-formulieren-intake/spec.md#scenario-no-ns-case-provider-installed-degrades-to-queued-not-an-error
	 */
	public function testHandoffProviderUnavailableMapsToConflict(): void {
		$this->intakeService->method('handoff')->willThrowException(
			new HandoffException(errorCode: HandoffException::PROVIDER_UNAVAILABLE, message: 'no provider')
		);

		$response = $this->controller->handoff('sub-1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(HandoffException::PROVIDER_UNAVAILABLE, $response->getData()['error']);

	}//end testHandoffProviderUnavailableMapsToConflict()

	public function testHandoffNotDeclaredMapsToNotFound(): void {
		$this->intakeService->method('handoff')->willThrowException(
			new HandoffException(errorCode: HandoffException::NOT_DECLARED, message: 'not declared')
		);

		$response = $this->controller->handoff('sub-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testHandoffNotDeclaredMapsToNotFound()

	/**
	 * @spec openspec/changes/open-formulieren-intake/specs/open-formulieren-intake/spec.md#scenario-handoff-failure-isolates-to-the-triggering-submission
	 */
	public function testHandoffRbacRefusalMapsToForbidden(): void {
		$this->intakeService->method('handoff')->willThrowException(
			new NotAuthorizedException('not authorized')
		);

		$response = $this->controller->handoff('sub-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testHandoffRbacRefusalMapsToForbidden()

	public function testHandoffNotReadyMapsToBadRequest(): void {
		$this->intakeService->method('handoff')->willThrowException(
			new OpenFormulierenException('not mapped yet')
		);

		$response = $this->controller->handoff('sub-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testHandoffNotReadyMapsToBadRequest()

	public function testHandoffUnexpectedFailureNeverCrashes(): void {
		$this->intakeService->method('handoff')->willThrowException(new \RuntimeException('boom'));

		$response = $this->controller->handoff('sub-1');

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());

	}//end testHandoffUnexpectedFailureNeverCrashes()
}//end class

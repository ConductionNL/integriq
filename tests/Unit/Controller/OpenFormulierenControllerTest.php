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
use OCA\Integriq\Exception\DsoConnectionUnavailableException;
use OCA\Integriq\Exception\DsoSignatureException;
use OCA\Integriq\Exception\OpenFormulierenException;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\OpenFormulierenIntakeService;
use OCA\Integriq\Service\Dso\DsoConnectionAlerts;
use OCA\Integriq\Service\Dso\DsoIdentity;
use OCA\Integriq\Service\OpenFormulieren\DestinationSubmitterInterface;
use OCA\Integriq\Service\OpenFormulieren\OpenFormulierenConnection;
use OCA\OpenRegister\Exception\FormSubmitRefusedException;
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

	/** @var OpenFormulierenConnection|\PHPUnit\Framework\MockObject\MockObject */
	private $connection;

	/** @var IUserSession|\PHPUnit\Framework\MockObject\MockObject */
	private $userSession;

	/** @var ActionAuthService|\PHPUnit\Framework\MockObject\MockObject */
	private $actionAuth;

	/** @var IL10N|\PHPUnit\Framework\MockObject\MockObject */
	private $l;

	/** @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject */
	private $logger;

	private $submitter;

	private OpenFormulierenController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->intakeService = $this->createMock(OpenFormulierenIntakeService::class);
		$this->connection = $this->createMock(OpenFormulierenConnection::class);
		$this->submitter = $this->createMock(DestinationSubmitterInterface::class);
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
			'integriq',
			$this->request,
			$this->intakeService,
			$this->connection,
			$this->createMock(DsoConnectionAlerts::class),
			$this->userSession,
			$this->actionAuth,
			$this->l,
			$this->logger,
			$this->submitter
		);

	}//end buildController()

	/**
	 * No usable Open Formulieren connection answers 503 before any state change.
	 *
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/specs/open-formulieren-intake/spec.md#scenario-no-active-source-configured-fails-closed
	 */
	public function testInboundWithNoConnectionReturns503(): void {
		$this->connection->method('authenticate')->willThrowException(
			new DsoConnectionUnavailableException(
				reason: DsoConnectionUnavailableException::NO_CONNECTION,
				message: 'none',
				channel: DsoConnectionUnavailableException::CHANNEL_OPEN_FORMULIEREN
			)
		);
		$this->submitter->expects($this->never())->method('submit');

		$response = $this->controller->inbound();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('openformulieren_connection_not_configured', $response->getData()['error']);

	}//end testInboundWithNoConnectionReturns503()

	/**
	 * @spec openspec/changes/open-formulieren-intake/specs/open-formulieren-intake/spec.md#scenario-invalid-signature-is-rejected
	 */
	public function testInboundInvalidSignatureReturns401BeforeAnySideEffect(): void {
		$this->connection->method('authenticate')->willThrowException(new DsoSignatureException('bad'));
		$this->submitter->expects($this->never())->method('submit');

		$response = $this->controller->inbound();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('invalid signature', $response->getData()['error']);

	}//end testInboundInvalidSignatureReturns401BeforeAnySideEffect()

	/**
	 * A valid submit creates its case as the connection's account and answers 201
	 * with the case reference; nothing is staged for later.
	 *
	 * @spec openspec/changes/open-formulieren-submits-into-its-case/specs/open-formulieren-intake/spec.md#scenario-a-valid-submit-returns-the-case-reference
	 */
	public function testInboundCreatesTheCaseAsTheConnectionAccountAndAnswers201(): void {
		$identity = $this->signedSubmit();
		$this->connection->expects($this->once())->method('runAs')
			->with($identity->account, $this->isType('callable'))
			->willReturnCallback(static fn ($account, callable $operation): mixed => $operation());
		$this->intakeService->expects($this->never())->method('ingest');
		$this->submitter->expects($this->once())
			->method('submit')
			->with('vergunning-aanvraag', 'of-sub-1', ['aanvraagType' => 'kapvergunning'], hash('sha256', ''))
			->willReturn(['reference' => '2026-0042', 'id' => 'case-uuid-1']);

		$response = $this->controller->inbound();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['reference' => '2026-0042', 'id' => 'case-uuid-1'], $response->getData());

	}//end testInboundCreatesTheCaseAsTheConnectionAccountAndAnswers201()

	/**
	 * A refused submit answers OpenRegister's 422 with its findings, so Open
	 * Formulieren reports the failure on its side.
	 *
	 * @spec openspec/changes/open-formulieren-submits-into-its-case/specs/open-formulieren-intake/spec.md#requirement-an-open-formulieren-submit-must-create-its-case-in-the-inbound-request
	 */
	public function testInboundRefusedSubmitAnswers422WithFindings(): void {
		$this->signedSubmit();
		$this->connection->method('runAs')->willReturnCallback(static fn ($account, callable $operation): mixed => $operation());
		$finding = ['property' => 'caseType', 'code' => 'required-unmapped', 'message' => 'caseType is required'];
		$this->submitter->method('submit')->willThrowException(new FormSubmitRefusedException('Refused', 422, [$finding]));

		$response = $this->controller->inbound();

		$this->assertSame(422, $response->getStatus());
		$this->assertSame('submission_refused', $response->getData()['error']);
		$this->assertSame([$finding], $response->getData()['findings']);

	}//end testInboundRefusedSubmitAnswers422WithFindings()

	/**
	 * A refusal OpenRegister answers with 503 is "not stored": 503 so the sender retries.
	 *
	 * @spec openspec/changes/open-formulieren-submits-into-its-case/specs/open-formulieren-intake/spec.md#requirement-an-open-formulieren-submit-must-create-its-case-in-the-inbound-request
	 */
	public function testInboundUnavailableDestinationAnswers503(): void {
		$this->signedSubmit();
		$this->connection->method('runAs')->willReturnCallback(static fn ($account, callable $operation): mixed => $operation());
		$this->submitter->method('submit')->willThrowException(new FormSubmitRefusedException('Try again later', 503));

		$response = $this->controller->inbound();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());

	}//end testInboundUnavailableDestinationAnswers503()

	/**
	 * A signed submit of form `vergunning-aanvraag`, submission `of-sub-1`.
	 *
	 * @return DsoIdentity The identity the connection authenticates.
	 */
	private function signedSubmit(): DsoIdentity {
		$identity = $this->identity();
		$this->connection->method('authenticate')->willReturn($identity);
		$this->request->method('getParams')->willReturn(
			[
				'form' => ['slug' => 'vergunning-aanvraag', 'uuid' => 'form-uuid-1'],
				'submission' => ['uuid' => 'of-sub-1'],
				'values' => ['aanvraagType' => 'kapvergunning'],
			]
		);

		return $identity;

	}//end signedSubmit()

	public function testInboundMissingFormSlugReturns400(): void {
		$this->connection->method('authenticate')->willReturn($this->identity());
		$this->request->method('getParams')->willReturn(['values' => []]);

		$this->submitter->expects($this->never())->method('submit');

		$response = $this->controller->inbound();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testInboundMissingFormSlugReturns400()

	/**
	 * An identity for the Open Formulieren connection account.
	 *
	 * @return DsoIdentity The identity.
	 */
	private function identity(): DsoIdentity {
		$account = $this->createMock(IUser::class);
		$account->method('getUID')->willReturn('of-intake');

		return new DsoIdentity(account: $account, consumerUuid: 'consumer-of');

	}//end identity()

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

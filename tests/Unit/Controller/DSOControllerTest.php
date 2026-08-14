<?php

/**
 * Unit tests for DSOController.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/dso-stam-pkioverheid-signature-verification/tasks.md#task-3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Controller;

use OCA\OpenConnector\Controller\DSOController;
use OCA\OpenConnector\Exception\DsoProviderException;
use OCA\OpenConnector\Exception\DsoTranslationException;
use OCA\OpenConnector\Service\ActionAuthService;
use OCA\OpenConnector\Service\DsoIngestService;
use OCA\OpenConnector\Service\DSOParserService;
use OCA\OpenConnector\Service\DSOSignatureVerifierService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the DSO STAM koppelvlak controller plus the authenticated
 * read/handoff/outbound surface added by dso-connector-adapter.
 *
 * @spec openspec/changes/dso-stam-pkioverheid-signature-verification/tasks.md#task-3
 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md
 */
class DSOControllerTest extends TestCase {

	/**
	 * @var \PHPUnit\Framework\MockObject\MockObject|IRequest
	 */
	private $request;

	/**
	 * @var \PHPUnit\Framework\MockObject\MockObject|DSOParserService
	 */
	private $parser;

	/**
	 * @var \PHPUnit\Framework\MockObject\MockObject|LoggerInterface
	 */
	private $logger;

	/**
	 * @var \PHPUnit\Framework\MockObject\MockObject|DSOSignatureVerifierService
	 */
	private $signatureVerifier;

	/**
	 * @var \PHPUnit\Framework\MockObject\MockObject|DsoIngestService
	 */
	private $ingestService;

	/**
	 * @var \PHPUnit\Framework\MockObject\MockObject|ActionAuthService
	 */
	private $actionAuth;

	/**
	 * @var \PHPUnit\Framework\MockObject\MockObject|IUserSession
	 */
	private $userSession;

	/**
	 * @var \PHPUnit\Framework\MockObject\MockObject|IL10N
	 */
	private $l;

	/**
	 * @var DSOController
	 */
	private DSOController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->parser = $this->createMock(DSOParserService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->signatureVerifier = $this->createMock(DSOSignatureVerifierService::class);
		$this->ingestService = $this->createMock(DsoIngestService::class);
		$this->actionAuth = $this->createMock(ActionAuthService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->l = $this->createMock(IL10N::class);
		$this->l->method('t')->willReturnArgument(0);

		// Default: signature verification passes, so tests only exercising
		// payload handling do not need to restate this every time.
		$this->signatureVerifier->method('verify')->willReturn(true);

		// Default: an authenticated user, so authenticated-surface tests do
		// not need to restate this every time.
		$user = $this->createMock(IUser::class);
		$this->userSession->method('getUser')->willReturn($user);

		$this->controller = $this->buildController();

	}//end setUp()

	/**
	 * Build a DSOController with the current mock collaborators.
	 *
	 * @return DSOController
	 */
	private function buildController(): DSOController {
		return new DSOController(
			appName: 'openconnector',
			request: $this->request,
			parser: $this->parser,
			logger: $this->logger,
			signatureVerifier: $this->signatureVerifier,
			ingestService: $this->ingestService,
			actionAuth: $this->actionAuth,
			userSession: $this->userSession,
			l: $this->l
		);

	}//end buildController()

	/**
	 * Test that a request whose signature fails verification returns 401.
	 *
	 * @return void
	 */
	public function testInvalidSignatureReturns401(): void {
		$this->signatureVerifier = $this->createMock(DSOSignatureVerifierService::class);
		$this->signatureVerifier->method('verify')->willReturn(false);
		$this->controller = $this->buildController();

		$body = [
			'verzoekId' => 'dso-12345',
			'type' => 'aanvraag',
			'submissionDate' => '2024-06-15',
			'aanvrager' => ['bsn' => '999993653'],
			'locatie' => ['bagAdres' => []],
			'activiteiten' => [['code' => 'bouwen-01']],
		];

		$this->request->method('getParams')->willReturn($body);
		$this->request->method('getHeader')->willReturn('');

		$response = $this->controller->receiveVerzoek();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

		$data = $response->getData();
		$this->assertSame('invalid_signature', $data['error']);

	}//end testInvalidSignatureReturns401()

	/**
	 * Test that a request with no `X-DSO-Signature` header (which the real
	 * verifier will always reject) returns 401.
	 *
	 * @return void
	 */
	public function testMissingSignatureHeaderReturns401(): void {
		$this->signatureVerifier = $this->createMock(DSOSignatureVerifierService::class);
		$this->signatureVerifier->method('verify')
			->willReturnCallback(static fn (?string $sig, string $body): bool => ($sig !== null && $sig !== ''));
		$this->controller = $this->buildController();

		$this->request->method('getParams')->willReturn([]);
		$this->request->method('getHeader')->willReturn('');

		$response = $this->controller->receiveVerzoek();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testMissingSignatureHeaderReturns401()

	/**
	 * Test that a valid verzoek with a verified signature returns 202.
	 *
	 * @return void
	 */
	public function testValidVerzoekWithSignatureReturns202(): void {
		$body = [
			'verzoekId' => 'dso-12345',
			'type' => 'aanvraag',
			'submissionDate' => '2024-06-15',
			'aanvrager' => ['bsn' => '999993653'],
			'locatie' => ['bagAdres' => []],
			'activiteiten' => [['code' => 'bouwen-01']],
		];

		$this->request->method('getParams')->willReturn($body);
		$this->request->method('getHeader')
			->willReturnCallback(
				static function (string $header): string {
					if ($header === 'X-DSO-Signature') {
						return 'sha256=abc123';
					}

					return '';
				}
			);

		$this->parser->method('validatePayload')->willReturn([]);
		$this->parser->method('parseVerzoek')->willReturn(
			array_merge($body, ['status' => 'ontvangen'])
		);

		$response = $this->controller->receiveVerzoek();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());

		$data = $response->getData();
		$this->assertArrayHasKey('verzoekId', $data);
		$this->assertSame('ontvangen', $data['status']);

	}//end testValidVerzoekWithSignatureReturns202()

	/**
	 * Test that a payload validation failure returns 400.
	 *
	 * @return void
	 */
	public function testValidationFailureReturns400(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->request->method('getHeader')
			->willReturnCallback(
				static function (string $header): string {
					if ($header === 'X-DSO-Signature') {
						return 'sha256=placeholder';
					}

					return '';
				}
			);

		$validationErrors = [
			[
				'field' => 'activiteiten',
				'error' => 'required_field_missing',
				'message' => 'Activiteiten is verplicht',
			],
		];

		$this->parser->method('validatePayload')->willReturn($validationErrors);

		$response = $this->controller->receiveVerzoek();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

		$data = $response->getData();
		$this->assertSame('validation_failed', $data['error']);
		$this->assertNotEmpty($data['errors']);

	}//end testValidationFailureReturns400()

	/**
	 * Test that verzoekId is preserved from the parsed payload.
	 *
	 * @return void
	 */
	public function testVerzoekIdPreservedFromParsedPayload(): void {
		$body = ['verzoekId' => 'test-id-999'];

		$this->request->method('getParams')->willReturn($body);
		$this->request->method('getHeader')
			->willReturnCallback(
				static function (string $header): string {
					if ($header === 'X-DSO-Signature') {
						return 'sha256=placeholder';
					}

					return '';
				}
			);

		$this->parser->method('validatePayload')->willReturn([]);
		$this->parser->method('parseVerzoek')->willReturn(['verzoekId' => 'test-id-999', 'type' => 'aanvraag']);

		$response = $this->controller->receiveVerzoek();

		$data = $response->getData();
		$this->assertSame('test-id-999', $data['verzoekId']);

	}//end testVerzoekIdPreservedFromParsedPayload()

	/**
	 * Test that environment header is accepted without error.
	 *
	 * @return void
	 */
	public function testEnvironmentHeaderTaggedOnVerzoek(): void {
		$body = ['verzoekId' => 'dso-env-test'];

		$this->request->method('getParams')->willReturn($body);
		$this->request->method('getHeader')
			->willReturnCallback(
				static function (string $header): string {
					if ($header === 'X-DSO-Environment') {
						return 'pre-productie';
					}

					if ($header === 'X-DSO-Signature') {
						return 'sha256=placeholder';
					}

					return '';
				}
			);

		$this->parser->method('validatePayload')->willReturn([]);
		$this->parser->method('parseVerzoek')->willReturn(['verzoekId' => 'dso-env-test', 'type' => 'aanvraag']);

		$response = $this->controller->receiveVerzoek();

		$this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());

	}//end testEnvironmentHeaderTaggedOnVerzoek()

	/**
	 * Test that a valid, signed verzoek is persisted via
	 * DsoIngestService::ingest() — the pre-existing gap this change fixes
	 * (previously the controller only logged and dropped the verzoek).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-dso_verzoek-lifecycle-with-per-verzoek-isolation-req-003
	 */
	public function testReceiveVerzoekPersistsViaIngestService(): void {
		$body = [
			'verzoekId' => 'dso-12345',
			'type' => 'aanvraag',
			'submissionDate' => '2024-06-15',
			'aanvrager' => ['bsn' => '999993653'],
			'locatie' => ['bagAdres' => []],
			'activiteiten' => [['code' => 'bouwen-01']],
		];

		$this->request->method('getParams')->willReturn($body);
		$this->request->method('getHeader')
			->willReturnCallback(
				static function (string $header): string {
					if ($header === 'X-DSO-Signature') {
						return 'sha256=abc123';
					}

					return '';
				}
			);

		$this->parser->method('validatePayload')->willReturn([]);
		$this->parser->method('parseVerzoek')->willReturn(array_merge($body, ['status' => 'ontvangen']));

		$this->ingestService->expects($this->once())
			->method('ingest')
			->with($this->callback(static fn (array $parsedRequest): bool => ($parsedRequest['verzoekId'] === 'dso-12345')));

		$response = $this->controller->receiveVerzoek();

		$this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());

	}//end testReceiveVerzoekPersistsViaIngestService()

	/**
	 * Test that an ingest failure never prevents the webhook's 202
	 * acknowledgement — the STAM koppelvlak's asynchronous-processing
	 * contract must not regress just because persistence/mapping failed.
	 *
	 * @return void
	 */
	public function testIngestFailureDoesNotBreakTheWebhookAcknowledgement(): void {
		$body = ['verzoekId' => 'dso-boom'];

		$this->request->method('getParams')->willReturn($body);
		$this->request->method('getHeader')
			->willReturnCallback(
				static function (string $header): string {
					if ($header === 'X-DSO-Signature') {
						return 'sha256=abc123';
					}

					return '';
				}
			);

		$this->parser->method('validatePayload')->willReturn([]);
		$this->parser->method('parseVerzoek')->willReturn($body);
		$this->ingestService->method('ingest')->willThrowException(new DsoTranslationException(message: 'boom'));

		$response = $this->controller->receiveVerzoek();

		$this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());

	}//end testIngestFailureDoesNotBreakTheWebhookAcknowledgement()

	/**
	 * Test that listVerzoeken() returns 401 when unauthenticated.
	 *
	 * @return void
	 */
	public function testListVerzoekenReturns401WhenUnauthenticated(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn(null);
		$this->controller = $this->buildController();

		$response = $this->controller->listVerzoeken();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testListVerzoekenReturns401WhenUnauthenticated()

	/**
	 * Test that listVerzoeken() delegates to the ingest service and returns
	 * its results.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-rest-surface-to-list-and-complete-mapped-verzoeken-req-004
	 */
	public function testListVerzoekenReturnsResults(): void {
		$this->request->method('getParam')->with('status')->willReturn('mapped');
		$this->ingestService->method('listVerzoeken')
			->with('mapped')
			->willReturn([['id' => 'v1', 'status' => 'mapped']]);

		$response = $this->controller->listVerzoeken();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $response->getData()['results']);

	}//end testListVerzoekenReturnsResults()

	/**
	 * Test that status() returns 404 for an unknown verzoek.
	 *
	 * @return void
	 */
	public function testStatusReturns404ForUnknownVerzoek(): void {
		$this->ingestService->method('getVerzoek')
			->willThrowException(new DsoTranslationException(message: 'no such verzoek'));

		$response = $this->controller->status(id: 'unknown');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testStatusReturns404ForUnknownVerzoek()

	/**
	 * Test that status() returns the verzoek record on success.
	 *
	 * @return void
	 */
	public function testStatusReturnsVerzoekRecord(): void {
		$this->ingestService->method('getVerzoek')
			->with('v1')
			->willReturn(['id' => 'v1', 'status' => 'mapped']);

		$response = $this->controller->status(id: 'v1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('mapped', $response->getData()['status']);

	}//end testStatusReturnsVerzoekRecord()

	/**
	 * Test that handoff() returns 400 when the verzoek is not yet mapped.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-declared-ns-case-handoff-executed-by-a-real-authenticated-actor-req-005
	 */
	public function testHandoffReturns400WhenNotReady(): void {
		$this->ingestService->method('handoff')
			->willThrowException(new DsoTranslationException(message: 'not mapped yet'));

		$response = $this->controller->handoff(id: 'v1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testHandoffReturns400WhenNotReady()

	/**
	 * Test that handoff() returns the engine's execute() result on success.
	 *
	 * @return void
	 */
	public function testHandoffReturnsExecuteResultOnSuccess(): void {
		$this->ingestService->method('handoff')
			->with('v1')
			->willReturn(['status' => 'executed', 'target' => ['uuid' => 'case-1'], 'correlationId' => 'corr-1']);

		$response = $this->controller->handoff(id: 'v1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('executed', $response->getData()['status']);

	}//end testHandoffReturnsExecuteResultOnSuccess()

	/**
	 * Test that postOutbound() maps a "no active source" failure to 503.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-outbound-status-besluit-post-with-per-message-audit-req-006
	 */
	public function testPostOutboundReturns503WhenNotConfigured(): void {
		$this->request->method('getParam')->with('type', 'status')->willReturn('status');
		$this->request->method('getParams')->willReturn(['type' => 'status']);
		$this->ingestService->method('postOutbound')
			->willThrowException(new DsoProviderException(message: 'No active DSO source is configured'));

		$response = $this->controller->postOutbound(id: 'v1');

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());

	}//end testPostOutboundReturns503WhenNotConfigured()

	/**
	 * Test that postOutbound() returns the dispatch outcome on success.
	 *
	 * @return void
	 */
	public function testPostOutboundReturnsDispatchOutcomeOnSuccess(): void {
		$this->request->method('getParam')->with('type', 'status')->willReturn('status');
		$this->request->method('getParams')->willReturn(['type' => 'status', 'status' => 'in_behandeling']);
		$this->ingestService->method('postOutbound')
			->willReturn(['ref' => 'MOCK-DSO-1', 'type' => 'status', 'status' => 'sent']);

		$response = $this->controller->postOutbound(id: 'v1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('sent', $response->getData()['status']);

	}//end testPostOutboundReturnsDispatchOutcomeOnSuccess()
}//end class

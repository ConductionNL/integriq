<?php

/**
 * Unit tests for DSOController.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
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

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\DSOController;
use OCA\Integriq\Exception\DsoProviderException;
use OCA\Integriq\Exception\DsoTranslationException;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\DsoIngestService;
use OCA\Integriq\Service\DSOParserService;
use OCA\Integriq\Service\Dso\DsoActivityMapper;
use OCA\Integriq\Service\Dso\DsoClient;
use OCA\Integriq\Service\Dso\DsoConnection;
use OCA\Integriq\Service\Dso\DsoConnectionAlerts;
use OCA\Integriq\Service\Dso\DsoRequestTranslator;
use OCA\Integriq\Service\Dso\LogDsoConnectorProvider;
use OCA\Integriq\Service\Security\RawSourceResolver;
use OCA\Integriq\Tests\Helpers\DsoConnectionWorld;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Handoff\HandoffService;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\BackgroundJob\IJobList;
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
	use DsoConnectionWorld;

	/**
	 * HMAC-SHA256 of the empty body under the world's secret.
	 *
	 * @var string
	 */
	private const SIGNED_EMPTY_BODY = 'sha256=a4d65da9059d56d101608601b2bdfbb7fbea6b205b6ccb06aa9dd2f0db1338f6';

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
	 * The real DSO connection over the test world.
	 *
	 * @var DsoConnection
	 */
	private DsoConnection $connection;

	/**
	 * @var \PHPUnit\Framework\MockObject\MockObject|DsoConnectionAlerts
	 */
	private $alerts;

	/**
	 * Reasons the controller raised an admin alert for.
	 *
	 * @var list<string>
	 */
	private array $alerted = [];

	/**
	 * Jobs queued by the real ingest service.
	 *
	 * @var list<array{0: string, 1: mixed}>
	 */
	private array $queuedJobs = [];

	/**
	 * The world's ObjectService, with OpenRegister's own runAs().
	 *
	 * @var ORObjectService
	 */
	private ORObjectService $worldObjectService;

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
		$this->ingestService = $this->createMock(DsoIngestService::class);
		$this->actionAuth = $this->createMock(ActionAuthService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->l = $this->createMock(IL10N::class);
		$this->l->method('t')->willReturnArgument(0);

		// Default: a dso-stam consumer whose account holds the rights, so tests
		// only exercising payload handling do not need to restate this. The
		// connection is the real one; PHPUnit's CLI request body is empty, so
		// SIGNED_EMPTY_BODY is a valid signature for it.
		$this->resetWorld();
		$this->addAccount(uid: 'dso-intake');
		$this->addDsoConsumer(userId: 'dso-intake');
		$this->worldObjectService = $this->buildWorldObjectService();
		$this->connection = $this->buildWorldConnection(objectService: $this->worldObjectService);
		$this->alerted = [];
		$this->alerts = $this->createMock(DsoConnectionAlerts::class);
		$this->alerts->method('notify')->willReturnCallback(
			function (string $reason): bool {
				$this->alerted[] = $reason;
				return true;
			}
		);

		// Default: an authenticated user, so authenticated-surface tests do
		// not need to restate this every time.
		$user = $this->createMock(IUser::class);
		$this->userSession->method('getUser')->willReturn($user);

		// Default: ingest stores the verzoek, so the 202 tests describe a
		// request that really was saved.
		$stored = new ObjectEntity();
		$stored->setUuid('verzoek-uuid-1');
		$this->ingestService->method('ingest')->willReturn($stored);

		$this->controller = $this->buildController();

	}//end setUp()

	/**
	 * Build a DSOController with the current mock collaborators.
	 *
	 * @return DSOController
	 */
	private function buildController(): DSOController {
		return new DSOController(
			appName: 'integriq',
			request: $this->request,
			parser: $this->parser,
			logger: $this->logger,
			connection: $this->connection,
			alerts: $this->alerts,
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

		$body = [
			'verzoekId' => 'dso-12345',
			'type' => 'aanvraag',
			'submissionDate' => '2024-06-15',
			'aanvrager' => ['bsn' => '999993653'],
			'locatie' => ['bagAdres' => []],
			'activiteiten' => [['code' => 'bouwen-01']],
		];

		$this->request->method('getParams')->willReturn($body);
		$this->request->method('getHeader')->willReturn('sha256=' . str_repeat('0', 64));

		$response = $this->controller->receiveRequest();

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

		$this->request->method('getParams')->willReturn([]);
		$this->request->method('getHeader')->willReturn('');

		$response = $this->controller->receiveRequest();

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
						return self::SIGNED_EMPTY_BODY;
					}

					return '';
				}
			);

		$this->parser->method('validatePayload')->willReturn([]);
		$this->parser->method('parseRequest')->willReturn(
			array_merge($body, ['status' => 'ontvangen'])
		);

		$response = $this->controller->receiveRequest();

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
						return self::SIGNED_EMPTY_BODY;
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

		$response = $this->controller->receiveRequest();

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
						return self::SIGNED_EMPTY_BODY;
					}

					return '';
				}
			);

		$this->parser->method('validatePayload')->willReturn([]);
		$this->parser->method('parseRequest')->willReturn(['verzoekId' => 'test-id-999', 'type' => 'aanvraag']);

		$response = $this->controller->receiveRequest();

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
						return self::SIGNED_EMPTY_BODY;
					}

					return '';
				}
			);

		$this->parser->method('validatePayload')->willReturn([]);
		$this->parser->method('parseRequest')->willReturn(['verzoekId' => 'dso-env-test', 'type' => 'aanvraag']);

		$response = $this->controller->receiveRequest();

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
						return self::SIGNED_EMPTY_BODY;
					}

					return '';
				}
			);

		$this->parser->method('validatePayload')->willReturn([]);
		$this->parser->method('parseRequest')->willReturn(array_merge($body, ['status' => 'ontvangen']));

		$this->ingestService->expects($this->once())
			->method('ingest')
			->with($this->callback(static fn (array $parsedRequest): bool => ($parsedRequest['verzoekId'] === 'dso-12345')));

		$response = $this->controller->receiveRequest();

		$this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());

	}//end testReceiveVerzoekPersistsViaIngestService()

	/**
	 * Sign every request and let the payload through, so a test only states
	 * what happens at ingest.
	 *
	 * @param array<string, mixed> $body The parsed verzoek.
	 *
	 * @return void
	 */
	private function acceptSignedPayload(array $body): void {
		$this->request->method('getParams')->willReturn($body);
		$this->request->method('getHeader')
			->willReturnCallback(
				static function (string $header): string {
					if ($header === 'X-DSO-Signature') {
						return self::SIGNED_EMPTY_BODY;
					}

					return '';
				}
			);

		$this->parser->method('validatePayload')->willReturn([]);
		$this->parser->method('parseRequest')->willReturn($body);

	}//end acceptSignedPayload()

	/**
	 * The live failure: OpenRegister refuses `create` for the anonymous
	 * webhook caller. The real ingest service runs against an object service
	 * that throws exactly what OpenRegister's PermissionHandler throws. The
	 * endpoint MUST NOT answer 202, because nothing was stored: it answers 503,
	 * which a Digikoppeling ebMS2 sender treats as recoverable and retries.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dso-omgevingsloket/spec.md#requirement-stam-koppelvlak-endpoint-registration-req-dso-001
	 */
	public function testRefusedCreateAnswers503AndQueuesNothing(): void {
		$this->acceptSignedPayload(
			[
				'verzoekId' => 'dso-refused',
				'type' => 'aanvraag',
				'bijlagen' => [['name' => 'tekening.pdf', 'url' => 'https://dso.example/tekening.pdf']],
			]
		);

		$objectService = $this->getMockBuilder(ORObjectService::class)
			->disableOriginalConstructor()
			->getMock();
		$objectService->expects($this->once())
			->method('saveObject')
			->willThrowException(
				new NotAuthorizedException(
					message: "User 'Anonymous' does not have permission to 'create' objects in schema 'DSO Verzoek'"
				)
			);

		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->never())->method('add');

		$this->ingestService = new DsoIngestService(
			objectService: $objectService,
			handoffService: $this->getMockBuilder(HandoffService::class)->disableOriginalConstructor()->getMock(),
			translator: new DsoRequestTranslator(),
			logProvider: new LogDsoConnectorProvider(),
			restProvider: $this->getMockBuilder(DsoClient::class)->disableOriginalConstructor()->getMock(),
			logger: $this->createMock(LoggerInterface::class),
			rawSourceResolver: new RawSourceResolver($objectService, $this->createMock(LoggerInterface::class)),
			jobList: $jobList,
			activityMapper: new DsoActivityMapper()
		);

		$errors = [];
		$this->logger->method('error')->willReturnCallback(
			static function (string $message, array $context = []) use (&$errors): void {
				$errors[] = ['message' => $message, 'context' => $context];
			}
		);

		$this->controller = $this->buildController();

		$response = $this->controller->receiveRequest();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('verzoek_not_stored', $response->getData()['error']);
		$this->assertArrayNotHasKey('status', $response->getData());
		$this->assertCount(1, $errors);
		$this->assertSame('dso-refused', $errors[0]['context']['verzoekId']);
		$this->assertStringContainsString("'create'", (string)$errors[0]['context']['exception']);

	}//end testRefusedCreateAnswers503AndQueuesNothing()

	/**
	 * An ingest that returns an object without a uuid stored nothing either,
	 * so it gets the same 503 as a thrown refusal.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dso-omgevingsloket/spec.md#requirement-stam-koppelvlak-endpoint-registration-req-dso-001
	 */
	public function testIngestWithoutAStoredObjectAnswers503(): void {
		$this->acceptSignedPayload(['verzoekId' => 'dso-empty']);

		$this->ingestService = $this->createMock(DsoIngestService::class);
		$this->ingestService->method('ingest')->willReturn(new ObjectEntity());
		$this->controller = $this->buildController();

		$response = $this->controller->receiveRequest();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('verzoek_not_stored', $response->getData()['error']);

	}//end testIngestWithoutAStoredObjectAnswers503()

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
		$this->ingestService->method('getRequest')
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
		$this->ingestService->method('getRequest')
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

	/**
	 * The real intake: the connection, then the real ingest service, over the
	 * world's ObjectService.
	 *
	 * Queued jobs land in {@see self::$queuedJobs}.
	 *
	 * @return DSOController The controller.
	 */
	private function realIntake(): DSOController {
		$this->queuedJobs = [];
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('add')->willReturnCallback(
			function ($job, $argument = null): void {
				$this->queuedJobs[] = [$job, $argument];
			}
		);

		$this->ingestService = new DsoIngestService(
			objectService: $this->worldObjectService,
			handoffService: $this->getMockBuilder(HandoffService::class)->disableOriginalConstructor()->getMock(),
			translator: new DsoRequestTranslator(),
			logProvider: new LogDsoConnectorProvider(),
			restProvider: $this->getMockBuilder(DsoClient::class)->disableOriginalConstructor()->getMock(),
			logger: $this->createMock(LoggerInterface::class),
			rawSourceResolver: new RawSourceResolver($this->worldObjectService, $this->createMock(LoggerInterface::class)),
			jobList: $jobList,
			activityMapper: new DsoActivityMapper()
		);

		return $this->buildController();
	}//end realIntake()

	/**
	 * The live bug, closed: a correctly signed push WITHOUT a Nextcloud user
	 * is stored, and every OpenRegister write runs as the connection's
	 * account. Afterwards nobody is active again.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/specs/dso-omgevingsloket/spec.md#scenario-the-verzoek-is-stored-as-the-configured-account
	 */
	public function testAnAnonymousSignedPushIsStoredAsTheConnectionAccount(): void {
		$this->acceptSignedPayload(
			[
				'verzoekId' => 'dso-anon-1',
				'type' => 'aanvraag',
				'bijlagen' => [
					['name' => 'a.pdf', 'url' => 'https://dso-lv.nl/docs/a'],
					['name' => 'b.pdf', 'url' => 'https://dso-lv.nl/docs/b'],
					['name' => 'c.pdf', 'url' => 'https://dso-lv.nl/docs/c'],
				],
			]
		);
		$controller = $this->realIntake();
		$this->assertNull($this->worldSession->getUser(), 'the push arrives without a user');

		$response = $controller->receiveRequest();

		$this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
		$this->assertSame(['create', 'update'], array_column($this->worldWrites, 'action'));
		$this->assertSame(['dso-intake', 'dso-intake'], $this->writerUids());
		$this->assertSame([true, true], array_column($this->worldWrites, 'rbac'), 'no write skips RBAC');
		$this->assertNull($this->worldSession->getUser(), 'the previous (anonymous) user is restored');

		$stored = array_values($this->worldVerzoeken)[0]->getObject();
		$this->assertSame(['consumer' => 'consumer-dso', 'account' => 'dso-intake'], $stored['receivedVia']);
		$this->assertCount(1, $this->queuedJobs);
		$this->assertSame('dso-intake', $this->queuedJobs[0][1]['actingUserId']);

	}//end testAnAnonymousSignedPushIsStoredAsTheConnectionAccount()

	/**
	 * When OpenRegister refuses a write anyway, the caller's identity is
	 * still restored, the answer is 503 and the admins hear of it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-2
	 */
	public function testARefusedWriteRestoresTheCallerAndAnswers503(): void {
		$this->acceptSignedPayload(['verzoekId' => 'dso-refused-2', 'type' => 'aanvraag']);
		$controller = $this->realIntake();
		// The rights check passes, but OpenRegister refuses the update anyway
		// (for example a rule that changed between the check and the write).
		$this->worldGrants['dso-intake'] = ['create', 'read'];
		$this->worldHasPermissionHandler = true;
		$realConnection = $this->buildWorldConnection(objectService: $this->worldObjectService);
		$alwaysGranted = $this->createMock(DsoConnection::class);
		$alwaysGranted->method('authenticate')->willReturnCallback(
			fn () => new \OCA\Integriq\Service\Dso\DsoIdentity(account: $this->worldUser('dso-intake'), consumerUuid: 'consumer-dso')
		);
		$alwaysGranted->method('runAs')->willReturnCallback(
			fn ($account, callable $operation) => $realConnection->runAs($account, $operation)
		);
		$this->connection = $alwaysGranted;
		$controller = $this->buildController();

		$response = $controller->receiveRequest();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('verzoek_not_stored', $response->getData()['error']);
		$this->assertNull($this->worldSession->getUser());
		$this->assertSame(['verzoek_not_stored'], $this->alerted);

	}//end testARefusedWriteRestoresTheCallerAndAnswers503()

	/**
	 * Each connection problem of the D7 table answers 503 with its error,
	 * writes nothing, and alerts the administrators with the reason.
	 *
	 * @return array<string, array{0: callable, 1: string, 2: string}>
	 */
	public static function unavailableConnections(): array {
		return [
			'no dso-stam consumer' => [
				static function (self $test): void {
					$test->worldConsumers = [];
				},
				'dso_connection_not_configured',
				'no_connection',
			],
			'empty account' => [
				static function (self $test): void {
					$test->worldConsumers['consumer-dso']['userId'] = '';
				},
				'dso_account_unavailable',
				'no_account',
			],
			'unknown account' => [
				static function (self $test): void {
					$test->worldConsumers['consumer-dso']['userId'] = 'ghost';
				},
				'dso_account_unavailable',
				'account_unknown',
			],
			'disabled account' => [
				static function (self $test): void {
					$test->worldAccounts['dso-intake'] = false;
				},
				'dso_account_unavailable',
				'account_disabled',
			],
			'create but not update' => [
				static function (self $test): void {
					$test->worldGrants['dso-intake'] = ['create', 'read'];
				},
				'dso_account_lacks_rights',
				'account_lacks_rights',
			],
			'no rights at all' => [
				static function (self $test): void {
					$test->worldGrants['dso-intake'] = [];
				},
				'dso_account_lacks_rights',
				'account_lacks_rights',
			],
		];
	}//end unavailableConnections()

	/**
	 * A connection problem: 503, the named error, nothing written, one alert.
	 *
	 * @param callable $breakIt   Breaks the world.
	 * @param string   $errorCode The expected error.
	 * @param string   $reason    The expected alert reason.
	 *
	 * @return void
	 *
	 * @dataProvider unavailableConnections
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/specs/dso-omgevingsloket/spec.md#scenario-a-missing-account-fails-loud
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/specs/dso-omgevingsloket/spec.md#scenario-an-account-without-rights-writes-nothing-half
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('unavailableConnections')]
	public function testAnUnusableConnectionAnswers503AndWritesNothing(callable $breakIt, string $errorCode, string $reason): void {
		$this->acceptSignedPayload(
			[
				'verzoekId' => 'dso-503',
				'type' => 'aanvraag',
				'bijlagen' => [['name' => 'a.pdf', 'url' => 'https://dso-lv.nl/docs/a']],
			]
		);
		$controller = $this->realIntake();
		$breakIt($this);

		$response = $controller->receiveRequest();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame($errorCode, $response->getData()['error']);
		$this->assertArrayNotHasKey('status', $response->getData());
		$this->assertSame([], $this->worldWrites, 'nothing is written');
		$this->assertSame([], $this->queuedJobs, 'no bijlage job');
		$this->assertSame([$reason], $this->alerted);

	}//end testAnUnusableConnectionAnswers503AndWritesNothing()

	/**
	 * A bad signature answers 401 and raises no alert: that is a caller
	 * problem, not a configuration problem.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/specs/dso-omgevingsloket/spec.md#requirement-stam-koppelvlak-endpoint-registration-req-dso-001
	 */
	public function testAForgedSignatureAnswers401WithoutAnAlert(): void {
		$this->request->method('getParams')->willReturn(['verzoekId' => 'dso-forged']);
		$this->request->method('getHeader')->willReturn('sha256=' . hash_hmac('sha256', '', 'not-the-secret'));
		$this->parser->expects($this->never())->method('parseRequest');
		$controller = $this->realIntake();

		$response = $controller->receiveRequest();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame([], $this->alerted);
		$this->assertSame([], $this->worldWrites);

	}//end testAForgedSignatureAnswers401WithoutAnAlert()

	/**
	 * A second delivery of the same verzoek answers 202 and creates nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/specs/dso-omgevingsloket/spec.md#scenario-a-repeated-delivery-creates-no-second-record
	 */
	public function testARepeatedDeliveryAnswers202AndCreatesNothing(): void {
		$this->acceptSignedPayload(['verzoekId' => 'dso-123', 'type' => 'aanvraag']);
		$controller = $this->realIntake();

		$this->assertSame(Http::STATUS_ACCEPTED, $controller->receiveRequest()->getStatus());
		$writes = count($this->worldWrites);
		$second = $controller->receiveRequest();

		$this->assertSame(Http::STATUS_ACCEPTED, $second->getStatus());
		$this->assertSame('dso-123', $second->getData()['verzoekId']);
		$this->assertCount(1, $this->worldVerzoeken);
		$this->assertSame($writes, count($this->worldWrites));

	}//end testARepeatedDeliveryAnswers202AndCreatesNothing()
}//end class

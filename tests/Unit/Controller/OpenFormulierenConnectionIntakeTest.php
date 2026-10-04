<?php

/**
 * The Open Formulieren intake acts as its connection's account.
 *
 * Runs the real path: OpenFormulierenController, the real
 * OpenFormulierenConnection, the real OpenFormulierenIntakeService and
 * OpenRegister's own `runAs()` (copied into the ObjectService stub) over a
 * real active-user slot. The world's ObjectService refuses a write without
 * the right, as OpenRegister does, and records the uid active at each write.
 * The attachment store records the uid active when each file was stored.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/specs/open-formulieren-intake/spec.md#requirement-the-intake-acts-as-the-open-formulieren-connections-account-req-006
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use OCA\Integriq\Controller\OpenFormulierenController;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\Dso\DsoConnectionAlerts;
use OCA\Integriq\Service\OpenFormulieren\FormFieldMapper;
use OCA\Integriq\Service\OpenFormulierenIntakeService;
use OCA\Integriq\Tests\Helpers\DsoConnectionWorld;
use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Handoff\HandoffService;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Signed submissions without a login, stored as the connection's account.
 */
class OpenFormulierenConnectionIntakeTest extends TestCase {
	use DsoConnectionWorld;

	/**
	 * The world's ObjectService.
	 *
	 * @var ORObjectService
	 */
	private ORObjectService $objectService;

	/**
	 * Every alert sent: reason and channel.
	 *
	 * @var list<array{reason: string, channel: string}>
	 */
	private array $alerted = [];

	/**
	 * Every stored attachment: file name and the uid active when it was stored.
	 *
	 * @var list<array{name: string, uid: string|null}>
	 */
	private array $storedFiles = [];

	/**
	 * The submission payload the request carries.
	 *
	 * @var array<string, mixed>
	 */
	private array $payload = [];

	/**
	 * The signature header the request carries.
	 *
	 * @var string
	 */
	private string $signature = '';

	/**
	 * A world with one Open Formulieren connection whose account holds the rights,
	 * and an enabled form mapping that every authenticated account can read.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->resetWorld();
		$this->addAccount(uid: 'of-intake', grants: ['create', 'update']);
		$this->addOpenFormulierenConsumer(userId: 'of-intake');
		$this->addOther(
			schema: 'openformulieren_form_mapping',
			uuid: 'mapping-melding',
			data: [
				'formSlug' => 'melding',
				'isEnabled' => true,
				'fieldMapping' => [
					'title' => ['type' => 'template', 'value' => 'Melding: {{onderwerp}}'],
					'summary' => ['type' => 'from', 'value' => 'toelichting'],
					'channel' => ['type' => 'const', 'value' => 'web'],
				],
			],
			adminOnly: false
		);
		$this->objectService = $this->buildWorldObjectService();
		$this->alerted = [];
		$this->storedFiles = [];

		// PHPUnit's CLI request body is empty, so a signature over '' is valid.
		$this->signature = $this->signOpenFormulieren('');
		$this->payload = [
			'form' => ['slug' => 'melding'],
			'submission' => ['uuid' => 'of-sub-1', 'submittedAt' => '2026-10-04T20:00:00+02:00'],
			'values' => ['onderwerp' => 'Losse tegel', 'toelichting' => 'Bij nummer 1'],
			'auth' => ['plugin' => 'digid', 'bsn' => '999999990'],
			'attachments' => [['key' => 'foto', 'url' => 'https://files.example/foto.jpg', 'filename' => 'foto.jpg']],
		];

	}//end setUp()

	/**
	 * Build the controller over the real connection and intake service.
	 *
	 * @return OpenFormulierenController The controller.
	 */
	private function controller(): OpenFormulierenController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			fn (string $name): string => ($name === 'X-OpenFormulieren-Signature' ? $this->signature : '')
		);
		$request->method('getParams')->willReturnCallback(fn (): array => $this->payload);

		$alerts = $this->createMock(DsoConnectionAlerts::class);
		$alerts->method('notify')->willReturnCallback(
			function (string $reason, string $channel = 'dso'): bool {
				$this->alerted[] = ['reason' => $reason, 'channel' => $channel];
				return true;
			}
		);

		$fileService = $this->getMockBuilder(FileService::class)->disableOriginalConstructor()->getMock();
		$fileService->method('addFile')->willReturnCallback(
			function ($objectEntity, string $fileName): File {
				$this->storedFiles[] = ['name' => $fileName, 'uid' => $this->worldSession->getUser()?->getUID()];
				$file = $this->createMock(File::class);
				$file->method('getId')->willReturn(500 + count($this->storedFiles));
				return $file;
			}
		);

		$client = new Client(['handler' => HandlerStack::create(new MockHandler(array_fill(0, 4, new Response(200, [], 'jpeg bytes'))))]);

		$intake = new OpenFormulierenIntakeService(
			objectService: $this->objectService,
			handoffService: $this->getMockBuilder(HandoffService::class)->disableOriginalConstructor()->getMock(),
			fileService: $fileService,
			fieldMapper: new FormFieldMapper(),
			httpClient: $client,
			logger: new NullLogger()
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new OpenFormulierenController(
			'integriq',
			$request,
			$intake,
			$this->buildWorldOpenFormulierenConnection(objectService: $this->objectService),
			$alerts,
			$this->createMock(IUserSession::class),
			$this->createMock(ActionAuthService::class),
			$l10n,
			new NullLogger()
		);

	}//end controller()

	/**
	 * The submissions the world holds.
	 *
	 * @return list<ObjectEntity> The stored submissions.
	 */
	private function submissions(): array {
		return array_values(array_map(static fn (array $stored): ObjectEntity => $stored['entity'], $this->worldSubmissions));

	}//end submissions()

	/**
	 * A correctly signed submission without a login is stored, mapped and
	 * attached, and every write and every file runs as the connection's account.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/specs/open-formulieren-intake/spec.md#scenario-the-submission-is-stored-as-the-configured-account
	 */
	public function testASignedSubmissionWithoutALoginIsStoredAsTheConnectionAccount(): void {
		$this->assertNull($this->worldSession->getUser(), 'precondition: no login');

		$response = $this->controller()->inbound();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $this->submissions());
		$stored = $this->submissions()[0]->getObject();
		$this->assertSame('mapped', $stored['status']);
		$this->assertSame('Melding: Losse tegel', $stored['mappedTitle']);
		$this->assertSame(['consumer' => 'consumer-of', 'account' => 'of-intake'], $stored['receivedVia']);
		$this->assertSame('fetched', $stored['attachments'][0]['status']);

		$submissionWriters = array_values(
			array_unique(
				array_map(
					static fn (array $write): ?string => $write['uid'],
					array_filter($this->worldWrites, static fn (array $write): bool => $write['schema'] === 'openformulieren_submission')
				)
			)
		);
		$this->assertSame(['of-intake'], $submissionWriters, 'every submission write runs as the connection account');
		$this->assertSame([['name' => 'foto.jpg', 'uid' => 'of-intake']], $this->storedFiles, 'the attachment is stored as the connection account');
		$this->assertNull($this->worldSession->getUser(), 'the previous (anonymous) user is restored');
		$this->assertSame([], $this->alerted);

	}//end testASignedSubmissionWithoutALoginIsStoredAsTheConnectionAccount()

	/**
	 * Every object the intake writes is valid against the real register
	 * schema, `receivedVia` included, and `receivedVia` accepts nothing else.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/tasks.md#task-3
	 */
	public function testEveryWrittenSubmissionIsValidAgainstTheRegisterSchema(): void {
		$this->controller()->inbound();

		$writes = array_filter($this->worldWrites, static fn (array $write): bool => $write['schema'] === 'openformulieren_submission');
		$this->assertNotSame([], $writes);
		foreach ($writes as $write) {
			$object = array_filter($write['object'], static fn (mixed $value): bool => $value !== null);
			$this->assertSame([], RegisterSchemaValidator::errors(schemaSlug: 'openformulieren_submission', object: $object));
		}

		$this->assertNotSame(
			[],
			RegisterSchemaValidator::errors(
				schemaSlug: 'openformulieren_submission',
				object: ['formSlug' => 'melding', 'status' => 'mapped', 'receivedVia' => ['consumer' => 'c', 'user' => 'x']]
			),
			'receivedVia declares exactly consumer and account'
		);

	}//end testEveryWrittenSubmissionIsValidAgainstTheRegisterSchema()

	/**
	 * A bad signature answers 401 and writes nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/open-formulieren-intake/spec.md#scenario-invalid-signature-is-rejected
	 */
	public function testABadSignatureAnswers401AndWritesNothing(): void {
		$this->signature = 't=' . time() . ',v1=' . str_repeat('0', 64);

		$response = $this->controller()->inbound();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('invalid signature', $response->getData()['error']);
		$this->assertSame([], $this->worldWrites);

	}//end testABadSignatureAnswers401AndWritesNothing()

	/**
	 * No Open Formulieren connection: 503, nothing written, admins alerted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/specs/open-formulieren-intake/spec.md#scenario-no-active-source-configured-fails-closed
	 */
	public function testNoConnectionAnswers503AndWritesNothing(): void {
		$this->worldConsumers = [];

		$response = $this->controller()->inbound();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('openformulieren_connection_not_configured', $response->getData()['error']);
		$this->assertSame([], $this->worldWrites);
		$this->assertSame([['reason' => 'no_connection', 'channel' => 'openformulieren']], $this->alerted);

	}//end testNoConnectionAnswers503AndWritesNothing()

	/**
	 * A connection without an account: 503, nothing written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/specs/open-formulieren-intake/spec.md#scenario-a-missing-account-fails-loud
	 */
	public function testAConnectionWithoutAnAccountAnswers503(): void {
		$this->addOpenFormulierenConsumer(userId: '');

		$response = $this->controller()->inbound();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('openformulieren_account_unavailable', $response->getData()['error']);
		$this->assertSame([], $this->worldWrites);
		$this->assertSame('no_account', $this->alerted[0]['reason']);

	}//end testAConnectionWithoutAnAccountAnswers503()

	/**
	 * A disabled account: 503, nothing written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/specs/open-formulieren-intake/spec.md#scenario-a-missing-account-fails-loud
	 */
	public function testADisabledAccountAnswers503(): void {
		$this->addAccount(uid: 'of-intake', grants: ['create', 'update'], enabled: false);

		$response = $this->controller()->inbound();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('openformulieren_account_unavailable', $response->getData()['error']);
		$this->assertSame([], $this->worldWrites);

	}//end testADisabledAccountAnswers503()

	/**
	 * An account with create but not update writes nothing half.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/specs/open-formulieren-intake/spec.md#scenario-an-account-without-rights-writes-nothing-half
	 */
	public function testAnAccountWithoutUpdateWritesNothingHalf(): void {
		$this->addAccount(uid: 'of-intake', grants: ['create']);

		$response = $this->controller()->inbound();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('openformulieren_account_lacks_rights', $response->getData()['error']);
		$this->assertSame([], $this->worldWrites, 'no received record is left behind');

	}//end testAnAccountWithoutUpdateWritesNothingHalf()

	/**
	 * Rights that cannot be checked fail closed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/specs/open-formulieren-intake/spec.md#scenario-an-account-without-rights-writes-nothing-half
	 */
	public function testRightsThatCannotBeCheckedFailClosed(): void {
		$this->worldHasPermissionHandler = false;

		$response = $this->controller()->inbound();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('openformulieren_account_lacks_rights', $response->getData()['error']);
		$this->assertSame([], $this->worldWrites);

	}//end testRightsThatCannotBeCheckedFailClosed()

	/**
	 * Two Open Formulieren connections: the intake does not guess.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/specs/open-formulieren-intake/spec.md#scenario-no-active-source-configured-fails-closed
	 */
	public function testTwoConnectionsAnswer503(): void {
		$this->addOpenFormulierenConsumer(userId: 'of-intake', uuid: 'consumer-of-2');

		$response = $this->controller()->inbound();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('openformulieren_connection_not_configured', $response->getData()['error']);
		$this->assertSame([], $this->worldWrites);

	}//end testTwoConnectionsAnswer503()

	/**
	 * A write OpenRegister refuses anyway answers 503, so Open Formulieren delivers again.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/specs/open-formulieren-intake/spec.md#scenario-a-submission-that-was-not-stored-is-answered-with-503
	 */
	public function testARefusedWriteAnswers503(): void {
		$this->worldRefuseSubmissionWrites = true;

		$response = $this->controller()->inbound();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('submission_not_stored', $response->getData()['error']);
		$this->assertSame([], $this->submissions());
		$this->assertSame([['reason' => 'submission_not_stored', 'channel' => 'openformulieren']], $this->alerted);

	}//end testARefusedWriteAnswers503()

	/**
	 * The same submission delivered twice creates one record.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/specs/open-formulieren-intake/spec.md#scenario-a-repeated-delivery-creates-no-second-record
	 */
	public function testARepeatedDeliveryCreatesNoSecondRecord(): void {
		$first = $this->controller()->inbound();
		$writes = count($this->worldWrites);

		$second = $this->controller()->inbound();

		$this->assertSame(Http::STATUS_OK, $first->getStatus());
		$this->assertSame(Http::STATUS_OK, $second->getStatus());
		$this->assertCount(1, $this->submissions());
		$this->assertSame($first->getData()['id'], $second->getData()['id']);
		$this->assertSame($writes, count($this->worldWrites), 'the second delivery writes nothing');
		$this->assertCount(1, $this->storedFiles, 'the attachment is stored once');

	}//end testARepeatedDeliveryCreatesNoSecondRecord()
}//end class

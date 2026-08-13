<?php

/**
 * Integration test: `action.kind: 'mapping'` outbound dispatch through a
 * REAL EventService -> FormsSyncAdapter -> FormsAnswerResolver chain, using
 * a mocked FormsClientInterface (only the outermost HTTP boundary is a test
 * double — proposal.md Risk 1: no live Forms-enabled Nextcloud instance is
 * available to this repo). Mirrors
 * tests/Unit/Service/EventServiceNotificatiesActionTest.php's wiring
 * convention for the sibling `action.kind` branches.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Integration\Forms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/nextcloud-forms-connector/tasks.md#task-10-integration-tests--mocked-formsclientinterface-end-to-end-dispatch
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Integration\Forms;

use OCA\OpenConnector\Service\CallService;
use OCA\OpenConnector\Service\EventService;
use OCA\OpenConnector\Service\FlowRunnerService;
use OCA\OpenConnector\Service\Forms\FormsAnswerResolver;
use OCA\OpenConnector\Service\Forms\FormsClientInterface;
use OCA\OpenConnector\Service\Forms\FormsSyncAdapter;
use OCA\OpenConnector\Service\JobService;
use OCA\OpenConnector\Service\MappingService;
use OCA\OpenConnector\Service\SynchronizationService;
use OCA\OpenConnector\Service\WebhookSignatureService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @spec openspec/changes/nextcloud-forms-connector/specs/nextcloud-forms-connector/spec.md#requirement-outbound-submission-to-call-mapping-dispatch-req-004
 */
class FormsOutboundMappingIntegrationTest extends TestCase {

	/**
	 * @var EventService
	 */
	private EventService $service;

	/**
	 * @var \PHPUnit\Framework\MockObject\MockObject
	 */
	private $objectService;

	/**
	 * @var CallService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $callService;

	/**
	 * @var MappingService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $mappingService;

	/**
	 * @var FormsClientInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $formsClient;

	/**
	 * The `object` payload of the most recent `saveObject()` call (the
	 * `event_message` being persisted by the dispatch under test).
	 *
	 * @var array
	 */
	private array $capturedMessage = [];

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectService = ObjectServiceMockBuilder::make($this);
		$logger = $this->createMock(LoggerInterface::class);
		$clientService = $this->createMock(IClientService::class);
		$this->callService = $this->createMock(CallService::class);
		$this->mappingService = $this->createMock(MappingService::class);
		$this->capturedMessage = [];

		$this->formsClient = $this->createMock(FormsClientInterface::class);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturn(true);
		$formsSyncAdapter = new FormsSyncAdapter($this->formsClient, $appManager, $this->createMock(LoggerInterface::class));

		$this->service = new EventService(
			$this->objectService,
			$clientService,
			$logger,
			new WebhookSignatureService($logger),
			$this->createMock(SynchronizationService::class),
			$this->createMock(JobService::class),
			$this->callService,
			$this->createMock(FlowRunnerService::class),
			$this->mappingService,
			new FormsAnswerResolver(),
			$formsSyncAdapter,
		);

	}//end setUp()

	/**
	 * Configure `find`/`findAll`/`saveObject` for a single active subscription
	 * with the given `action` block, matching every incoming event.
	 * `saveObject()` calls are captured into `$this->capturedMessage`.
	 *
	 * @param array $action The subscription's `action` block.
	 * @param array $eventData The Forms trigger's `event.data` block (formId/submission.id).
	 * @param array $find Extra `schema => ObjectEntity` responses for `find()`
	 *                    (e.g. `mapping`/`source`) beyond the subscription/event themselves.
	 *
	 * @return ObjectEntity The event entity to pass to `processEvent()`.
	 */
	private function wireSubscription(array $action, array $eventData, array $find = []): ObjectEntity {
		$subscription = ObjectServiceMockBuilder::objectEntity(
			$this,
			['status' => 'active', 'style' => 'push', 'action' => $action],
			'sub-uuid'
		);

		$event = ObjectServiceMockBuilder::objectEntity(
			$this,
			[
				'type' => 'com.nextcloud.forms.submission.created',
				'subject' => '42',
				'time' => '2026-07-15T09:00:00Z',
				'data' => $eventData,
			],
			'event-uuid'
		);

		$this->objectService->method('findAll')->willReturn(['results' => [$subscription], 'total' => 1]);
		$this->objectService->method('find')->willReturnCallback(
			function (string $id, string $register, string $schema) use ($find, $event) {
				if ($schema === 'event') {
					return ($find['event'] ?? $event);
				}

				return ($find[$schema] ?? null);
			}
		);

		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, ...$rest) {
				$this->capturedMessage = $object;
				return ObjectServiceMockBuilder::objectEntity($this, $object, 'msg-uuid');
			}
		);

		return $event;
	}//end wireSubscription()

	/**
	 * TC-11: a Forms submission event drives an external call via answer
	 * mapping — full submission fetched, answers resolved (REQ-003),
	 * MappingService::executeMapping() runs, CallService::call() POSTs the
	 * mapped result; message persisted `status='delivered'` on 2xx.
	 *
	 * @return void
	 */
	public function testFormsSubmissionEventDrivesExternalCallViaAnswerMapping(): void {
		$mapping = ObjectServiceMockBuilder::objectEntity($this, ['name' => 'lead-mapping'], 'mapping-1');
		$source = ObjectServiceMockBuilder::objectEntity($this, ['location' => 'https://crm.example.test'], 'source-1');

		$event = $this->wireSubscription(
			['kind' => 'mapping', 'mappingId' => 'mapping-1', 'sourceId' => 'source-1', 'endpoint' => '/leads'],
			['formId' => '42', 'submission' => ['id' => 5, 'formId' => 42, 'userId' => 'admin', 'timestamp' => 100]],
			['mapping' => $mapping, 'source' => $source]
		);

		$this->formsClient->expects($this->once())->method('getSubmission')
			->with($source, 42, 5)
			->willReturn(
				[
					'id' => 5,
					'formId' => 42,
					'userId' => 'admin',
					'timestamp' => 100,
					'answers' => [
						['id' => 1, 'questionId' => 7, 'questionName' => null, 'text' => 'Acme BV'],
						['id' => 2, 'questionId' => 8, 'questionName' => null, 'text' => 'info@acme.test'],
					],
				]
			);
		$this->formsClient->expects($this->once())->method('getForm')
			->with($source, 42)
			->willReturn(
				[
					'id' => 42,
					'title' => 'Contact form',
					'questions' => [
						['id' => 7, 'text' => 'Company name', 'name' => '', 'type' => 'short'],
						['id' => 8, 'text' => 'Email', 'name' => '', 'type' => 'short'],
					],
				]
			);

		$this->mappingService->expects($this->once())->method('executeMapping')
			->with(
				$mapping,
				$this->callback(
					static fn (array $input) => ($input['Company name'] ?? null) === 'Acme BV'
						&& ($input['Email'] ?? null) === 'info@acme.test'
				)
			)
			->willReturn(['companyName' => 'Acme BV', 'email' => 'info@acme.test']);

		$capturedCall = null;
		$callLog = ObjectServiceMockBuilder::objectEntity($this, ['statusCode' => 201], 'call-log-1');
		$this->callService->expects($this->once())->method('call')
			->willReturnCallback(
				function ($src, $endpoint, $method, $config = []) use (&$capturedCall, $callLog) {
					$capturedCall = ['source' => $src, 'endpoint' => $endpoint, 'method' => $method, 'config' => $config];
					return $callLog;
				}
			);

		$this->service->processEvent($event);

		$this->assertSame('/leads', $capturedCall['endpoint']);
		$this->assertSame('POST', $capturedCall['method']);
		$this->assertSame('Acme BV', $capturedCall['config']['json']['companyName']);
		$this->assertSame('delivered', $this->capturedMessage['status']);

	}//end testFormsSubmissionEventDrivesExternalCallViaAnswerMapping()

	/**
	 * TC-12: an ambiguous question text referenced by the Mapping is a
	 * standard retryable failure (not a config error) — a data-shape problem
	 * in a specific submission does not permanently misconfigure the
	 * subscription.
	 *
	 * @return void
	 */
	public function testAmbiguousQuestionTextFollowsStandardRetryBackoff(): void {
		$mapping = ObjectServiceMockBuilder::objectEntity($this, ['name' => 'lead-mapping'], 'mapping-1');
		$source = ObjectServiceMockBuilder::objectEntity($this, ['location' => 'https://crm.example.test'], 'source-1');

		$event = $this->wireSubscription(
			['kind' => 'mapping', 'mappingId' => 'mapping-1', 'sourceId' => 'source-1', 'endpoint' => '/leads'],
			['formId' => '42', 'submission' => ['id' => 5, 'formId' => 42, 'userId' => 'admin', 'timestamp' => 100]],
			['mapping' => $mapping, 'source' => $source]
		);

		$this->formsClient->method('getSubmission')->willReturn(
			[
				'id' => 5,
				'formId' => 42,
				'userId' => 'admin',
				'timestamp' => 100,
				'answers' => [
					['id' => 1, 'questionId' => 12, 'questionName' => null, 'text' => 'foo'],
					['id' => 2, 'questionId' => 19, 'questionName' => null, 'text' => 'bar'],
				],
			]
		);
		$this->formsClient->method('getForm')->willReturn(
			[
				'id' => 42,
				'title' => 'Contact form',
				'questions' => [
					['id' => 12, 'text' => 'Comments', 'name' => '', 'type' => 'long'],
					['id' => 19, 'text' => 'Comments', 'name' => '', 'type' => 'long'],
				],
			]
		);
		$this->mappingService->expects($this->never())->method('executeMapping');
		$this->callService->expects($this->never())->method('call');

		$this->service->processEvent($event);

		$this->assertSame('failed', $this->capturedMessage['status']);
		$this->assertSame(1, $this->capturedMessage['retryCount']);
		$this->assertNotNull($this->capturedMessage['nextAttempt']);

	}//end testAmbiguousQuestionTextFollowsStandardRetryBackoff()

	/**
	 * TC-13: an unresolvable mappingId fails without any Forms client call
	 * and without any CallService::call() attempt.
	 *
	 * @return void
	 */
	public function testUnresolvableMappingIdFailsWithoutFormsCall(): void {
		$event = $this->wireSubscription(
			['kind' => 'mapping', 'mappingId' => 'does-not-exist', 'sourceId' => 'source-1', 'endpoint' => '/leads'],
			['formId' => '42', 'submission' => ['id' => 5, 'formId' => 42, 'userId' => 'admin', 'timestamp' => 100]],
			['source' => ObjectServiceMockBuilder::objectEntity($this, ['location' => 'https://crm.example.test'], 'source-1')]
		);

		$this->formsClient->expects($this->never())->method('getSubmission');
		$this->formsClient->expects($this->never())->method('getForm');
		$this->callService->expects($this->never())->method('call');

		$this->service->processEvent($event);

		$this->assertSame('failed', $this->capturedMessage['status']);
		$this->assertSame('mapping or source not found', $this->capturedMessage['error']);

	}//end testUnresolvableMappingIdFailsWithoutFormsCall()

	/**
	 * REQ-001 scenario 3: Forms disabled is a config error — retryCount
	 * stays 0, no Forms HTTP call attempted.
	 *
	 * @return void
	 */
	public function testFormsDisabledIsConfigErrorNotRetried(): void {
		$mapping = ObjectServiceMockBuilder::objectEntity($this, ['name' => 'lead-mapping'], 'mapping-1');
		$source = ObjectServiceMockBuilder::objectEntity($this, ['location' => 'https://crm.example.test'], 'source-1');

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturn(false);
		$formsSyncAdapter = new FormsSyncAdapter($this->formsClient, $appManager, $this->createMock(LoggerInterface::class));

		$service = new EventService(
			$this->objectService,
			$this->createMock(IClientService::class),
			$this->createMock(LoggerInterface::class),
			new WebhookSignatureService($this->createMock(LoggerInterface::class)),
			$this->createMock(SynchronizationService::class),
			$this->createMock(JobService::class),
			$this->callService,
			$this->createMock(FlowRunnerService::class),
			$this->mappingService,
			new FormsAnswerResolver(),
			$formsSyncAdapter,
		);

		$event = $this->wireSubscription(
			['kind' => 'mapping', 'mappingId' => 'mapping-1', 'sourceId' => 'source-1', 'endpoint' => '/leads'],
			['formId' => '42', 'submission' => ['id' => 5, 'formId' => 42, 'userId' => 'admin', 'timestamp' => 100]],
			['mapping' => $mapping, 'source' => $source]
		);

		$this->formsClient->expects($this->never())->method('getSubmission');
		$this->callService->expects($this->never())->method('call');

		$service->processEvent($event);

		$this->assertSame('failed', $this->capturedMessage['status']);
		$this->assertSame(0, ($this->capturedMessage['retryCount'] ?? 0));

	}//end testFormsDisabledIsConfigErrorNotRetried()
}//end class

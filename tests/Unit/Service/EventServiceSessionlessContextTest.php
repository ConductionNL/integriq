<?php

/**
 * Events raised in a request without a session reach their subscriptions.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/events-cloudevents/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\EventService;
use OCA\Integriq\Service\FlowRunnerService;
use OCA\Integriq\Service\JobService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Http\Client\IClientService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * The LTI AGS score route and inbound webhooks are public pages: the request
 * has no session, and OpenRegister filters its reads as anonymous. Measured on
 * the dev instance on 2026-09-30 with `AnonymousEvaluationContext::run()`, the
 * lookup processEvent() made (`event_subscription`, `status: active`) found:
 * 0 rows with the default flags, 0 with `_rbac: false`, and 1 only with both
 * `_rbac: false` and `_multitenancy: false`. So a score's CloudEvent reached no
 * subscription (`messagesCreated: 0`) and learniq never got the grade.
 *
 * The object store below evaluates reads the way that measurement did: a read
 * sees the rows only in system context. Writes are recorded with their scope.
 */
class EventServiceSessionlessContextTest extends TestCase {

	/**
	 * Rows per schema.
	 *
	 * @var array<string, array<int, ObjectEntity>>
	 */
	private array $rows = [];

	/**
	 * Every write: schema, `_rbac`, `_multitenancy`.
	 *
	 * @var array<int, array{schema: ?string, rbac: bool, multitenancy: bool}>
	 */
	private array $writes = [];

	/**
	 * The service under test.
	 *
	 * @var EventService
	 */
	private EventService $service;

	/**
	 * Build the service over the anonymous-evaluating store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$subscription = new ObjectEntity();
		$subscription->setUuid('34ab1b52-6a57-4a19-bd09-be251efd4d68');
		$subscription->setObject(
			[
				'types' => ['nl.conduction.lti.ags.score.received'],
				'source' => 'lti_deployment/899c7cf9-d16b-48eb-b29f-15be79d2d079',
				'style' => 'pull',
				'status' => 'active',
			]
		);
		$this->rows['event_subscription'] = [$subscription];

		$objectService = ObjectServiceMockBuilder::make($this);

		$objectService->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				// Anonymous evaluation: only a system-context read sees the rows.
				if ($_rbac === true || $_multitenancy === true) {
					return ['results' => []];
				}

				$schema = (string)($config['filters']['schema'] ?? '');
				$status = ($config['filters']['status'] ?? null);

				return [
					'results' => array_values(
						array_filter(
							($this->rows[$schema] ?? []),
							static fn (ObjectEntity $row): bool => $status === null || ($row->getObject()['status'] ?? null) === $status
						)
					),
				];
			}
		);

		$names = array_map(
			static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
			(new ReflectionMethod(ObjectService::class, 'saveObject'))->getParameters()
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (...$args) use ($names) {
				$named = array_combine(array_slice($names, 0, count($args)), $args);
				$schema = ($named['schema'] ?? null);
				$this->writes[] = [
					'schema' => $schema,
					'rbac' => (bool)($named['_rbac'] ?? true),
					'multitenancy' => (bool)($named['_multitenancy'] ?? true),
				];

				$entity = new ObjectEntity();
				$entity->setUuid('00000000-0000-4000-8000-' . str_pad((string)count($this->writes), 12, '0', STR_PAD_LEFT));
				$entity->setObject(is_array($args[0]) === true ? $args[0] : []);
				if ($schema !== null) {
					$this->rows[(string)$schema][] = $entity;
				}

				return $entity;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$this->service = new EventService(
			$objectService,
			$this->createMock(IClientService::class),
			$logger,
			new WebhookSignatureService($logger),
			$this->createMock(SynchronizationService::class),
			$this->createMock(JobService::class),
			$this->createMock(CallService::class),
			$this->createMock(FlowRunnerService::class),
		);
	}//end setUp()

	/**
	 * A score raised in a sessionless request lands as a message on the
	 * subscription it matches, and the message is written in system context.
	 *
	 * @return void
	 */
	public function testAScoreRaisedWithoutASessionReachesItsSubscription(): void {
		$messages = $this->service->emitCloudEvent(
			type: 'nl.conduction.lti.ags.score.received',
			source: 'lti_deployment/899c7cf9-d16b-48eb-b29f-15be79d2d079',
			subject: 'c98d4939-9c20-4280-b1f3-545d0765a93c',
			data: ['lineItemId' => 'c98d4939-9c20-4280-b1f3-545d0765a93c', 'score' => ['userId' => 'learner-1', 'scoreGiven' => 8]]
		);

		$this->assertCount(1, $messages, 'the subscription must receive the event');
		$this->assertSame('34ab1b52-6a57-4a19-bd09-be251efd4d68', $messages[0]->getObject()['subscription']);
		$this->assertWritesInSystemContext('event_message');

	}//end testAScoreRaisedWithoutASessionReachesItsSubscription()

	/**
	 * The firehose gate sees the subscription in a sessionless listener.
	 *
	 * @return void
	 */
	public function testTheSubscriptionGateSeesSubscriptionsWithoutASession(): void {
		$this->assertTrue($this->service->hasActiveSubscriptions());

	}//end testTheSubscriptionGateSeesSubscriptionsWithoutASession()

	/**
	 * The loop guard recognises integriq's own schemas without a session.
	 *
	 * @return void
	 */
	public function testTheLoopGuardFindsItsOwnSchemasWithoutASession(): void {
		$event = new ObjectEntity();
		$event->setUuid('00000000-0000-4000-8000-00000000e001');
		$event->setSchema('1060');
		$event->setObject(['type' => 'x']);
		$this->rows['event'] = [$event];

		$this->assertSame(['1060'], $this->service->getSelfSchemaIds());

	}//end testTheLoopGuardFindsItsOwnSchemasWithoutASession()

	/**
	 * The pull returns the subscription's pending messages to its caller.
	 *
	 * @return void
	 */
	public function testThePullReturnsTheSubscriptionsMessages(): void {
		$this->service->emitCloudEvent(
			type: 'nl.conduction.lti.ags.score.received',
			source: 'lti_deployment/899c7cf9-d16b-48eb-b29f-15be79d2d079',
			subject: 'c98d4939-9c20-4280-b1f3-545d0765a93c',
			data: []
		);
		$pulled = $this->service->pullEvents(subscription: $this->rows['event_subscription'][0]);

		$this->assertCount(1, $pulled['messages']);

	}//end testThePullReturnsTheSubscriptionsMessages()

	/**
	 * Every message update on the delivery path is written in system context.
	 *
	 * @return void
	 */
	public function testMessageUpdatesRunInSystemContext(): void {
		$message = ObjectServiceMockBuilder::objectEntity(
			$this,
			['event' => 'e-1', 'subscription' => '34ab1b52-6a57-4a19-bd09-be251efd4d68', 'status' => 'pending', 'retryCount' => 0, 'attempts' => []],
			'00000000-0000-4000-8000-00000000f001'
		);

		(new ReflectionMethod(EventService::class, 'recordFailure'))->invoke($this->service, $message, 'HTTP 503', 503, null, ['maxRetries' => 5]);
		(new ReflectionMethod(EventService::class, 'recordDeliverySuccess'))->invoke($this->service, $message);
		(new ReflectionMethod(EventService::class, 'recordConfigurationError'))->invoke($this->service, $message, 'no action');

		$this->assertCount(3, array_filter($this->writes, static fn (array $write): bool => $write['schema'] === 'event_message'));
		$this->assertWritesInSystemContext('event_message');

	}//end testMessageUpdatesRunInSystemContext()

	/**
	 * Assert every recorded write to a schema ran with `_rbac: false` and `_multitenancy: false`.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return void
	 */
	private function assertWritesInSystemContext(string $schema): void {
		$writes = array_values(array_filter($this->writes, static fn (array $write): bool => $write['schema'] === $schema));
		$this->assertNotEmpty($writes, 'expected a write to ' . $schema);
		foreach ($writes as $index => $write) {
			$this->assertFalse($write['rbac'], $schema . ' write #' . $index . ' ran in the caller\'s RBAC context');
			$this->assertFalse($write['multitenancy'], $schema . ' write #' . $index . ' ran in the caller\'s tenant scope');
		}

	}//end assertWritesInSystemContext()
}//end class

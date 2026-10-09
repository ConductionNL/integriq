<?php

/**
 * A broker subscription's credential is a reference, resolved at publish.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/events-cloudevents/spec.md#requirement-broker-credentials-are-a-credential-reference-resolved-at-publish-req-ebsc-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Broker\BrokerCredentialResolver;
use OCA\Integriq\Broker\BrokerPublication;
use OCA\Integriq\Broker\BrokerResult;
use OCA\Integriq\Broker\BrokerTransportInterface;
use OCA\Integriq\Broker\BrokerTransportRegistry;
use OCA\Integriq\Exception\BrokeredCallConfigurationException;
use OCA\Integriq\Service\BrokeredCallService;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\EventService;
use OCA\Integriq\Service\FlowRunnerService;
use OCA\Integriq\Service\JobService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\Http\Client\IClientService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * REQ-EBSC-003 on EventService's broker dispatch.
 */
class EventServiceBrokerTest extends TestCase {

	/**
	 * @var \PHPUnit\Framework\MockObject\MockObject
	 */
	private $objectService;

	/**
	 * @var array The last event_message written.
	 */
	private array $capturedMessage = [];

	/**
	 * @var array|null What the transport was handed as its configuration.
	 */
	private ?array $capturedConfiguration = null;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = ObjectServiceMockBuilder::make($this);
		$this->capturedMessage = [];
		$this->capturedConfiguration = null;

	}//end setUp()

	/**
	 * An EventService with one recording transport and the given credential lookup.
	 *
	 * @param string $brokerId The transport id.
	 * @param mixed  $brokeredCalls The BrokeredCallService double.
	 *
	 * @return EventService
	 */
	private function service(string $brokerId, $brokeredCalls): EventService {
		$logger = $this->createMock(LoggerInterface::class);
		$transport = $this->createMock(BrokerTransportInterface::class);
		$transport->method('getId')->willReturn($brokerId);
		$transport->method('describe')->willReturn(['id' => $brokerId]);
		$transport->method('publish')->willReturnCallback(
			function (BrokerPublication $publication, array $configuration) use ($brokerId) {
				$this->capturedConfiguration = $configuration;
				return BrokerResult::published($brokerId);
			}
		);

		return new EventService(
			$this->objectService,
			$this->createMock(IClientService::class),
			$logger,
			new WebhookSignatureService($logger),
			$this->createMock(SynchronizationService::class),
			$this->createMock(JobService::class),
			$this->createMock(CallService::class),
			$this->createMock(FlowRunnerService::class),
			null,
			null,
			null,
			null,
			null,
			new BrokerTransportRegistry(logger: $logger, transports: [$transport]),
			null,
			new BrokerCredentialResolver($brokeredCalls),
		);

	}//end service()

	/**
	 * Wire one active broker subscription and return the event it matches.
	 *
	 * @param array $action The subscription's action.
	 * @param array $broker Its protocolSettings.broker block.
	 *
	 * @return ObjectEntity
	 */
	private function wire(array $action, array $broker): ObjectEntity {
		$subscription = ObjectServiceMockBuilder::objectEntity(
			$this,
			['status' => 'active', 'style' => 'push', 'action' => $action, 'protocolSettings' => ['broker' => $broker]],
			'sub-uuid'
		);
		$event = ObjectServiceMockBuilder::objectEntity(
			$this,
			['type' => 'nl.zaak.created', 'source' => '/dossiq/zaken', 'subject' => 'ZAAK-1', 'data' => []],
			'event-uuid'
		);
		$this->objectService->method('findAll')->willReturn(['results' => [$subscription], 'total' => 1]);
		$this->objectService->method('find')->willReturnCallback(
			static fn (string $id, string $register, string $schema) => ($schema === 'event' ? $event : $subscription)
		);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, ...$rest) {
				$this->capturedMessage = $object;
				return ObjectServiceMockBuilder::objectEntity($this, $object, 'msg-uuid');
			}
		);

		return $event;

	}//end wire()

	/**
	 * A publish uses the referenced credential: RabbitMQ gets the username
	 * from the subscription and the password from the credential.
	 *
	 * @return void
	 */
	public function testARabbitMqPublishUsesTheReferencedCredential(): void {
		$brokeredCalls = $this->createMock(BrokeredCallService::class);
		$brokeredCalls->expects($this->once())->method('resolveCredentialRef')
			->with(['credentialName' => 'rabbitmq-zaken'])
			->willReturn('s3cret');

		$event = $this->wire(
			['kind' => 'broker', 'brokerId' => 'rabbitmq', 'topic' => 'zaken', 'routingKey' => 'zaak.created'],
			['baseUrl' => 'https://rabbit.example.nl', 'vhost' => 'zaken', 'username' => 'integriq', 'credentialRef' => ['credentialName' => 'rabbitmq-zaken']]
		);

		$this->service('rabbitmq', $brokeredCalls)->processEvent($event);

		$this->assertSame('delivered', $this->capturedMessage['status']);
		$this->assertSame('integriq', $this->capturedConfiguration['username']);
		$this->assertSame('s3cret', $this->capturedConfiguration['password']);
		$this->assertSame('zaken', $this->capturedConfiguration['vhost']);
		$this->assertArrayNotHasKey('credentialRef', $this->capturedConfiguration);

	}//end testARabbitMqPublishUsesTheReferencedCredential()

	/**
	 * A token broker (a CloudEvents sink, or Kafka without a username) gets the secret as its token.
	 *
	 * @return void
	 */
	public function testATokenBrokerGetsTheSecretAsItsToken(): void {
		$brokeredCalls = $this->createMock(BrokeredCallService::class);
		$brokeredCalls->method('resolveCredentialRef')->willReturn('bearer-token');

		$event = $this->wire(
			['kind' => 'broker', 'brokerId' => 'kafka-rest', 'topic' => 'zaken'],
			['baseUrl' => 'https://kafka.example.nl', 'credentialRef' => ['credentialId' => '42']]
		);

		$this->service('kafka-rest', $brokeredCalls)->processEvent($event);

		$this->assertSame('bearer-token', $this->capturedConfiguration['token']);
		$this->assertArrayNotHasKey('password', $this->capturedConfiguration);

	}//end testATokenBrokerGetsTheSecretAsItsToken()

	/**
	 * An unknown credential fails once: a configuration error, no retry, no publish.
	 *
	 * @return void
	 */
	public function testAnUnknownCredentialFailsOnce(): void {
		$brokeredCalls = $this->createMock(BrokeredCallService::class);
		$brokeredCalls->method('resolveCredentialRef')
			->willThrowException(new BrokeredCallConfigurationException('No credential named rabbitmq-gone.'));

		$event = $this->wire(
			['kind' => 'broker', 'brokerId' => 'rabbitmq', 'topic' => 'zaken'],
			['baseUrl' => 'https://rabbit.example.nl', 'credentialRef' => ['credentialName' => 'rabbitmq-gone']]
		);

		$this->service('rabbitmq', $brokeredCalls)->processEvent($event);

		$this->assertSame('failed', $this->capturedMessage['status']);
		$this->assertSame(0, ($this->capturedMessage['retryCount'] ?? 0));
		$this->assertNull($this->capturedMessage['nextAttempt']);
		$this->assertStringContainsString('rabbitmq-gone', (string)$this->capturedMessage['error']);
		$this->assertNull($this->capturedConfiguration);

	}//end testAnUnknownCredentialFailsOnce()

	/**
	 * Settings without a reference reach the transport unchanged, and nothing is looked up.
	 *
	 * @return void
	 */
	public function testSettingsWithoutAReferenceAreLeftAlone(): void {
		$brokeredCalls = $this->createMock(BrokeredCallService::class);
		$brokeredCalls->expects($this->never())->method('resolveCredentialRef');

		$event = $this->wire(
			['kind' => 'broker', 'brokerId' => 'rabbitmq', 'topic' => 'zaken'],
			['baseUrl' => 'https://rabbit.example.nl', 'username' => 'u', 'password' => 'legacy']
		);

		$this->service('rabbitmq', $brokeredCalls)->processEvent($event);

		$this->assertSame('legacy', $this->capturedConfiguration['password']);

	}//end testSettingsWithoutAReferenceAreLeftAlone()

}//end class

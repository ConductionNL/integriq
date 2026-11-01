<?php

/**
 * Unit tests for the broker transports and their registry.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Broker
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-broker-that-accepted-a-message-it-delivered-to-nobody-is-a-failure-req-014
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Broker;

use OCA\Integriq\Broker\BrokerPublication;
use OCA\Integriq\Broker\BrokerResult;
use OCA\Integriq\Broker\BrokerTransportRegistry;
use OCA\Integriq\Broker\CloudEventHttpBinding;
use OCA\Integriq\Broker\Transport\CloudEventsHttpTransport;
use OCA\Integriq\Broker\Transport\KafkaRestTransport;
use OCA\Integriq\Broker\Transport\LogBrokerTransport;
use OCA\Integriq\Broker\Transport\RabbitMqHttpTransport;
use OCA\Integriq\Exception\BrokerTransportException;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the broker transports.
 *
 * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-broker-that-accepted-a-message-it-delivered-to-nobody-is-a-failure-req-014
 */
class BrokerTransportsTest extends TestCase {

	/**
	 * The last request each transport made, for the assertions that read it.
	 *
	 * @var array<string,mixed>
	 */
	private array $seen = [];

	/**
	 * A client service whose one client answers with a fixed response.
	 *
	 * @param integer $statusCode The status to answer.
	 * @param string $body The body to answer.
	 *
	 * @return IClientService The client service.
	 */
	private function clientAnswering(int $statusCode, string $body): IClientService {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($statusCode);
		$response->method('getBody')->willReturn($body);

		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(
			function (string $url, array $options) use ($response) {
				$this->seen = ['url' => $url, 'options' => $options];
				return $response;
			}
		);

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		return $clientService;
	}//end clientAnswering()

	/**
	 * One publication to send.
	 *
	 * @param string $contentMode The content mode.
	 *
	 * @return BrokerPublication The publication.
	 */
	private function publication(string $contentMode = CloudEventHttpBinding::MODE_STRUCTURED): BrokerPublication {
		return new BrokerPublication(
			cloudEvent: [
				'specversion' => '1.0',
				'id' => 'b9b6b0a2',
				'source' => '/integriq/zaken',
				'type' => 'nl.conduction.zaak.created',
				'datacontenttype' => 'application/json',
				'data' => ['zaak' => 'ZAAK-2026-0042'],
			],
			topic: 'zaken',
			routingKey: 'zaak.created',
			contentMode: $contentMode
		);
	}//end publication()

	/**
	 * REQ-014: RabbitMQ answering 200 with `routed: false` took the message
	 * and gave it to nobody, which is a failure and not a delivery.
	 *
	 * @return void
	 */
	public function testRabbitMqRoutedFalseUnderA200IsARefusal(): void {
		$transport = new RabbitMqHttpTransport(
			clientService: $this->clientAnswering(200, '{"routed": false}'),
			binding: new CloudEventHttpBinding()
		);

		$result = $transport->publish(
			publication: $this->publication(),
			configuration: ['baseUrl' => 'https://rabbit.example.nl', 'vhost' => '/', 'username' => 'u', 'password' => 'p']
		);

		$this->assertFalse($result->isPublished());
		$this->assertSame(BrokerResult::STATUS_REFUSED, $result->getStatus());
		$this->assertStringContainsString('routed it to no queue', (string)$result->getDetail());
		$this->assertStringContainsString('zaak.created', (string)$result->getDetail());
	}//end testRabbitMqRoutedFalseUnderA200IsARefusal()

	/**
	 * REQ-014: a routed publish is a delivery, and it goes to the exchange the
	 * publication names.
	 *
	 * @return void
	 */
	public function testRabbitMqRoutedTrueIsAPublish(): void {
		$transport = new RabbitMqHttpTransport(
			clientService: $this->clientAnswering(200, '{"routed": true}'),
			binding: new CloudEventHttpBinding()
		);

		$result = $transport->publish(
			publication: $this->publication(),
			configuration: ['baseUrl' => 'https://rabbit.example.nl/', 'vhost' => 'gemeente', 'username' => 'u', 'password' => 'p']
		);

		$this->assertTrue($result->isPublished());
		$this->assertSame('https://rabbit.example.nl/api/exchanges/gemeente/zaken/publish', $this->seen['url']);
		$this->assertSame('zaak.created', $this->seen['options']['json']['routing_key']);
		$this->assertSame(['u', 'p'], $this->seen['options']['auth']);
	}//end testRabbitMqRoutedTrueIsAPublish()

	/**
	 * A RabbitMQ publish with no configured baseUrl is reported as not
	 * configured, never attempted.
	 *
	 * @return void
	 */
	public function testRabbitMqWithoutABaseUrlIsUnconfigured(): void {
		$client = $this->createMock(IClient::class);
		$client->expects($this->never())->method('post');
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$transport = new RabbitMqHttpTransport(clientService: $clientService, binding: new CloudEventHttpBinding());
		$result = $transport->publish(publication: $this->publication(), configuration: []);

		$this->assertSame(BrokerResult::STATUS_UNCONFIGURED, $result->getStatus());
		$this->assertFalse($result->isPublished());
	}//end testRabbitMqWithoutABaseUrlIsUnconfigured()

	/**
	 * REQ-014: the Kafka REST Proxy reports a produce failure per record under
	 * an HTTP 200, and that is a refusal.
	 *
	 * @return void
	 */
	public function testKafkaErrorCodeUnderA200IsARefusal(): void {
		$transport = new KafkaRestTransport(
			clientService: $this->clientAnswering(200, '{"offsets":[{"partition":null,"offset":null,"error_code":1,"error":"leader not available"}]}')
		);

		$result = $transport->publish(
			publication: $this->publication(),
			configuration: ['baseUrl' => 'https://kafka-rest.example.nl', 'token' => 't']
		);

		$this->assertFalse($result->isPublished());
		$this->assertStringContainsString('error_code 1', (string)$result->getDetail());
		$this->assertStringContainsString('leader not available', (string)$result->getDetail());
	}//end testKafkaErrorCodeUnderA200IsARefusal()

	/**
	 * A clean Kafka produce is a publish, and the reference names the offset.
	 *
	 * @return void
	 */
	public function testKafkaCleanProduceIsAPublish(): void {
		$transport = new KafkaRestTransport(
			clientService: $this->clientAnswering(200, '{"offsets":[{"partition":2,"offset":41,"error_code":null,"error":null}]}')
		);

		$result = $transport->publish(
			publication: $this->publication(),
			configuration: ['baseUrl' => 'https://kafka-rest.example.nl', 'token' => 't']
		);

		$this->assertTrue($result->isPublished());
		$this->assertSame('zaken@2:41', $result->getReference());
		$this->assertSame('https://kafka-rest.example.nl/topics/zaken', $this->seen['url']);
		$this->assertSame('Bearer t', $this->seen['options']['headers']['Authorization']);
	}//end testKafkaCleanProduceIsAPublish()

	/**
	 * A Kafka answer carrying no offsets produced no record, whatever its
	 * status says.
	 *
	 * @return void
	 */
	public function testKafkaWithNoOffsetsIsARefusal(): void {
		$transport = new KafkaRestTransport(
			clientService: $this->clientAnswering(200, '{"offsets":[]}')
		);

		$result = $transport->publish(
			publication: $this->publication(),
			configuration: ['baseUrl' => 'https://kafka-rest.example.nl']
		);

		$this->assertFalse($result->isPublished());
		$this->assertStringContainsString('no offsets', (string)$result->getDetail());
	}//end testKafkaWithNoOffsetsIsARefusal()

	/**
	 * The generic sink posts the event in the content mode the publication
	 * asked for.
	 *
	 * @return void
	 */
	public function testTheGenericSinkHonoursBinaryContentMode(): void {
		$transport = new CloudEventsHttpTransport(
			clientService: $this->clientAnswering(202, ''),
			binding: new CloudEventHttpBinding()
		);

		$result = $transport->publish(
			publication: $this->publication(contentMode: CloudEventHttpBinding::MODE_BINARY),
			configuration: ['baseUrl' => 'https://sink.example.nl']
		);

		$this->assertTrue($result->isPublished());
		$this->assertSame('https://sink.example.nl/zaken', $this->seen['url']);
		$this->assertSame('nl.conduction.zaak.created', $this->seen['options']['headers']['ce-type']);
		$this->assertSame('application/json', $this->seen['options']['headers']['Content-Type']);
		$this->assertSame(['zaak' => 'ZAAK-2026-0042'], json_decode($this->seen['options']['body'], true));
	}//end testTheGenericSinkHonoursBinaryContentMode()

	/**
	 * A sink answering 4xx is a refusal carrying its status.
	 *
	 * @return void
	 */
	public function testTheGenericSinkReportsANonSuccessStatus(): void {
		$transport = new CloudEventsHttpTransport(
			clientService: $this->clientAnswering(422, 'nope'),
			binding: new CloudEventHttpBinding()
		);

		$result = $transport->publish(
			publication: $this->publication(),
			configuration: ['baseUrl' => 'https://sink.example.nl']
		);

		$this->assertFalse($result->isPublished());
		$this->assertSame(422, $result->getStatusCode());
	}//end testTheGenericSinkReportsANonSuccessStatus()

	/**
	 * REQ-016: the dormant transport logs and refuses, and can never report a
	 * send.
	 *
	 * @return void
	 */
	public function testTheDormantTransportNeverReportsASend(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('no broker is configured'));

		$result = (new LogBrokerTransport(logger: $logger))->publish(
			publication: $this->publication(),
			configuration: ['baseUrl' => 'https://this-is-ignored.example.nl']
		);

		$this->assertFalse($result->isPublished());
		$this->assertSame(BrokerResult::STATUS_UNCONFIGURED, $result->getStatus());
		$this->assertStringContainsString('Broker not configured', (string)$result->getDetail());
	}//end testTheDormantTransportNeverReportsASend()

	/**
	 * REQ-013: the registry keeps the first transport for an id and refuses
	 * the second, and names an id nothing answers to.
	 *
	 * @return void
	 */
	public function testTheRegistryKeepsTheFirstTransportForAnId(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$first = new LogBrokerTransport(logger: $logger);
		$second = new LogBrokerTransport(logger: $logger);

		$registry = new BrokerTransportRegistry(logger: $logger, transports: [$first]);

		$this->assertFalse($registry->register(transport: $second));
		$this->assertSame($first, $registry->get('log'));
		$this->assertSame(['log'], $registry->getBrokerIds());

		$this->expectException(BrokerTransportException::class);
		$this->expectExceptionMessage('No broker transport answers to "rabbitmq"');
		$registry->get('rabbitmq');
	}//end testTheRegistryKeepsTheFirstTransportForAnId()

	/**
	 * A publication that names no routing key routes on the event type, not on
	 * the empty string: a broker that routes on a key and gets an empty one
	 * delivers to nothing.
	 *
	 * @return void
	 */
	public function testAnAbsentRoutingKeyFallsBackToTheEventType(): void {
		$publication = new BrokerPublication(
			cloudEvent: ['id' => 'x', 'type' => 'nl.conduction.zaak.closed'],
			topic: 'zaken'
		);

		$this->assertSame('nl.conduction.zaak.closed', $publication->getRoutingKey());
	}//end testAnAbsentRoutingKeyFallsBackToTheEventType()

	/**
	 * Every RabbitMQ publish outcome matches the golden file recorded before
	 * publish() was split: the request sent and the result for configuration
	 * gaps, transport failures, status codes and the routed flag.
	 *
	 * Regenerate with RABBITMQ_PUBLISH_GOLDEN=1.
	 *
	 * @return void
	 */
	public function testEveryRabbitMqPublishMatchesTheGoldenFile(): void {
		$config = ['baseUrl' => ' https://rabbit.example.nl/ ', 'vhost' => ' a/b ', 'username' => 'u', 'password' => 'p'];
		$cases = [
			'no-base-url' => [[], 200, '{"routed": true}', null],
			'blank-base-url' => [['baseUrl' => '  '], 200, '{"routed": true}', null],
			'no-exchange' => [$config, 200, '{"routed": true}', 'no-topic'],
			'routed' => [$config, 200, '{"routed": true}', null],
			'default-vhost-no-credentials' => [['baseUrl' => 'https://r.example'], 200, '{"routed": true}', null],
			'routed-false' => [$config, 200, '{"routed": false}', null],
			'routed-missing' => [$config, 200, '{}', null],
			'routed-truthy-not-true' => [$config, 200, '{"routed": 1}', null],
			'not-json' => [$config, 200, 'nope', null],
			'status-199' => [$config, 199, '{"routed": true}', null],
			'status-299' => [$config, 299, '{"routed": true}', null],
			'status-300' => [$config, 300, '{"routed": true}', null],
			'transport-throws' => [$config, 0, '', null],
			'binary-mode-with-headers' => [$config, 200, '{"routed": true}', 'binary'],
		];

		$actual = [];
		foreach ($cases as $name => [$configuration, $status, $body, $variant]) {
			$this->seen = [];
			$clientService = $this->clientAnswering($status, $body);
			if ($status === 0) {
				$client = $this->createMock(IClient::class);
				$client->method('post')->willThrowException(new \RuntimeException('connection refused'));
				$clientService = $this->createMock(IClientService::class);
				$clientService->method('newClient')->willReturn($client);
			}

			$publication = $this->publication();
			if ($variant === 'no-topic') {
				$publication = new BrokerPublication(cloudEvent: $publication->getCloudEvent(), topic: '', routingKey: 'zaak.created');
			}

			if ($variant === 'binary') {
				$publication = new BrokerPublication(
					cloudEvent: $publication->getCloudEvent(),
					topic: 'zaken',
					routingKey: 'zaak.created',
					contentMode: CloudEventHttpBinding::MODE_BINARY,
					headers: ['X-Trace' => 't-1']
				);
			}

			$transport = new RabbitMqHttpTransport(clientService: $clientService, binding: new CloudEventHttpBinding());
			$result = $transport->publish(publication: $publication, configuration: $configuration);
			$actual[$name] = ['result' => $result->toArray(), 'sent' => $this->seen];
		}//end foreach

		$golden = __DIR__ . '/../../fixtures/broker/rabbitmq-publish.json';
		$encoded = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n";
		if (getenv('RABBITMQ_PUBLISH_GOLDEN') === '1') {
			if (is_dir(dirname($golden)) === false) {
				mkdir(dirname($golden), 0777, true);
			}

			file_put_contents($golden, $encoded);
		}

		$this->assertSame(json_decode((string)file_get_contents($golden), true), json_decode($encoded, true));
	}//end testEveryRabbitMqPublishMatchesTheGoldenFile()
}//end class

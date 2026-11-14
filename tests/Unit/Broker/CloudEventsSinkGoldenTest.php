<?php

/**
 * Golden tests for CloudEventsHttpTransport::publish(): the sink URL, the
 * request it posts, and every result it answers.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Broker
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-broker-that-accepted-a-message-it-delivered-to-nobody-is-a-failure-req-014
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Broker;

use OCA\Integriq\Broker\BrokerPublication;
use OCA\Integriq\Broker\CloudEventHttpBinding;
use OCA\Integriq\Broker\Transport\CloudEventsHttpTransport;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins the sink publish.
 */
class CloudEventsSinkGoldenTest extends TestCase {

	/**
	 * Posts made, as [url, options].
	 *
	 * @var list<array{0:string,1:array<string,mixed>}>
	 */
	private array $posts = [];

	/**
	 * A transport whose sink answers a status, or throws.
	 *
	 * @param int|\Exception $answer The status, or the exception.
	 *
	 * @return CloudEventsHttpTransport
	 */
	private function transport(int|\Exception $answer): CloudEventsHttpTransport {
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(
			function (string $url, array $options) use ($answer): IResponse {
				$this->posts[] = [$url, $options];
				if ($answer instanceof \Exception) {
					throw $answer;
				}

				$response = $this->createMock(IResponse::class);
				$response->method('getStatusCode')->willReturn($answer);
				return $response;
			}
		);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		return new CloudEventsHttpTransport(clientService: $clients, binding: new CloudEventHttpBinding());

	}//end transport()

	/**
	 * A publication into a topic, with extra headers.
	 *
	 * @param string $topic The topic.
	 *
	 * @return BrokerPublication
	 */
	private function publication(string $topic): BrokerPublication {
		return new BrokerPublication(
			cloudEvent: ['specversion' => '1.0', 'id' => 'ev-1', 'source' => '/s', 'type' => 't', 'datacontenttype' => 'application/json', 'data' => ['a' => 1]],
			topic: $topic,
			contentMode: CloudEventHttpBinding::MODE_STRUCTURED,
			headers: ['X-Extra' => 'e', 'Authorization' => 'Basic override']
		);

	}//end publication()

	/**
	 * Each configuration, topic, sink answer and result.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:string,2:int|\Exception,3:array<string,mixed>,4:string|null}>
	 */
	public static function publishes(): array {
		return [
			'no base url' => [['baseUrl' => '  '], 'z', 202, ['status' => 'not configured', 'detail' => 'The subscription names no CloudEvents sink baseUrl.'], null],
			'topic appended' => [['baseUrl' => ' https://sink/ '], '/zaken', 202, ['status' => 'published', 'reference' => 'ev-1'], 'https://sink/zaken'],
			'no topic' => [['baseUrl' => 'https://sink//', 'token' => ' tok '], '', 299, ['status' => 'published', 'reference' => 'ev-1'], 'https://sink'],
			'refused status' => [['baseUrl' => 'https://sink'], 'z', 302, ['status' => 'refused', 'detail' => 'The sink answered 302 to the event.', 'statusCode' => 302], 'https://sink/z'],
			'unreachable' => [['baseUrl' => 'https://sink'], 'z', new RuntimeException('dns'), ['status' => 'refused', 'detail' => 'The sink refused the event: dns'], 'https://sink/z'],
		];

	}//end publishes()

	/**
	 * Each publish posts and answers as before.
	 *
	 * @param array<string,mixed> $configuration The subscription configuration.
	 * @param string              $topic         The topic.
	 * @param int|\Exception      $answer        The sink's answer.
	 * @param array<string,mixed> $expected      The result's fields that matter.
	 * @param string|null         $url           The URL posted to, or null when nothing is posted.
	 *
	 * @return void
	 */
	#[DataProvider('publishes')]
	public function testPublish(array $configuration, string $topic, int|\Exception $answer, array $expected, ?string $url): void {
		$result = $this->transport($answer)->publish($this->publication($topic), $configuration);

		$this->assertSame($expected, array_intersect_key($result->toArray(), $expected));
		$this->assertSame('cloudevents-http', $result->getBrokerId());
		if ($url === null) {
			$this->assertSame([], $this->posts);
			return;
		}

		[$postedUrl, $options] = $this->posts[0];
		$this->assertSame($url, $postedUrl);
		$this->assertSame(['headers', 'body', 'timeout'], array_keys($options));
		$this->assertSame(30, $options['timeout']);
		$this->assertSame('e', $options['headers']['X-Extra']);
		$this->assertSame('Basic override', $options['headers']['Authorization']);
		$this->assertSame('ev-1', json_decode($options['body'], true)['id']);

	}//end testPublish()

	/**
	 * The token becomes a bearer header when the publication sets none.
	 *
	 * @return void
	 */
	public function testTokenBecomesBearer(): void {
		$publication = new BrokerPublication(cloudEvent: ['id' => 'ev-2', 'type' => 't'], topic: 'x');

		$this->transport(200)->publish($publication, ['baseUrl' => 'https://sink', 'token' => ' tok ']);

		$this->assertSame('Bearer tok', $this->posts[0][1]['headers']['Authorization']);

	}//end testTokenBecomesBearer()
}//end class

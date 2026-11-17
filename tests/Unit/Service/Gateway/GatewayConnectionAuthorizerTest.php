<?php

/**
 * Unit tests for the connection authorization a web server or broker asks for.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Gateway
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-web-server-or-broker-asks-integriq-who-may-connect-req-gqlp-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Gateway;

use OCA\Integriq\Exception\AuthenticationException;
use OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridge;
use OCA\Integriq\Service\Gateway\GatewayConnectionAuthorizer;
use OCA\Integriq\Service\Gateway\GatewayServerSecret;
use OCA\Integriq\Service\Gateway\StreamRouteMatcher;
use OCA\Integriq\Service\Helper\RegisterSave;
use OCA\Integriq\Service\RateLimit\InboundRateLimitServiceInterface;
use OCA\Integriq\Service\RateLimit\RateLimitDecision;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * One stream endpoint `ws/meldingen` (websocket, /ws/meldingen, scope
 * meldingen:read) and one consumer `c-1` behind the API key `good-key`.
 */
#[CoversClass(GatewayConnectionAuthorizer::class)]
#[UsesClass(StreamRouteMatcher::class)]
#[UsesClass(RegisterSave::class)]
#[UsesClass(RateLimitDecision::class)]
class GatewayConnectionAuthorizerTest extends TestCase {

	/**
	 * Call log rows written.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $logged = [];

	/**
	 * Rate limit keys asked for.
	 *
	 * @var list<string>
	 */
	private array $limited = [];

	/**
	 * An entity.
	 *
	 * @param string               $uuid The uuid.
	 * @param array<string, mixed> $data The data.
	 *
	 * @return ObjectEntity
	 */
	private static function entity(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data + ['uuid' => $uuid]);
		return $entity;
	}

	/**
	 * The authorizer over one endpoint and one consumer.
	 *
	 * @param array<string, mixed> $endpoint      Overrides of the stream endpoint.
	 * @param array<string, mixed> $consumer      Overrides of the consumer.
	 * @param bool                 $secretOk      Whether the server secret holds.
	 * @param bool                 $rateAllowed   Whether the connection limit allows.
	 *
	 * @return GatewayConnectionAuthorizer
	 */
	private function authorizer(array $endpoint=[], array $consumer=[], bool $secretOk=true, bool $rateAllowed=true): GatewayConnectionAuthorizer {
		$secret = $this->createMock(GatewayServerSecret::class);
		$secret->method('verify')->willReturnCallback(fn (string $presented) => $secretOk && $presented === 'server-secret');

		$consumerEntity = self::entity('c-1', $consumer + ['name' => 'Meldingen app', 'scopes' => ['meldingen:read']]);
		$resolved = null;
		$bridge = $this->createMock(OpenRegisterCredentialBridge::class);
		$bridge->method('authorizeApiKey')->willReturnCallback(
			function (string $header, array $keys) use (&$resolved, $consumerEntity): void {
				if ($header !== 'good-key') {
					$resolved = null;
					throw new AuthenticationException('Invalid API key');
				}

				$resolved = $consumerEntity;
			}
		);
		$bridge->method('getResolvedConsumer')->willReturnCallback(function () use (&$resolved) {
			return $resolved;
		});

		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturn(
			[
				'results' => [
					self::entity('ep-other', ['targetType' => 'stream', 'streamProtocol' => 'websocket', 'streamRoute' => '/ws/other']),
					self::entity(
						'ep-1',
						$endpoint + [
							'name' => 'ws/meldingen',
							'targetType' => 'stream',
							'streamProtocol' => 'websocket',
							'streamRoute' => '/ws/meldingen',
							'requiredScopes' => ['meldingen:read'],
							'connectionRateLimit' => ['requestsPerWindow' => 10, 'windowSeconds' => 60],
						]
					),
				],
			]
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object) {
				$this->logged[] = $object;
				return new ObjectEntity();
			}
		);

		$rates = $this->createMock(InboundRateLimitServiceInterface::class);
		$rates->method('enforce')->willReturnCallback(
			function (string $consumerKey) use ($rateAllowed) {
				$this->limited[] = $consumerKey;
				return new RateLimitDecision(allowed: $rateAllowed, hasRateLimit: true, limit: 10, remaining: 0, resetSeconds: 20, retryAfter: ($rateAllowed ? null : 20), reason: ($rateAllowed ? null : RateLimitDecision::REASON_RATE_LIMIT));
			}
		);

		return new GatewayConnectionAuthorizer(
			serverSecret: $secret,
			credentials: $bridge,
			objects: $objects,
			rateLimits: $rates,
			logger: $this->createMock(LoggerInterface::class)
		);
	}

	/**
	 * A WebSocket connection to /ws/meldingen.
	 *
	 * @param array<string, string> $overrides Overrides.
	 *
	 * @return array<string, string>
	 */
	private static function ask(array $overrides=[]): array {
		return $overrides + [
			'serverSecret' => 'server-secret',
			'protocol' => 'websocket',
			'target' => '/ws/meldingen?x=1',
			'credential' => 'good-key',
			'clientAddress' => '10.0.0.5',
			'connectionId' => 'conn-42',
		];
	}

	public function testAValidKeyGets200WithTheConsumerAndOneAllowedLogRow(): void {
		$answer = $this->authorizer()->authorize(ask: self::ask());

		$this->assertSame(200, $answer['status']);
		$this->assertSame('c-1', $answer['consumer']);
		$this->assertSame(['X-Integriq-Consumer' => 'c-1'], $answer['headers']);
		$this->assertCount(1, $this->logged);
		$this->assertSame(200, $this->logged[0]['statusCode']);
		$this->assertSame('websocket', $this->logged[0]['protocol']);
		$this->assertSame('conn-42', $this->logged[0]['connectionId']);
		$this->assertSame('ep-1', $this->logged[0]['endpoint']);
		$this->assertSame('c-1', $this->logged[0]['consumer']);
		$this->assertSame('inbound', $this->logged[0]['direction']);
		$this->assertSame(['stream:ep-1:consumer:c-1'], $this->limited);
	}

	public function testAWrongServerSecretIs401AndLogsNothing(): void {
		$answer = $this->authorizer()->authorize(ask: self::ask(['serverSecret' => 'guess']));

		$this->assertSame(401, $answer['status']);
		$this->assertSame('server_not_authorized', $answer['reason']);
		$this->assertNull($answer['consumer']);
		$this->assertSame([], $this->logged);
	}

	public function testAClientWithoutAKeyIs401AndLoggedAsRefused(): void {
		$answer = $this->authorizer()->authorize(ask: self::ask(['credential' => '']));

		$this->assertSame(401, $answer['status']);
		$this->assertSame('no_consumer', $answer['reason']);
		$this->assertCount(1, $this->logged);
		$this->assertSame(401, $this->logged[0]['statusCode']);
		$this->assertSame('Connection refused: no_consumer', $this->logged[0]['statusMessage']);
		$this->assertArrayNotHasKey('consumer', $this->logged[0]);
	}

	public function testAWrongKeyIs401(): void {
		$answer = $this->authorizer()->authorize(ask: self::ask(['credential' => 'bad-key']));

		$this->assertSame(401, $answer['status']);
		$this->assertSame('no_consumer', $answer['reason']);
	}

	public function testAPathNoStreamEndpointCoversIs401(): void {
		$answer = $this->authorizer()->authorize(ask: self::ask(['target' => '/ws/elders']));

		$this->assertSame(401, $answer['status']);
		$this->assertSame('no_stream_endpoint', $answer['reason']);
		$this->assertArrayNotHasKey('endpoint', $this->logged[0]);
	}

	public function testAnUnknownProtocolIs401(): void {
		$answer = $this->authorizer()->authorize(ask: self::ask(['protocol' => 'telnet']));

		$this->assertSame(401, $answer['status']);
		$this->assertSame('unknown_connection', $answer['reason']);
		$this->assertSame([], $this->logged);
	}

	public function testAConsumerWithoutTheRequiredScopeIs401(): void {
		$answer = $this->authorizer(consumer: ['scopes' => ['zaken:read']])->authorize(ask: self::ask());

		$this->assertSame(401, $answer['status']);
		$this->assertSame('consumer_not_allowed', $answer['reason']);
		$this->assertSame([], $this->limited, 'a refused consumer spends no connection allowance');
	}

	public function testAConsumerOffTheAllowedListIs401(): void {
		$answer = $this->authorizer(endpoint: ['allowedConsumers' => ['c-9']])->authorize(ask: self::ask());

		$this->assertSame(401, $answer['status']);
		$this->assertSame('consumer_not_allowed', $answer['reason']);
	}

	public function testPastTheConnectionLimitIs429(): void {
		$answer = $this->authorizer(rateAllowed: false)->authorize(ask: self::ask());

		$this->assertSame(429, $answer['status']);
		$this->assertSame('rate_limited', $answer['reason']);
		$this->assertNull($answer['consumer']);
		$this->assertSame('20', (string)$answer['headers']['Retry-After']);
		$this->assertSame(429, $this->logged[0]['statusCode']);
	}

	public function testAnMqttTopicUnderTheFilterIsAllowed(): void {
		$answer = $this->authorizer(endpoint: ['streamProtocol' => 'mqtt', 'streamRoute' => 'meldingen/#'])
			->authorize(ask: self::ask(['protocol' => 'mqtt', 'target' => 'meldingen/123']));

		$this->assertSame(200, $answer['status']);
		$this->assertSame('mqtt', $this->logged[0]['protocol']);
	}

	public function testTheAllowListAndScopesAreReadTogether(): void {
		$this->assertTrue(GatewayConnectionAuthorizer::consumerAllowed(endpointData: [], consumerUuid: 'c-1', consumerData: []));
		$this->assertTrue(GatewayConnectionAuthorizer::consumerAllowed(endpointData: ['allowedConsumers' => ['c-1'], 'requiredScopes' => ['a']], consumerUuid: 'c-1', consumerData: ['scopes' => ['a', 'b']]));
		$this->assertFalse(GatewayConnectionAuthorizer::consumerAllowed(endpointData: ['requiredScopes' => ['a', 'c']], consumerUuid: 'c-1', consumerData: ['scopes' => ['a']]));
		$this->assertFalse(GatewayConnectionAuthorizer::consumerAllowed(endpointData: ['requiredScopes' => ['a']], consumerUuid: 'c-1', consumerData: ['scopes' => 'a']));
	}
}

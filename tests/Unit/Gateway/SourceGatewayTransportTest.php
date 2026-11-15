<?php

/**
 * The gateway transport hands its payload to the call engine as JSON.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Gateway
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#requirement-digital-post-is-stored-as-its-service-account-req-dpa-007
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Gateway;

use OCA\Integriq\Gateway\SourceGatewayTransport;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\ConnectionStore;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Guzzle refuses an array under `body`, so every gateway send (Postex, CORV,
 * GGK, WKPB) failed before it left: "Passing in the "body" request option as
 * an array to send a request is not supported" (measured on idp-live,
 * 2026-10-07). The payload goes under `json`, as every other caller does.
 */
class SourceGatewayTransportTest extends TestCase {

	/**
	 * The payload reaches the call engine as JSON, and the answer's id is the reference.
	 *
	 * @return void
	 */
	public function testThePayloadIsSentAsJson(): void {
		$source = new ObjectEntity();
		$source->setObject(['slug' => 'postex']);
		$store = $this->getMockBuilder(ConnectionStore::class)->disableOriginalConstructor()->onlyMethods(['findSourceBySlug'])->getMock();
		$store->method('findSourceBySlug')->willReturn($source);

		$seen = [];
		$callLog = new ObjectEntity();
		$callLog->setObject(['statusCode' => 201, 'response' => ['body' => '{"id":"postex-1"}']]);
		$calls = $this->getMockBuilder(CallService::class)->disableOriginalConstructor()->onlyMethods(['call'])->getMock();
		$calls->method('call')->willReturnCallback(
			function (ObjectEntity $source, string $endpoint = '', string $method = 'GET', array $config = []) use (&$seen, $callLog): ObjectEntity {
				$seen = ['endpoint' => $endpoint, 'method' => $method, 'config' => $config];
				return $callLog;
			}
		);

		$delivery = (new SourceGatewayTransport($store, $calls, new NullLogger()))->send(
			'postex',
			['campaign' => 'c', 'recipient' => '999993653'],
			['source' => 'postex', 'endpoint' => '/messages', 'method' => 'POST']
		);

		$config = ($seen['config'] ?? []);
		$this->assertSame(['campaign' => 'c', 'recipient' => '999993653'], ($config['json'] ?? null));
		$this->assertArrayNotHasKey('body', $config, 'an array body is refused by Guzzle');
		$this->assertTrue($delivery->isDelivered());
		$this->assertSame('postex-1', $delivery->getIdentifier());

	}//end testThePayloadIsSentAsJson()

	/**
	 * Every delivery send() answers: no source, a failing call, refusing and
	 * accepting answers in each body shape, with the calls made and the
	 * warning logged.
	 *
	 * @return void
	 */
	public function testEveryDeliveryOutcome(): void {
		$cases = [
			'no-source' => [null, [], []],
			'call-throws' => ['throw', [], ['transport' => 'digikoppeling']],
			'created-identificatie-wins' => [['statusCode' => 201, 'response' => ['body' => '{"identificatie":7,"identifier":"i","id":"x"}']], [], []],
			'ok-identifier' => [['statusCode' => 200, 'response' => ['body' => ['identifier' => 'id-2']]], [], ['endpoint' => '/e', 'method' => 'PUT']],
			'ok-no-identifier' => [['statusCode' => 299, 'response' => ['body' => 'not json']], [], []],
			'refused-detail' => [['statusCode' => 422, 'response' => ['body' => '{"detail":"Bad","error":"E"}']], [], ['transport' => 'x']],
			'refused-error' => [['statusCode' => 500, 'response' => ['body' => ['error' => 'Boom']]], [], []],
			'refused-plain' => [['statusCode' => 199, 'response' => []], [], []],
			'no-status' => [['response' => ['body' => '{"id":"z"}']], [], []],
		];

		$found = [];
		foreach ($cases as $name => [$answer, $payload, $config]) {
			$store = $this->getMockBuilder(ConnectionStore::class)->disableOriginalConstructor()->onlyMethods(['findSourceBySlug'])->getMock();
			$slugs = [];
			$store->method('findSourceBySlug')->willReturnCallback(
				function (string $slug) use (&$slugs, $answer): ?ObjectEntity {
					$slugs[] = $slug;
					return ($answer === null ? null : new ObjectEntity());
				}
			);
			$seen = [];
			$calls = $this->getMockBuilder(CallService::class)->disableOriginalConstructor()->onlyMethods(['call'])->getMock();
			$calls->method('call')->willReturnCallback(
				function (ObjectEntity $source, string $endpoint = '', string $method = 'GET', array $config = []) use (&$seen, $answer): ObjectEntity {
					$seen[] = [$endpoint, $method, $config];
					if ($answer === 'throw') {
						throw new \RuntimeException('connection reset');
					}

					$callLog = new ObjectEntity();
					$callLog->setObject($answer);
					return $callLog;
				}
			);
			$warnings = [];
			$logger = $this->createMock(\Psr\Log\LoggerInterface::class);
			$logger->method('warning')->willReturnCallback(
				function (string $message, array $context = []) use (&$warnings): void {
					$warnings[] = [$message, $context];
				}
			);

			$delivery = (new SourceGatewayTransport($store, $calls, $logger))->send('postex', ['a' => 1], $config);
			$found[$name] = [$delivery->toArray(), $delivery->isDelivered(), $delivery->wasTransmitted(), $slugs, $seen, $warnings];
		}

		$this->assertSame(
			[
				'no-source' => [
					['gateway' => 'postex', 'delivered' => false, 'identifier' => null, 'reason' => 'The gateway "postex" has no source configured under "postex". Nothing was sent.', 'transmitted' => false, 'replayable' => false, 'transport' => 'https'],
					false, false, ['postex'], [], [],
				],
			],
			['no-source' => $found['no-source']]
		);
		$this->assertSame([false, true, [['', 'POST', ['json' => ['a' => 1]]]], [['gateway.transport.failed', ['gateway' => 'postex', 'error' => 'connection reset']]]], [$found['call-throws'][1], $found['call-throws'][2], $found['call-throws'][4], $found['call-throws'][5]]);
		$this->assertSame(['connection reset', 'digikoppeling'], [$found['call-throws'][0]['reason'], $found['call-throws'][0]['transport']]);
		$this->assertSame([true, '7', 'https'], [$found['created-identificatie-wins'][1], $found['created-identificatie-wins'][0]['identifier'], $found['created-identificatie-wins'][0]['transport']]);
		$this->assertSame([true, 'id-2', [['/e', 'PUT', ['json' => ['a' => 1]]]]], [$found['ok-identifier'][1], $found['ok-identifier'][0]['identifier'], $found['ok-identifier'][4]]);
		$this->assertSame([true, null], [$found['ok-no-identifier'][1], $found['ok-no-identifier'][0]['identifier']]);
		$this->assertSame([false, 'Bad', 'x'], [$found['refused-detail'][1], $found['refused-detail'][0]['reason'], $found['refused-detail'][0]['transport']]);
		$this->assertSame([false, 'Boom'], [$found['refused-error'][1], $found['refused-error'][0]['reason']]);
		$this->assertSame([false, 'The gateway answered HTTP 199.'], [$found['refused-plain'][1], $found['refused-plain'][0]['reason']]);
		$this->assertSame([false, 'The gateway answered HTTP 0.'], [$found['no-status'][1], $found['no-status'][0]['reason']]);

	}//end testEveryDeliveryOutcome()
}//end class

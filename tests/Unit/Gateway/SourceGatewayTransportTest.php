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
}//end class

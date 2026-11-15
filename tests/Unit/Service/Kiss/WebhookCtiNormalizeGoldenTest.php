<?php

/**
 * Golden test for WebhookCtiProvider::normalize.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Kiss
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Kiss;

use OCA\Integriq\Service\Kiss\CallEventNormaliser;
use OCA\Integriq\Service\Kiss\WebhookCtiProvider;
use OCA\Integriq\Service\WebhookSignatureService;
use PHPUnit\Framework\TestCase;

/**
 * Pins what each webhook payload shape hands the normaliser and which events survive.
 */
class WebhookCtiNormalizeGoldenTest extends TestCase {

	/**
	 * The calls the normaliser saw and the events returned.
	 *
	 * @param array $configuration The source configuration.
	 * @param array $payload       The webhook payload.
	 *
	 * @return array{calls: array, events: array} The calls and events.
	 */
	private function normalize(array $configuration, array $payload): array {
		$calls      = [];
		$normaliser = $this->createMock(CallEventNormaliser::class);
		$normaliser->method('normalise')->willReturnCallback(
			static function (array $payload, array $mapping=[], array $kindMap=[]) use (&$calls): ?array {
				$calls[] = [$payload, $mapping, $kindMap];
				if (($payload['drop'] ?? false) === true) {
					return null;
				}

				return ['callId' => $payload['id'] ?? ''];
			}
		);
		$provider = new WebhookCtiProvider($normaliser, $this->createMock(WebhookSignatureService::class));
		$events   = $provider->normalize($configuration, $payload);

		return ['calls' => $calls, 'events' => $events];

	}//end normalize()

	/**
	 * Every payload shape.
	 *
	 * @return void
	 */
	public function testEveryShape(): void {
		$this->assertSame(
			['calls' => [[['id' => 'c-1'], [], []]], 'events' => [['callId' => 'c-1']]],
			$this->normalize([], ['id' => 'c-1'])
		);

		$this->assertSame(
			[
				'calls' => [[['id' => 'a'], ['callId' => 'id'], ['alerting' => 'ringing']], [['id' => 'b', 'drop' => true], ['callId' => 'id'], ['alerting' => 'ringing']]],
				'events' => [['callId' => 'a']],
			],
			$this->normalize(
				['eventsPath' => 'data.events', 'fieldMapping' => ['callId' => 'id'], 'kindMapping' => ['alerting' => 'ringing']],
				['data' => ['events' => [['id' => 'a'], 'not-an-item', ['id' => 'b', 'drop' => true]]]]
			)
		);

		$this->assertSame(
			['calls' => [[['id' => 'x'], [], []]], 'events' => [['callId' => 'x']]],
			$this->normalize(['eventsPath' => 'events', 'fieldMapping' => 'nope', 'kindMapping' => 5], ['events' => [['id' => 'x']]])
		);

		$this->assertSame(['calls' => [], 'events' => []], $this->normalize(['eventsPath' => 'missing.path'], ['data' => []]));
		$this->assertSame(['calls' => [], 'events' => []], $this->normalize(['eventsPath' => 'data'], ['data' => 'text']));

	}//end testEveryShape()
}//end class

<?php

/**
 * Unit tests for matching a connection against a stream endpoint's route.
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

use OCA\Integriq\Service\Gateway\StreamRouteMatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(StreamRouteMatcher::class)]
class StreamRouteMatcherTest extends TestCase {

	/**
	 * Protocol, route, target, expected.
	 *
	 * @return array<string, array{string, string, string, bool}>
	 */
	public static function cases(): array {
		return [
			'websocket path equal' => ['websocket', '/ws/meldingen', '/ws/meldingen', true],
			'websocket trailing slash' => ['websocket', '/ws/meldingen/', '/ws/meldingen', true],
			'websocket query string ignored' => ['websocket', '/ws/meldingen', '/ws/meldingen?token=x', true],
			'websocket other path' => ['websocket', '/ws/meldingen', '/ws/meldingen-oud', false],
			'websocket below without star' => ['websocket', '/ws/meldingen', '/ws/meldingen/1', false],
			'websocket prefix with star' => ['websocket', '/ws/*', '/ws/meldingen/1', true],
			'grpc service prefix' => ['grpc', '/zaken.v1.Zaken/*', '/zaken.v1.Zaken/GetZaak', true],
			'grpc other service' => ['grpc', '/zaken.v1.Zaken/*', '/besluiten.v1.Besluiten/Get', false],
			'empty route matches nothing' => ['websocket', ' ', '/ws', false],
			'mqtt exact topic' => ['mqtt', 'meldingen/nieuw', 'meldingen/nieuw', true],
			'mqtt plus one level' => ['mqtt', 'meldingen/+/status', 'meldingen/123/status', true],
			'mqtt plus is not two levels' => ['mqtt', 'meldingen/+/status', 'meldingen/1/2/status', false],
			'mqtt hash the rest' => ['mqtt', 'meldingen/#', 'meldingen/123/status', true],
			'mqtt hash includes parent' => ['mqtt', 'meldingen/#', 'meldingen', true],
			'mqtt hash only last' => ['mqtt', 'meldingen/#/x', 'meldingen/a/x', false],
			'mqtt shorter topic' => ['mqtt', 'meldingen/+', 'meldingen', false],
			'mqtt longer topic' => ['mqtt', 'meldingen', 'meldingen/1', false],
			'mqtt wildcard in topic refused' => ['mqtt', 'meldingen/#', 'meldingen/+', false],
		];
	}

	#[DataProvider('cases')]
	public function testMatches(string $protocol, string $route, string $target, bool $expected): void {
		$this->assertSame($expected, StreamRouteMatcher::matches(protocol: $protocol, route: $route, target: $target));
	}
}

<?php

/**
 * Characterisation test for the header extraction of FlowToken.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Helper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Helper;

use OCA\Integriq\Service\Helper\FlowToken;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Golden record of the request headers a flow token keeps.
 */
class FlowTokenHeadersGoldenTest extends TestCase {

	/**
	 * The recorded header maps.
	 *
	 * @return void
	 */
	public function testGoldenRecord(): void {
		$server = [
			'HTTP_HOST' => 'integriq.example.test',
			'HTTP_CONTENT_TYPE' => 'application/json',
			'HTTP_X_FORWARDED_FOR' => '10.0.0.1',
			'HTTP_X_FORWARDED_PROTO' => 'https',
			'HTTP_X_REAL_IP' => '10.0.0.2',
			'HTTP_X_ORIGINAL_URI' => '/api/x',
			'HTTP_X_REAL_IP_EXTRA' => 'kept',
			'HTTP_AUTHORIZATION' => 'Bearer t',
			'REQUEST_METHOD' => 'POST',
			'CONTENT_LENGTH' => '12',
			'http_lower' => 'not a header',
			'HTTP_' => 'empty name',
			'HTTP_Mixed_Case' => 'm',
		];

		$token = new FlowToken();
		$headers = new ReflectionMethod(FlowToken::class, 'getHeaders');
		$record = [
			'without proxy headers' => $headers->invoke($token, $server, false),
			'with proxy headers' => $headers->invoke($token, $server, true),
			'default' => $headers->invoke($token, $server),
			'empty server' => $headers->invoke($token, [], true),
		];

		$backup = $_SERVER;
		$_SERVER = $server;
		$request = $this->createMock(IRequest::class);
		$request->method('getMethod')->willReturn('POST');
		$request->method('getHeader')->willReturn('');
		$request->method('getParams')->willReturn(['a' => 1]);
		$record['request original'] = (new FlowToken(requestOriginal: $request, path: '/p'))->getRequestOriginal();
		$_SERVER = $backup;

		// Header maps as [name, value] pairs: the `HTTP_` case yields an empty
		// name, which a JSON object key may not be in the linted fixtures.
		$record = array_map(static fn (array $entry): array => self::pairs(map: $entry), $record);

		$path = __DIR__ . '/../../../fixtures/helper/flow-token-headers.json';
		$actual = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents($path, $actual);
		}

		$this->assertSame(file_get_contents($path), $actual);
	}//end testGoldenRecord()

	/**
	 * A map as ordered [key, value] pairs, nested maps included.
	 *
	 * @param array<mixed> $map The map.
	 *
	 * @return list<array{0: int|string, 1: mixed}>
	 */
	private static function pairs(array $map): array {
		$pairs = [];
		foreach ($map as $key => $value) {
			$pairs[] = [$key, (is_array($value) === true && $value !== [] ? self::pairs(map: $value) : $value)];
		}

		return $pairs;
	}//end pairs()
}//end class

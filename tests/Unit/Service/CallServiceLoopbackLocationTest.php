<?php

/**
 * Which source locations count as loopback for the TLS-verification exemption.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/http-call-engine/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\CallService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * CallService::isLoopbackLocation(): only localhost, ::1 and 127.x hosts are
 * exempt from the verify:false guard, with or without scheme, port or path.
 */
#[CoversClass(CallService::class)]
class CallServiceLoopbackLocationTest extends TestCase {

	/**
	 * The locations and whether they are loopback.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function locations(): array {
		return [
			'empty'                       => ['', false],
			'http localhost'              => ['http://localhost', true],
			'https localhost with port'   => ['https://LOCALHOST:8443/api', true],
			'bare localhost with port'    => ['localhost:8080', true],
			'bare localhost with path'    => ['localhost/path/to', true],
			'bare localhost port and path' => ['localhost:8080/path', true],
			'127 block'                   => ['http://127.0.0.1:9000/x', true],
			'127 other address'           => ['https://127.10.20.30', true],
			'bare 127 address'            => ['127.0.0.1:81', true],
			'ipv6 loopback'               => ['http://[::1]:8080/', true],
			'public host'                 => ['https://api.example.org/v1', false],
			'localhost as subdomain'      => ['https://localhost.example.org', false],
			'128 address'                 => ['http://128.0.0.1', false],
			'bare public host'            => ['example.org:443', false],
			'other ipv6'                  => ['http://[2001:db8::1]/', false],
		];

	}//end locations()

	/**
	 * Each location is classified as the guard expects.
	 *
	 * @param string $location The source location.
	 * @param bool   $expected Whether it is loopback.
	 *
	 * @return void
	 */
	#[DataProvider('locations')]
	public function testTheLocationIsClassified(string $location, bool $expected): void {
		$reflection = new ReflectionClass(CallService::class);
		$service = $reflection->newInstanceWithoutConstructor();
		$method = $reflection->getMethod('isLoopbackLocation');

		$this->assertSame($expected, $method->invoke($service, $location));

	}//end testTheLocationIsClassified()
}//end class

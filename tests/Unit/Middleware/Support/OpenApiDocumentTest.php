<?php

/**
 * Unit tests that keep openapi.json and appinfo/routes.php in step.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Middleware\Support
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Middleware\Support;

use OCA\Integriq\Middleware\Support\StandardProtocolPaths;
use PHPUnit\Framework\TestCase;

/**
 * The OpenAPI document describes the `/api/v1/` paths, and exactly the ones
 * the app routes: no documented path without a route, no versioned route
 * left out.
 *
 * @covers \OCA\Integriq\Middleware\Support\StandardProtocolPaths
 * @uses   \OCA\Integriq\Middleware\Support\ApiPathAliases
 */
class OpenApiDocumentTest extends TestCase {

	/**
	 * The document is OpenAPI 3.0 and its server is the versioned API root.
	 *
	 * @return void
	 */
	public function testTheServerIsVersioned(): void {
		$document = self::document();

		$this->assertStringStartsWith(prefix: '3.0.', string: $document['openapi']);
		$this->assertStringEndsWith(suffix: '/apps/integriq/api/v1', string: $document['servers'][0]['url']);
	}//end testTheServerIsVersioned()

	/**
	 * Every documented operation has a versioned route, and every versioned route is documented.
	 *
	 * @return void
	 */
	public function testTheDocumentMatchesTheRoutes(): void {
		// Operations marked x-provided-by come from OpenRegister's AppHost route table, which tests may not load.
		$documented = [];
		$hosted     = [];
		$protocol  = [];
		$standards = dirname(__DIR__, 4).'/openapi.standards.json';
		if (is_file($standards) === true) {
			// The endpoints that implement an external standard, under their unversioned base.
			$document = json_decode((string) file_get_contents($standards), true);
			$this->assertStringEndsWith(suffix: '/apps/integriq/api', string: $document['servers'][0]['url']);
			foreach (array_keys($document['paths']) as $path) {
				$this->assertTrue(condition: StandardProtocolPaths::matches(path: '/api'.$path), message: $path.' is not a standard protocol path');
				$protocol[] = '/api'.self::shape(path: $path);
			}
		}

		foreach (self::document()['paths'] as $path => $item) {
			foreach ($item as $verb => $operation) {
				if (in_array($verb, ['get', 'put', 'post', 'delete', 'patch', 'options', 'head'], true) === false) {
					continue;
				}

				if (($operation['x-provided-by'] ?? null) === 'openregister') {
					$hosted[] = strtoupper($verb).' '.self::shape(path: '/api/v1'.$path);
				} else {
					$documented[] = strtoupper($verb).' '.self::shape(path: '/api/v1'.$path);
				}
			}
		}

		$routed   = [];
		$declared = require dirname(__DIR__, 4).'/appinfo/routes.php';
		$urls     = array_map(static fn (array $route): string => self::shape(path: '/'.ltrim((string) $route['url'], '/')), $declared['routes']);
		$this->assertSame(expected: [], actual: array_values(array_diff($protocol, $urls)), message: 'documented protocol paths without a route');
		foreach ($declared['routes'] as $route) {
			$url = '/'.ltrim((string) $route['url'], '/');
			// The /api/v1/ twin of a standard protocol path is served too,
			// but documented at its own path in openapi.standards.json.
			$source = '/api/'.substr($url, strlen('/api/v1/'));
			$protocolTwin = (StandardProtocolPaths::matches(path: $url) === true || StandardProtocolPaths::matches(path: $source) === true);
			if (str_starts_with($url, '/api/v1/') === true && $protocolTwin === false) {
				$routed[] = strtoupper((string) ($route['verb'] ?? 'GET')).' '.self::shape(path: $url);
			}
		}

		$routed = array_values(array_unique(array_diff($routed, $hosted)));
		sort($documented);
		sort($routed);
		$this->assertSame(expected: [], actual: array_values(array_diff($documented, $routed)), message: 'documented but not routed');
		$this->assertSame(expected: [], actual: array_values(array_diff($routed, $documented)), message: 'routed but not documented');
	}//end testTheDocumentMatchesTheRoutes()

	/**
	 * The decoded openapi.json.
	 *
	 * @return array<string,mixed>
	 */
	private static function document(): array {
		$document = json_decode((string) file_get_contents(dirname(__DIR__, 4).'/openapi.json'), true);
		self::assertIsArray(actual: $document);

		return $document;
	}//end document()

	/**
	 * A path with its placeholder names left out, so `/a/{id}` and `/a/{uuid}` compare equal.
	 *
	 * @param string $path The path.
	 *
	 * @return string The shape of the path.
	 */
	private static function shape(string $path): string {
		return (string) preg_replace('#\{[^}]+\}#', '{}', $path);
	}//end shape()
}//end class

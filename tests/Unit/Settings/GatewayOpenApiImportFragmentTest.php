<?php

/**
 * Unit tests for the gateway-openapi-import-and-publish register fragment:
 * after every fragment is merged the way the repair step merges them, source
 * carries `openApiDocument` as an object and keeps its existing properties.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use OCA\Integriq\Repair\InitializeRegister;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The openApiDocument fragment, merged.
 *
 * @coversNothing
 */
class GatewayOpenApiImportFragmentTest extends TestCase {

	private const SETTINGS = __DIR__.'/../../../lib/Settings';

	/**
	 * The register with every fragment merged, in the repair step's order.
	 *
	 * @return array<string,mixed> The merged register.
	 */
	private static function merged(): array {
		$register = json_decode((string) file_get_contents(self::SETTINGS.'/integriq_register.json'), true);
		$files = glob(self::SETTINGS.'/register.d/*.json');
		sort($files);
		$merge = new ReflectionMethod(InitializeRegister::class, 'deepMergeConfig');
		foreach ($files as $file) {
			$register = $merge->invoke(null, $register, json_decode((string) file_get_contents($file), true));
		}

		return $register;
	}//end merged()

	/**
	 * Source gains openApiDocument as an open object; location, configuration and the write-only secrets remain.
	 *
	 * @return void
	 */
	public function testASourceKeepsItsImportedDocument(): void {
		$properties = self::merged()['components']['schemas']['source']['properties'];

		$this->assertSame('object', $properties['openApiDocument']['type']);
		$this->assertTrue($properties['openApiDocument']['additionalProperties']);
		$this->assertArrayHasKey('location', $properties);
		$this->assertArrayHasKey('configuration', $properties);
		$this->assertTrue($properties['password']['writeOnly']);
	}//end testASourceKeepsItsImportedDocument()

	/**
	 * Endpoint gains an optional upstreamPath string; its own path and routing fields remain.
	 *
	 * @return void
	 */
	public function testAnEndpointCanNameItsUpstreamPath(): void {
		$properties = self::merged()['components']['schemas']['endpoint']['properties'];

		$this->assertSame('string', $properties['upstreamPath']['type']);
		$this->assertArrayHasKey('endpoint', $properties);
		$this->assertArrayHasKey('endpointArray', $properties);
		$this->assertArrayHasKey('endpointRegex', $properties);
	}//end testAnEndpointCanNameItsUpstreamPath()
}//end class

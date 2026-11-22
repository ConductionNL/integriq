<?php

/**
 * Unit tests for UpstreamPathTemplate (gateway-openapi-import-and-publish, REQ-OAPI-001).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Endpoint;

use OCA\Integriq\Service\Endpoint\UpstreamPathTemplate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Which path an `api` endpoint proxies to.
 */
#[CoversClass(UpstreamPathTemplate::class)]
class UpstreamPathTemplateTest extends TestCase {

	public function testAnEndpointWithoutAnUpstreamPathProxiesToItsOwnPath(): void {
		$this->assertSame('/repos/{{owner}}/labels', (new UpstreamPathTemplate())->forEndpoint(endpointData: ['endpoint' => '/repos/{{owner}}/labels']));
	}

	public function testAnImportedEndpointProxiesToTheVendorPathNotItsGatewayRoute(): void {
		$data = ['endpoint' => 'vendor/pets/{{petId}}', 'upstreamPath' => 'pets/{{petId}}'];

		$this->assertSame('pets/{{petId}}', (new UpstreamPathTemplate())->forEndpoint(endpointData: $data));
	}

	public function testAnEmptyOrNonStringUpstreamPathFallsBackToTheEndpoint(): void {
		$this->assertSame('zaken', (new UpstreamPathTemplate())->forEndpoint(endpointData: ['endpoint' => 'zaken', 'upstreamPath' => '  ']));
		$this->assertSame('zaken', (new UpstreamPathTemplate())->forEndpoint(endpointData: ['endpoint' => 'zaken', 'upstreamPath' => ['x']]));
		$this->assertSame('zaken', (new UpstreamPathTemplate())->forEndpoint(endpointData: ['endpoint' => 'zaken', 'upstreamPath' => null]));
	}

	public function testAnEndpointWithNoPathAtAllYieldsAnEmptyTemplate(): void {
		$this->assertSame('', (new UpstreamPathTemplate())->forEndpoint(endpointData: []));
	}
}

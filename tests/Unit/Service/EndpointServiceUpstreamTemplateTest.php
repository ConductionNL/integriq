<?php

/**
 * Unit tests for EndpointService::upstreamTemplate() (gateway-openapi-import-and-publish, REQ-OAPI-001).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\EndpointService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Which path an `api` endpoint proxies to.
 */
#[CoversClass(EndpointService::class)]
class EndpointServiceUpstreamTemplateTest extends TestCase {

	public function testAnEndpointWithoutAnUpstreamPathProxiesToItsOwnPath(): void {
		$this->assertSame('/repos/{{owner}}/labels', EndpointService::upstreamTemplate(endpointData: ['endpoint' => '/repos/{{owner}}/labels']));
	}

	public function testAnImportedEndpointProxiesToTheVendorPathNotItsGatewayRoute(): void {
		$data = ['endpoint' => 'vendor/pets/{{petId}}', 'upstreamPath' => 'pets/{{petId}}'];

		$this->assertSame('pets/{{petId}}', EndpointService::upstreamTemplate(endpointData: $data));
	}

	public function testAnEmptyOrNonStringUpstreamPathFallsBackToTheEndpoint(): void {
		$this->assertSame('zaken', EndpointService::upstreamTemplate(endpointData: ['endpoint' => 'zaken', 'upstreamPath' => '  ']));
		$this->assertSame('zaken', EndpointService::upstreamTemplate(endpointData: ['endpoint' => 'zaken', 'upstreamPath' => ['x']]));
		$this->assertSame('zaken', EndpointService::upstreamTemplate(endpointData: ['endpoint' => 'zaken', 'upstreamPath' => null]));
	}

	public function testAnEndpointWithNoPathAtAllYieldsAnEmptyTemplate(): void {
		$this->assertSame('', EndpointService::upstreamTemplate(endpointData: []));
	}
}

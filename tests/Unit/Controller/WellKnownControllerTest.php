<?php

/**
 * WellKnownController publishes RFC 9728 protected resource metadata.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-a-protected-endpoint-publishes-where-to-get-a-token-req-tokv-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\WellKnownController;
use OCA\Integriq\Service\OAuth\ProtectedResourceMetadata;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The metadata document of a token-protected endpoint, or a 404.
 *
 * @covers \OCA\Integriq\Controller\WellKnownController
 */
final class WellKnownControllerTest extends TestCase {

	/**
	 * The metadata builder.
	 *
	 * @var ProtectedResourceMetadata&MockObject
	 */
	private ProtectedResourceMetadata&MockObject $metadata;

	private WellKnownController $controller;

	/**
	 * A controller over a mocked metadata builder.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->metadata   = $this->createMock(ProtectedResourceMetadata::class);
		$this->controller = new WellKnownController(appName: 'integriq', request: $this->createMock(IRequest::class), metadata: $this->metadata);
	}//end setUp()

	/**
	 * A protected endpoint's document is returned as is, for the path asked.
	 *
	 * @return void
	 */
	public function testAProtectedEndpointGetsItsDocument(): void {
		$document = ['resource' => 'https://cloud.example.nl/apps/integriq/api/endpoints/zaken', 'scopes_supported' => ['zaken:read']];
		$this->metadata->expects($this->once())->method('forPath')->with('api/endpoints/zaken')->willReturn($document);

		$response = $this->controller->protectedResource(_path: 'api/endpoints/zaken');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame($document, $response->getData());
	}//end testAProtectedEndpointGetsItsDocument()

	/**
	 * A path without a token-protected endpoint is a 404.
	 *
	 * @return void
	 */
	public function testAnUnprotectedPathIsA404(): void {
		$this->metadata->method('forPath')->willReturn(null);

		$response = $this->controller->protectedResource(_path: 'api/endpoints/open');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'not_found'], $response->getData());
	}//end testAnUnprotectedPathIsA404()

	/**
	 * The document is public and rate limited for anonymous callers.
	 *
	 * @return void
	 */
	public function testThePublicDocumentIsRateLimited(): void {
		$reflection = new ReflectionMethod(WellKnownController::class, 'protectedResource');

		$this->assertCount(1, $reflection->getAttributes(PublicPage::class));
		$this->assertCount(1, $reflection->getAttributes(AnonRateLimit::class));
	}//end testThePublicDocumentIsRateLimited()
}//end class

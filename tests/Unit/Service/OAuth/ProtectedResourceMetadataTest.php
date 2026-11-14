<?php

/**
 * A token-protected endpoint publishes where to get a token, and its 401 points there.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\OAuth
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

namespace OCA\Integriq\Tests\Unit\Service\OAuth;

use OCA\Integriq\Controller\WellKnownController;
use OCA\Integriq\Exception\AuthenticationException;
use OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridge;
use OCA\Integriq\Service\EndpointService;
use OCA\Integriq\Service\OAuth\IssuerKeyService;
use OCA\Integriq\Service\OAuth\ProtectedResourceMetadata;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * RFC 9728 metadata and the 401 challenge.
 */
#[CoversClass(ProtectedResourceMetadata::class)]
#[CoversClass(WellKnownController::class)]
#[CoversClass(EndpointService::class)]
final class ProtectedResourceMetadataTest extends TestCase {

	private ProtectedResourceMetadata $metadata;

	/**
	 * Endpoint "zaken" behind a JWT rule requiring zaken:read on GET and zaken:write on POST; "open" behind an API key.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$endpoints = [
			['endpoint' => 'zaken', 'rules' => ['r-jwt', 'r-map']],
			['endpoint' => 'open', 'rules' => ['r-key']],
		];
		$rules = [
			'r-jwt' => ['type' => 'authentication', 'configuration' => ['authentication' => ['type' => 'jwt', 'requiredScopes' => ['GET' => ['zaken:read'], 'POST' => ['zaken:write']], 'keys' => ['secret' => 'u']]]],
			'r-map' => ['type' => 'mapping', 'configuration' => []],
			'r-key' => ['type' => 'authentication', 'configuration' => ['authentication' => ['type' => 'apikey', 'keys' => ['k' => 'u']]]],
		];
		$objects = $this->createMock(ObjectService::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('findAll')->willReturnCallback(static function (array $config) use ($endpoints): array {
			$found = [];
			foreach ($endpoints as $endpoint) {
				if ($endpoint['endpoint'] === ($config['filters']['endpoint'] ?? null)) {
					$entity = new ObjectEntity();
					$entity->setObject($endpoint);
					$found[] = $entity;
				}
			}

			return $found;
		});
		$objects->method('find')->willReturnCallback(static function (string|int $id) use ($rules): ?ObjectEntity {
			if (isset($rules[$id]) === false) {
				return null;
			}

			$entity = new ObjectEntity();
			$entity->setObject($rules[$id]);
			return $entity;
		});
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://cloud.example.nl' . $path);
		$issuer = $this->createMock(IssuerKeyService::class);
		$issuer->method('issuerUrl')->willReturn('https://cloud.example.nl/apps/integriq');

		$this->metadata = new ProtectedResourceMetadata(objectService: $objects, urlGenerator: $urls, issuerKey: $issuer);
	}//end setUp()

	/**
	 * A token-protected endpoint's document names the resource, the issuer and its scopes; an open one has none.
	 *
	 * @return void
	 */
	public function testTheDocumentOfATokenProtectedEndpoint(): void {
		$this->assertSame(
			[
				'resource'                 => 'https://cloud.example.nl/apps/integriq/api/endpoint/zaken',
				'authorization_servers'    => ['https://cloud.example.nl/apps/integriq'],
				'scopes_supported'         => ['zaken:read', 'zaken:write'],
				'bearer_methods_supported' => ['header'],
			],
			$this->metadata->forPath(endpointPath: '/zaken/')
		);
		$this->assertNull($this->metadata->forPath(endpointPath: 'open'));
		$this->assertNull($this->metadata->forPath(endpointPath: 'gone'));
		$this->assertNull($this->metadata->forPath(endpointPath: ''));

		$controller = new WellKnownController(appName: 'integriq', request: $this->createMock(IRequest::class), metadata: $this->metadata);
		$this->assertSame(200, $controller->protectedResource(_path: 'zaken')->getStatus());
		$this->assertSame(404, $controller->protectedResource(_path: 'open')->getStatus());
	}//end testTheDocumentOfATokenProtectedEndpoint()

	/**
	 * A call without a token to a JWT endpoint gets 401 naming the metadata; a refused token too; an API-key endpoint keeps its 403.
	 *
	 * @return void
	 */
	public function testThe401PointsToTheDocument(): void {
		$bridge = $this->createMock(OpenRegisterCredentialBridge::class);
		$bridge->method('authorizeJwt')->willThrowException(new AuthenticationException(message: 'The token could not be validated', details: []));
		$reflection = new ReflectionClass(EndpointService::class);
		$service = $reflection->newInstanceWithoutConstructor();
		$reflection->getProperty('authorizationService')->setValue($service, $bridge);
		$reflection->getProperty('resourceMetadata')->setValue($service, $this->metadata);
		$process = static function (array $authentication, string $header) use ($reflection, $service): array|JSONResponse {
			$rule = new ObjectEntity();
			$rule->setObject(['configuration' => ['authentication' => $authentication]]);
			return $reflection->getMethod('processAuthenticationRule')->invoke($service, $rule, ['headers' => ['Authorization' => $header], 'method' => 'GET'], 'zaken');
		};
		$headers = static fn (Response $response): array => (new ReflectionProperty(Response::class, 'headers'))->getValue($response);
		$expected = 'Bearer resource_metadata="https://cloud.example.nl/apps/integriq/.well-known/oauth-protected-resource/zaken"';

		$missing = $process(['type' => 'jwt'], '');
		$this->assertSame(401, $missing->getStatus());
		$this->assertSame($expected, $headers($missing)['WWW-Authenticate']);

		$refused = $process(['type' => 'jwt'], 'Bearer bad');
		$this->assertSame(401, $refused->getStatus());
		$this->assertSame($expected, $headers($refused)['WWW-Authenticate']);

		$apiKey = $process(['type' => 'apikey', 'keys' => []], '');
		$this->assertSame(403, $apiKey->getStatus());
		$this->assertArrayNotHasKey('WWW-Authenticate', $headers($apiKey));
	}//end testThe401PointsToTheDocument()
}//end class

<?php

/**
 * Unit tests for GatewayOpenApiController: the gateway document is public, a
 * public product's document is readable by anyone, a private product's only
 * by an administrator or a consumer with an active subscription.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-integriq-publishes-an-openapi-description-of-its-endpoints-req-oapi-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\GatewayOpenApiController;
use OCA\Integriq\Service\AsyncApiDocumentService;
use OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridge;
use OCA\Integriq\Service\EndpointTargetResolver;
use OCA\Integriq\Service\OpenApi\OpenApiDocumentNormaliser;
use OCA\Integriq\Service\OpenApi\OpenApiPublishService;
use OCA\Integriq\Service\ProductPolicyService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCA\Integriq\Service\EndpointService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Tests for the published OpenAPI routes.
 */
#[CoversClass(GatewayOpenApiController::class)]
#[UsesClass(OpenApiPublishService::class)]
#[UsesClass(OpenApiDocumentNormaliser::class)]
#[UsesClass(EndpointTargetResolver::class)]
#[UsesClass(EndpointService::class)]
#[UsesClass(AsyncApiDocumentService::class)]
#[UsesClass(ProductPolicyService::class)]
class GatewayOpenApiControllerTest extends TestCase {

	private OrObjectService&MockObject $objects;

	private OpenRegisterCredentialBridge&MockObject $credentials;

	/**
	 * The rows OpenRegister holds, by schema.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	private array $rows = [];

	/**
	 * The active subscriptions: "consumer|product" keys.
	 *
	 * @var array<int,string>
	 */
	private array $subscriptions = [];

	/**
	 * Build the collaborators and rows.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->subscriptions = [];
		$this->rows = [
			'endpoint' => [['uuid' => 'ep-1', 'name' => 'Ping', 'endpoint' => 'ping', 'endpointArray' => ['ping'], 'method' => 'GET', 'targetType' => 'api', 'targetId' => 'src-1']],
			'source' => [['uuid' => 'src-1', 'location' => 'https://upstream.example.org/api']],
			'api_product' => [
				['uuid' => 'pub-1', 'productSlug' => 'public-api', 'name' => 'Public', 'version' => '1.0.0', 'visibility' => 'public', 'status' => 'active', 'endpoints' => ['ep-1']],
				['uuid' => 'priv-1', 'productSlug' => 'private-api', 'name' => 'Private', 'version' => '1.0.0', 'visibility' => 'private', 'status' => 'active', 'endpoints' => ['ep-1']],
			],
		];
		$this->objects = $this->createMock(OrObjectService::class);
		$this->objects->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				$filters = $config['filters'];
				if ($filters['schema'] === 'api_product_subscription') {
					$hit = in_array($filters['consumer'].'|'.$filters['product'], $this->subscriptions, true);

					return ['results' => ($hit === true ? [new ObjectEntity()] : [])];
				}

				$rows = [];
				foreach (($this->rows[$filters['schema']] ?? []) as $row) {
					if (isset($filters['productSlug']) === true && ($row['productSlug'] ?? null) !== $filters['productSlug']) {
						continue;
					}

					$rows[] = self::entity(row: $row);
				}

				return ['results' => $rows];
			}
		);
		$this->objects->method('find')->willReturnCallback(
			function (mixed $id, mixed $register = null, mixed $schema = null): ObjectEntity {
				foreach (($this->rows[$schema] ?? []) as $row) {
					if ($row['uuid'] === $id) {
						return self::entity(row: $row);
					}
				}

				throw new RuntimeException('Not found');
			}
		);
		$this->credentials = $this->createMock(OpenRegisterCredentialBridge::class);
	}//end setUp()

	/**
	 * An entity holding a row.
	 *
	 * @param array<string,mixed> $row The row.
	 *
	 * @return ObjectEntity The entity.
	 */
	private static function entity(array $row): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid((string) $row['uuid']);
		$entity->setObject($row);

		return $entity;
	}//end entity()

	/**
	 * The controller for a session user and an Authorization header.
	 *
	 * @param string|null $uid           The session user, or null for none.
	 * @param bool        $admin         Whether that user is an administrator.
	 * @param string      $authorization The Authorization header.
	 *
	 * @return GatewayOpenApiController The controller.
	 */
	private function controller(?string $uid = null, bool $admin = false, string $authorization = ''): GatewayOpenApiController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(static fn (string $name): string => ($name === 'Authorization' ? $authorization : ''));
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $candidate): bool => $admin === true && $candidate === $uid);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->willReturn('2.4.0');
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://gemeente.example'.$path);
		$logger = $this->createMock(LoggerInterface::class);

		$publisher = new OpenApiPublishService(
			objects: $this->objects,
			normaliser: new OpenApiDocumentNormaliser(),
			targets: new EndpointTargetResolver(registerMapper: $this->createMock(RegisterMapper::class), schemaMapper: $this->createMock(SchemaMapper::class)),
			schemaMapper: $this->createMock(SchemaMapper::class),
			logger: $logger
		);

		return new GatewayOpenApiController(
			appName: 'integriq',
			request: $request,
			publisher: $publisher,
			objects: $this->objects,
			versions: new AsyncApiDocumentService(),
			policy: new ProductPolicyService(orObjectService: $this->objects, logger: $logger),
			credentials: $this->credentials,
			userSession: $session,
			groupManager: $groups,
			appManager: $apps,
			urlGenerator: $urls,
			logger: $logger
		);
	}//end controller()

	/**
	 * Both documents are public routes; neither is an admin route.
	 *
	 * @return void
	 */
	public function testBothDocumentsArePublicRoutes(): void {
		foreach (['gateway', 'product'] as $method) {
			$reflection = new ReflectionMethod(GatewayOpenApiController::class, $method);
			$this->assertCount(1, $reflection->getAttributes(PublicPage::class));
			$this->assertCount(0, $reflection->getAttributes(AuthorizedAdminSetting::class));
			$this->assertCount(0, $reflection->getAttributes(NoAdminRequired::class));
		}
	}//end testBothDocumentsArePublicRoutes()

	/**
	 * The gateway document is served to anyone, versioned with the app, readable from any origin, without the upstream address.
	 *
	 * @return void
	 */
	public function testTheGatewayDocumentIsServedToAnyone(): void {
		$response = $this->controller()->gateway();
		$data = $response->getData();

		$this->assertSame(200, $response->getStatus());
		// Response::getHeaders() reads the server container; the headers set on the response are read directly.
		$headers = (new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers'))->getValue($response);
		$this->assertSame('*', $headers['Access-Control-Allow-Origin']);
		$this->assertSame('3.1.0', $data['openapi']);
		$this->assertSame('2.4.0', $data['info']['version']);
		$this->assertSame('https://gemeente.example/index.php/apps/integriq/api/endpoint', $data['servers'][0]['url']);
		$this->assertSame(['/ping'], array_keys($data['paths']));
		$this->assertStringNotContainsString('upstream.example.org', (string) json_encode($data));
	}//end testTheGatewayDocumentIsServedToAnyone()

	/**
	 * A public product is found by uuid or by slug and served to anyone; an unknown one is 404.
	 *
	 * @return void
	 */
	public function testAPublicProductIsServedByUuidOrSlug(): void {
		$byUuid = $this->controller()->product(id: 'pub-1');
		$bySlug = $this->controller()->product(id: 'public-api');

		$this->assertSame(200, $byUuid->getStatus());
		$this->assertSame('Public', $byUuid->getData()['info']['title']);
		$this->assertSame($byUuid->getData(), $bySlug->getData());
		$this->assertSame(404, $this->controller()->product(id: 'nothing')->getStatus());
	}//end testAPublicProductIsServedByUuidOrSlug()

	/**
	 * A private product is refused to an anonymous caller and a non-admin user, and served to an administrator.
	 *
	 * @return void
	 */
	public function testAPrivateProductIsRefusedToANonAdmin(): void {
		$anonymous = $this->controller()->product(id: 'priv-1');
		$this->assertSame(403, $anonymous->getStatus());
		$this->assertSame('subscription_required', $anonymous->getData()['error']);

		$this->assertSame(403, $this->controller(uid: 'alice', admin: false)->product(id: 'priv-1')->getStatus());

		$admin = $this->controller(uid: 'root', admin: true)->product(id: 'priv-1');
		$this->assertSame(200, $admin->getStatus());
		$this->assertSame('Private', $admin->getData()['info']['title']);
	}//end testAPrivateProductIsRefusedToANonAdmin()

	/**
	 * A consumer with an active subscription reads a private product; without one it is refused.
	 *
	 * @return void
	 */
	public function testASubscribedConsumerReadsAPrivateProduct(): void {
		$consumer = new ObjectEntity();
		$consumer->setUuid('consumer-1');
		$this->credentials->method('getResolvedConsumer')->willReturn($consumer);

		$this->assertSame(403, $this->controller(authorization: 'Bearer a.b.c')->product(id: 'priv-1')->getStatus());

		$this->subscriptions = ['consumer-1|priv-1'];
		$this->assertSame(200, $this->controller(authorization: 'Bearer a.b.c')->product(id: 'priv-1')->getStatus());
	}//end testASubscribedConsumerReadsAPrivateProduct()
}//end class

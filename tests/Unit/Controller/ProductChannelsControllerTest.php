<?php

/**
 * Unit tests for ProductChannelsController::asyncapi() — a public product's
 * document is readable by anyone; a private product's needs a consumer with an
 * active subscription.
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
 * @spec openspec/changes/events-async-api-products/specs/api-product-gateway/spec.md#requirement-the-product-publishes-an-asyncapi-document-of-its-channels-req-aapi-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\ProductChannelsController;
use OCA\Integriq\Exception\AuthenticationException;
use OCA\Integriq\Service\AsyncApiDocumentService;
use OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridge;
use OCA\Integriq\Service\ProductPolicyService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the AsyncAPI route.
 */
#[CoversClass(ProductChannelsController::class)]
#[UsesClass(AsyncApiDocumentService::class)]
#[UsesClass(ProductPolicyService::class)]
#[UsesClass(AuthenticationException::class)]
class ProductChannelsControllerTest extends TestCase {

	private OrObjectService&MockObject $objects;

	private OpenRegisterCredentialBridge&MockObject $credentials;

	/**
	 * The active subscriptions: "consumer|product" keys.
	 *
	 * @var array<int,string>
	 */
	private array $subscriptions = [];

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(OrObjectService::class);
		$this->credentials = $this->createMock(OpenRegisterCredentialBridge::class);
		$this->subscriptions = [];
	}//end setUp()

	/**
	 * The product rows the register holds, and the subscription lookups.
	 *
	 * @param array<int,array<string,mixed>> $rows The api_product rows.
	 *
	 * @return void
	 */
	private function holds(array $rows): void {
		$this->objects->method('findAll')->willReturnCallback(
			function (array $config = []) use ($rows): array {
				$filters = $config['filters'];
				if ($filters['schema'] === 'api_product_subscription') {
					$hit = in_array($filters['consumer'] . '|' . $filters['product'], $this->subscriptions, true);

					return ['results' => ($hit === true ? [new ObjectEntity()] : [])];
				}

				$entities = [];
				foreach ($rows as $row) {
					$entity = new ObjectEntity();
					$entity->setUuid($row['uuid']);
					$entity->setObject($row);
					$entities[] = $entity;
				}

				return ['results' => $entities];
			}
		);
	}//end holds()

	/**
	 * The controller for a request with the given Authorization header.
	 *
	 * @param string $authorization The header.
	 *
	 * @return ProductChannelsController The controller.
	 */
	private function controller(string $authorization = ''): ProductChannelsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(static fn (string $name): string => ($name === 'Authorization' ? $authorization : ''));
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://gemeente.example' . $path);
		$logger = $this->createMock(LoggerInterface::class);

		return new ProductChannelsController(
			appName: 'integriq',
			request: $request,
			objects: $this->objects,
			documents: new AsyncApiDocumentService(),
			policy: new ProductPolicyService(orObjectService: $this->objects, logger: $logger),
			credentials: $this->credentials,
			urlGenerator: $urls,
			logger: $logger
		);
	}//end controller()

	/**
	 * A public product's document is served without a session, from its latest active version.
	 *
	 * @return void
	 */
	public function testAPublicProductsDocumentIsServedToAnyone(): void {
		$this->holds(
			[
				['uuid' => 'v1', 'productSlug' => 'woo-publications', 'name' => 'WOO', 'version' => '1.0.0', 'status' => 'deprecated', 'visibility' => 'public'],
				['uuid' => 'v2', 'productSlug' => 'woo-publications', 'name' => 'WOO', 'version' => '2.0.0', 'status' => 'active', 'visibility' => 'public', 'channels' => [['slug' => 'publications', 'title' => 'Publications', 'types' => ['nl.woo.publication.created']]]],
				['uuid' => 'other', 'productSlug' => 'kvk-lookup', 'version' => '9.0.0', 'status' => 'active'],
			]
		);
		$this->credentials->expects($this->never())->method('authorizeJwt');

		$response = $this->controller()->asyncapi(productSlug: 'woo-publications');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('2.0.0', $response->getData()['info']['version']);
		$this->assertSame(['publications'], array_keys($response->getData()['channels']));
		$this->assertSame('/index.php/apps/integriq/api/products/woo-publications', $response->getData()['servers']['integriq']['pathname']);
	}//end testAPublicProductsDocumentIsServedToAnyone()

	/**
	 * An unknown product is 404.
	 *
	 * @return void
	 */
	public function testAnUnknownProductIsNotFound(): void {
		$this->holds([]);

		$this->assertSame(404, $this->controller()->asyncapi(productSlug: 'nothing')->getStatus());
	}//end testAnUnknownProductIsNotFound()

	/**
	 * A private product without a credential, or with a consumer that holds no subscription, is 403 subscription_required.
	 *
	 * @return void
	 */
	public function testAPrivateProductNeedsASubscribedConsumer(): void {
		$this->holds([['uuid' => 'kvk-1', 'productSlug' => 'kvk-lookup', 'version' => '1.0.0', 'visibility' => 'private']]);
		$consumer = new ObjectEntity();
		$consumer->setUuid('consumer-1');
		$this->credentials->method('getResolvedConsumer')->willReturn($consumer);

		$anonymous = $this->controller()->asyncapi(productSlug: 'kvk-lookup');
		$this->assertSame(403, $anonymous->getStatus());
		$this->assertSame('subscription_required', $anonymous->getData()['error']);

		$this->assertSame(403, $this->controller(authorization: 'Bearer a.b.c')->asyncapi(productSlug: 'kvk-lookup')->getStatus());

		$this->subscriptions = ['consumer-1|kvk-1'];
		$this->assertSame(200, $this->controller(authorization: 'Bearer a.b.c')->asyncapi(productSlug: 'kvk-lookup')->getStatus());
	}//end testAPrivateProductNeedsASubscribedConsumer()

	/**
	 * A token that does not authenticate is 401.
	 *
	 * @return void
	 */
	public function testATokenThatDoesNotAuthenticateIs401(): void {
		$this->holds([['uuid' => 'kvk-1', 'productSlug' => 'kvk-lookup', 'version' => '1.0.0', 'visibility' => 'private']]);
		$this->credentials->method('authorizeJwt')->willThrowException(new AuthenticationException('The token could not be validated', []));

		$response = $this->controller(authorization: 'Bearer a.b.c')->asyncapi(productSlug: 'kvk-lookup');

		$this->assertSame(401, $response->getStatus());
		$this->assertSame('The token could not be validated', $response->getData()['message']);
	}//end testATokenThatDoesNotAuthenticateIs401()
}//end class

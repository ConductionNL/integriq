<?php

/**
 * Unit tests for the pull, subscribe and unsubscribe routes of ProductChannelsController.
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
 * @spec openspec/changes/events-async-api-products/specs/api-product-gateway/spec.md#requirement-a-consumer-reaches-a-channel-under-the-products-policies-req-aapi-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\ProductChannelsController;
use OCA\Integriq\Service\AsyncApiDocumentService;
use OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridge;
use OCA\Integriq\Service\EventServiceInterface;
use OCA\Integriq\Service\ProductPolicyService;
use OCA\Integriq\Service\RateLimit\InboundRateLimitService;
use OCA\Integriq\Service\RateLimit\RateLimitDecision;
use OCA\Integriq\Service\Security\EgressGuard;
use OCA\Integriq\Service\Subscriptions\SubscriptionSigningPolicyInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A channel is reached with the product's key, subscription and tier budget.
 */
class ProductChannelsControllerChannelTest extends TestCase {

	/**
	 * Stored event subscriptions by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $eventSubscriptions = [];

	/**
	 * Whether the consumer holds an active product subscription.
	 *
	 * @var bool
	 */
	private bool $subscribed = true;

	/**
	 * Whether the tier budget allows the request.
	 *
	 * @var bool
	 */
	private bool $allowed = true;

	/**
	 * The keys the budget was spent under.
	 *
	 * @var array<int, string>
	 */
	private array $spent = [];

	/**
	 * Deleted uuids.
	 *
	 * @var array<int, string>
	 */
	private array $deleted = [];

	/**
	 * The product status.
	 *
	 * @var string
	 */
	private string $status = 'active';

	/**
	 * Reset.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->eventSubscriptions = [];
		$this->subscribed = true;
		$this->allowed = true;
		$this->spent = [];
		$this->deleted = [];
		$this->status = 'active';
	}//end setUp()

	/**
	 * Without a product subscription a pull is 403 and nothing is spent or opened.
	 *
	 * @return void
	 */
	public function testNoSubscriptionNoEvents(): void {
		$this->subscribed = false;

		$response = $this->controller()->pull(productSlug: 'woo-publications', channel: 'publications');

		$this->assertSame([403, 'subscription_required'], [$response->getStatus(), $response->getData()['error']]);
		$this->assertSame([[], []], [$this->spent, $this->eventSubscriptions]);
	}//end testNoSubscriptionNoEvents()

	/**
	 * An unknown channel is 404 before anyone is authenticated.
	 *
	 * @return void
	 */
	public function testAnUnknownChannelIsNotFound(): void {
		$this->assertSame(404, $this->controller()->pull(productSlug: 'woo-publications', channel: 'nothing')->getStatus());
		$this->assertSame(404, $this->controller()->pull(productSlug: 'nothing', channel: 'publications')->getStatus());
	}//end testAnUnknownChannelIsNotFound()

	/**
	 * The first pull opens one pull subscription on the channel's types; the next reuses it.
	 *
	 * @return void
	 */
	public function testAPullOpensOnePullSubscriptionAndReadsItsEvents(): void {
		$first = $this->controller()->pull(productSlug: 'woo-publications', channel: 'publications');
		$second = $this->controller()->pull(productSlug: 'woo-publications', channel: 'publications', cursor: 'm-1');

		$this->assertSame(200, $first->getStatus());
		$this->assertSame(['m-1'], array_column($first->getData()['messages'], 'id'));
		$this->assertSame('m-1', $first->getData()['cursor']);
		$this->assertCount(1, $this->eventSubscriptions);
		$stored = array_values($this->eventSubscriptions)[0];
		$this->assertEquals(
			['style' => 'pull', 'types' => ['nl.woo.publication.created', 'nl.woo.publication.updated'], 'productSubscription' => 'ps-1', 'productChannel' => 'publications'],
			array_intersect_key($stored, array_flip(['style', 'types', 'productSubscription', 'productChannel']))
		);
		$this->assertSame(200, $second->getStatus());
		$this->assertSame(['product:v2:consumer:consumer-1', 'product:v2:consumer:consumer-1'], $this->spent);
	}//end testAPullOpensOnePullSubscriptionAndReadsItsEvents()

	/**
	 * A spent tier budget answers 429 with Retry-After.
	 *
	 * @return void
	 */
	public function testASpentBudgetIs429(): void {
		$this->allowed = false;

		$response = $this->controller()->pull(productSlug: 'woo-publications', channel: 'publications');

		$this->assertSame(429, $response->getStatus());
		$this->assertSame('30', (string)$this->headersOf(response: $response)['Retry-After']);
	}//end testASpentBudgetIs429()

	/**
	 * A deprecated product version adds Deprecation and Sunset to a pull.
	 *
	 * @return void
	 */
	public function testADeprecatedVersionAddsTheHeaders(): void {
		$this->status = 'deprecated';

		$headers = $this->headersOf(response: $this->controller()->pull(productSlug: 'woo-publications', channel: 'publications'));

		$this->assertSame(['true', 'Thu, 31 Dec 2026 00:00:00 GMT'], [$headers['Deprecation'], $headers['Sunset']]);
	}//end testADeprecatedVersionAddsTheHeaders()

	/**
	 * An internal or plain-http sink is refused with 400 and nothing is created.
	 *
	 * @return void
	 */
	public function testAnInternalSinkIsRefused(): void {
		foreach (['http://169.254.169.254/latest', 'https://169.254.169.254/latest', 'https://127.0.0.1/hook', 'not a url', ''] as $sink) {
			$response = $this->controller(sink: $sink)->subscribe(productSlug: 'woo-publications', channel: 'publications');
			$this->assertSame([400, 'invalid_sink'], [$response->getStatus(), $response->getData()['error']], $sink);
		}

		$this->assertSame([], $this->eventSubscriptions);
	}//end testAnInternalSinkIsRefused()

	/**
	 * A push subscription filters on the channel, names the product subscription and reveals its secret once.
	 *
	 * @return void
	 */
	public function testAPushSubscriptionRidesOnTheProductSubscription(): void {
		$response = $this->controller(sink: 'https://93.184.216.34/hook')->subscribe(productSlug: 'woo-publications', channel: 'publications');

		$this->assertSame(201, $response->getStatus());
		$this->assertSame('whsec_test', $response->getData()['signingSecret']);
		$stored = array_values($this->eventSubscriptions)[0];
		$this->assertEquals(
			['style' => 'push', 'sink' => 'https://93.184.216.34/hook', 'productSubscription' => 'ps-1', 'productChannel' => 'publications'],
			array_intersect_key($stored, array_flip(['style', 'sink', 'productSubscription', 'productChannel']))
		);
	}//end testAPushSubscriptionRidesOnTheProductSubscription()

	/**
	 * Only the consumer's own push subscription on the channel can be ended.
	 *
	 * @return void
	 */
	public function testOnlyAnOwnSubscriptionCanBeEnded(): void {
		$this->eventSubscriptions = [
			'own' => ['style' => 'push', 'productSubscription' => 'ps-1', 'productChannel' => 'publications'],
			'other' => ['style' => 'push', 'productSubscription' => 'ps-9', 'productChannel' => 'publications'],
		];

		$this->assertSame(404, $this->controller()->unsubscribe(productSlug: 'woo-publications', channel: 'publications', id: 'other')->getStatus());
		$this->assertSame(200, $this->controller()->unsubscribe(productSlug: 'woo-publications', channel: 'publications', id: 'own')->getStatus());
		$this->assertSame(['own'], $this->deleted);
	}//end testOnlyAnOwnSubscriptionCanBeEnded()

	/**
	 * The headers a response was built with, read without a running server.
	 *
	 * @param \OCP\AppFramework\Http\JSONResponse $response The response.
	 *
	 * @return array<string, mixed> The headers.
	 */
	private function headersOf(\OCP\AppFramework\Http\JSONResponse $response): array {
		$property = new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers');
		return (array)$property->getValue($response);
	}//end headersOf()

	/**
	 * The controller over in-memory rows.
	 *
	 * @param string $sink The sink request parameter.
	 *
	 * @return ProductChannelsController The controller.
	 */
	private function controller(string $sink = ''): ProductChannelsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(static fn (string $name): string => ($name === 'Authorization' ? 'Bearer a.b.c' : ''));
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => ($key === 'sink' ? $sink : $default));

		$credentials = $this->createMock(OpenRegisterCredentialBridge::class);
		$consumer = new ObjectEntity();
		$consumer->setUuid('consumer-1');
		$credentials->method('getResolvedConsumer')->willReturn($consumer);

		$limits = $this->createMock(InboundRateLimitService::class);
		$limits->method('enforce')->willReturnCallback(
			function (string $consumerKey): RateLimitDecision {
				$this->spent[] = $consumerKey;
				return new RateLimitDecision(allowed: $this->allowed, retryAfter: ($this->allowed === true ? null : 30), reason: 'rate_limit');
			}
		);

		$events = $this->createMock(EventServiceInterface::class);
		$events->method('pullEvents')->willReturnCallback(
			static function (ObjectEntity $subscription, ?int $limit = 100, ?string $cursor = null): array {
				$message = new ObjectEntity();
				$message->setUuid('m-1');
				$message->setObject(['id' => 'm-1']);
				return ['messages' => [$message], 'cursor' => 'm-1'];
			}
		);

		$signing = $this->createMock(SubscriptionSigningPolicyInterface::class);
		$signing->method('reveal')->willReturn(['signingSecret' => 'whsec_test']);

		$objects = $this->objects();
		return new ProductChannelsController(
			appName: 'integriq',
			request: $request,
			objects: $objects,
			documents: new AsyncApiDocumentService(),
			policy: new ProductPolicyService(orObjectService: $objects, logger: new NullLogger()),
			credentials: $credentials,
			urlGenerator: $this->createMock(IURLGenerator::class),
			logger: new NullLogger(),
			rateLimits: $limits,
			events: $events,
			egressGuard: new EgressGuard(),
			signingPolicy: $signing,
		);
	}//end controller()

	/**
	 * An object service over the product, its subscription and the event subscriptions.
	 *
	 * @return OrObjectService The mock.
	 */
	private function objects(): OrObjectService {
		$entity = static function (string $uuid, array $data): ObjectEntity {
			$row = new ObjectEntity();
			$row->setUuid($uuid);
			$row->setObject($data);
			return $row;
		};
		$objects = $this->createMock(OrObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = []) use ($entity): array {
				$filters = $config['filters'];
				if ($filters['schema'] === 'api_product') {
					return ['results' => [$entity('v2', [
						'productSlug' => 'woo-publications', 'version' => '2.0.0', 'status' => $this->status, 'sunsetDate' => '2026-12-31T00:00:00Z',
						'tiers' => ['free' => ['rateLimit' => ['requests' => 2, 'window' => 60]]],
						'channels' => [['slug' => 'publications', 'title' => 'Publications', 'types' => ['nl.woo.publication.created', 'nl.woo.publication.updated']]],
					])]];
				}

				if ($filters['schema'] === 'api_product_subscription') {
					return ['results' => ($this->subscribed === true ? [$entity('ps-1', ['status' => 'active', 'tier' => 'free'])] : [])];
				}

				$rows = [];
				foreach ($this->eventSubscriptions as $uuid => $data) {
					$rows[] = $entity($uuid, $data);
				}

				return ['results' => $rows];
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function ($object) use ($entity): ObjectEntity {
				$uuid = 'es-' . (count($this->eventSubscriptions) + 1);
				$this->eventSubscriptions[$uuid] = $object;
				return $entity($uuid, $object);
			}
		);
		$objects->method('find')->willReturnCallback(fn ($id): ObjectEntity => $entity((string)$id, ($this->eventSubscriptions[$id] ?? [])));
		$objects->method('deleteObject')->willReturnCallback(
			function (?string $uuid = null): bool {
				$this->deleted[] = (string)$uuid;
				return true;
			}
		);

		return $objects;
	}//end objects()
}//end class

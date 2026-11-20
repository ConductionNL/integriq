<?php

/**
 * Integriq ProductChannelsController.
 *
 * Serves the AsyncAPI document of an API product's event channels.
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-20-events-async-api-products/tasks.md#task-5
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Exception\EgressRefusedException;
use OCA\Integriq\Service\AsyncApiDocumentServiceInterface;
use OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridgeInterface;
use OCA\Integriq\Service\EventServiceInterface;
use OCA\Integriq\Service\Helper\RegisterSave;
use OCA\Integriq\Service\ProductPolicyServiceInterface;
use OCA\Integriq\Service\ProductSubscriber;
use OCA\Integriq\Service\RateLimit\InboundRateLimitServiceInterface;
use OCA\Integriq\Service\Security\EgressGuardInterface;
use OCA\Integriq\Service\Security\SubscriptionSecretMasker;
use OCA\Integriq\Service\Subscriptions\SubscriptionSigningPolicyInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectDeletion;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A public product's document is readable by anyone. A private product's
 * document needs a consumer that authenticates with a JWT and holds an active
 * subscription to the product; without one the answer is 403
 * `subscription_required`, the same refusal a product endpoint gives.
 *
 * @spec openspec/changes/archive/2026-11-20-events-async-api-products/specs/api-product-gateway/spec.md#requirement-the-product-publishes-an-asyncapi-document-of-its-channels-req-aapi-004
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) One product channel surface: the AsyncAPI
 *  document, pull, subscribe and unsubscribe share the consumer, subscription and tier-budget
 *  checks, which is why they live together; each method stays short.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) It joins the product policy, the rate limits,
 *  the event store, the egress guard and the signing policy, the services a channel needs.
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor injection of those services.
 */
class ProductChannelsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                                  $appName       The app name.
	 * @param IRequest                                $request       The request.
	 * @param OrObjectService                         $objects       OpenRegister's object service.
	 * @param AsyncApiDocumentServiceInterface        $documents     Builds the document.
	 * @param ProductPolicyServiceInterface           $policy        The product subscription lookup.
	 * @param OpenRegisterCredentialBridgeInterface   $credentials   Authenticates a consumer.
	 * @param IURLGenerator                           $urlGenerator  Builds the server URL.
	 * @param LoggerInterface                         $logger        The logger.
	 * @param InboundRateLimitServiceInterface|null   $rateLimits    The tier rate limit and quota counters a product endpoint uses.
	 * @param EventServiceInterface|null              $events        Reads a pull subscription's pending events.
	 * @param EgressGuardInterface|null               $egressGuard   Judges a consumer's push sink.
	 * @param SubscriptionSigningPolicyInterface|null $signingPolicy Reveals a new push subscription's signing secret once.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly OrObjectService $objects,
		private readonly AsyncApiDocumentServiceInterface $documents,
		private readonly ProductPolicyServiceInterface $policy,
		private readonly OpenRegisterCredentialBridgeInterface $credentials,
		private readonly IURLGenerator $urlGenerator,
		private readonly LoggerInterface $logger,
		private readonly ?InboundRateLimitServiceInterface $rateLimits = null,
		private readonly ?EventServiceInterface $events = null,
		private readonly ?EgressGuardInterface $egressGuard = null,
		private readonly ?SubscriptionSigningPolicyInterface $signingPolicy = null,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The AsyncAPI 3.0 document of a product's channels, from its latest active version.
	 *
	 * @param string $productSlug The product's slug.
	 *
	 * @return JSONResponse 200 with the document, 403 for a private product without a subscribed consumer,
	 *                      401 for a credential that does not authenticate, 404 for an unknown product.
	 *
	 * @spec openspec/changes/archive/2026-11-20-events-async-api-products/specs/api-product-gateway/spec.md#requirement-the-product-publishes-an-asyncapi-document-of-its-channels-req-aapi-004
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function asyncapi(string $productSlug): JSONResponse {
		$product = $this->documents->latestVersion(versions: $this->versionsOf(productSlug: $productSlug));
		if ($product === null) {
			return new JSONResponse(['error' => 'not_found', 'message' => 'No API product with this slug'], Http::STATUS_NOT_FOUND);
		}

		if (($product->getObject()['visibility'] ?? 'public') !== 'public') {
			$refusal = $this->refuseWithoutSubscription(product: $product);
			if ($refusal !== null) {
				return $refusal;
			}
		}

		$baseUrl = $this->urlGenerator->getAbsoluteURL('/index.php/apps/integriq/api/products/' . rawurlencode($productSlug));

		return new JSONResponse($this->documents->build(product: $product, baseUrl: $baseUrl));
	}//end asyncapi()

	/**
	 * GET /api/products/{productSlug}/channels/{channel}/events: the consumer's pending events on a channel.
	 *
	 * The consumer authenticates as for a product endpoint, needs an active
	 * subscription to the product, and spends the same tier budget, under the
	 * same counter key, as the product's endpoints. The first pull opens the
	 * consumer's pull subscription on the channel.
	 *
	 * @param string      $productSlug The product.
	 * @param string      $channel     The channel slug.
	 * @param string|null $cursor      The last message id read, to read on from.
	 * @param int         $limit       How many messages, at most 100.
	 *
	 * @return JSONResponse `{messages, cursor}`, or 401/403/404/429.
	 *
	 * @spec openspec/changes/archive/2026-11-20-events-async-api-products/specs/api-product-gateway/spec.md#requirement-a-consumer-reaches-a-channel-under-the-products-policies-req-aapi-002
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function pull(string $productSlug, string $channel, ?string $cursor = null, int $limit = 100): JSONResponse {
		$access = $this->channelAccess(productSlug: $productSlug, channel: $channel);
		if ($access instanceof JSONResponse) {
			return $access;
		}

		if ($this->events === null) {
			return $this->unavailable();
		}

		$subscription = $this->pullSubscription(access: $access);
		if ($subscription === null) {
			return $this->unavailable();
		}

		$page = $this->events->pullEvents(subscription: $subscription, limit: max(1, min(100, $limit)), cursor: $cursor);
		return new JSONResponse(
			['messages' => $this->pageMessages(page: $page), 'cursor' => ($page['cursor'] ?? null)],
			Http::STATUS_OK,
			$access['headers']
		);
	}//end pull()

	/**
	 * The messages of one pulled page, each as an array.
	 *
	 * @param array<string, mixed> $page The page `pullEvents` answered.
	 *
	 * @return array<int, array<string, mixed>> The messages.
	 */
	private function pageMessages(array $page): array {
		$messages = [];
		foreach ((array)($page['messages'] ?? []) as $message) {
			if ($message instanceof ObjectEntity) {
				$message = $message->jsonSerialize();
			}

			$messages[] = (array)$message;
		}

		return $messages;
	}//end pageMessages()

	/**
	 * POST /api/products/{productSlug}/channels/{channel}/subscriptions: register a push subscription.
	 *
	 * The subscription filters on the channel's CloudEvent types, is signed, and
	 * names the product subscription it rides on. A sink that is not https, or
	 * that the egress guard refuses, is refused with 400 and nothing is created.
	 *
	 * @param string $productSlug The product.
	 * @param string $channel     The channel slug.
	 *
	 * @return JSONResponse 201 with the subscription and its signing secret, or 400/401/403/404/429.
	 *
	 * @spec openspec/changes/archive/2026-11-20-events-async-api-products/specs/api-product-gateway/spec.md#requirement-push-deliveries-follow-the-product-subscription-req-aapi-003
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function subscribe(string $productSlug, string $channel): JSONResponse {
		$access = $this->channelAccess(productSlug: $productSlug, channel: $channel);
		if ($access instanceof JSONResponse) {
			return $access;
		}

		$sink = trim((string)$this->request->getParam('sink', ''));
		$refusal = $this->refuseSink(sink: $sink);
		if ($refusal !== null) {
			return $refusal;
		}

		$response = $this->savePushSubscription(data: $this->subscriptionFor(access: $access, style: 'push') + ['sink' => $sink]);
		if ($response === null) {
			return $this->unavailable();
		}

		return new JSONResponse($response, Http::STATUS_CREATED, $access['headers']);
	}//end subscribe()

	/**
	 * Save a push subscription and answer it masked, with its signing secret revealed once.
	 *
	 * @param array<string, mixed> $data The event_subscription to save.
	 *
	 * @return array<string, mixed>|null The answer, or null when it could not be saved.
	 *
	 * @spec openspec/changes/archive/2026-11-20-events-async-api-products/specs/api-product-gateway/spec.md#requirement-push-deliveries-follow-the-product-subscription-req-aapi-003
	 */
	private function savePushSubscription(array $data): ?array {
		try {
			$saved = $this->objects->saveObject(
				object: $data,
				options: RegisterSave::options(register: 'integriq', schema: 'event_subscription'),
				_rbac: false,
				_multitenancy: false
			);
			$stored = $this->objects->find(
				id: (string)$saved->getUuid(),
				options: new ObjectFindOptions(register: 'integriq', schema: 'event_subscription', render: false),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			$this->logger->warning('integriq: a product channel push subscription could not be saved: ' . $exception->getMessage());
			return null;
		}

		$response = (new SubscriptionSecretMasker())->mask(subscription: $saved->getObject());
		if ($this->signingPolicy !== null && $stored instanceof ObjectEntity) {
			$response += $this->signingPolicy->reveal(stored: (array)$stored->getObject());
		}

		return $response;
	}//end savePushSubscription()

	/**
	 * DELETE /api/products/{productSlug}/channels/{channel}/subscriptions/{id}: end a push subscription.
	 *
	 * Only a subscription riding on the caller's own product subscription, on
	 * this channel, can be ended; any other id answers 404.
	 *
	 * @param string $productSlug The product.
	 * @param string $channel     The channel slug.
	 * @param string $id          The event subscription id.
	 *
	 * @return JSONResponse 200, or 401/403/404/429.
	 *
	 * @spec openspec/changes/archive/2026-11-20-events-async-api-products/specs/api-product-gateway/spec.md#requirement-push-deliveries-follow-the-product-subscription-req-aapi-003
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function unsubscribe(string $productSlug, string $channel, string $id): JSONResponse {
		$access = $this->channelAccess(productSlug: $productSlug, channel: $channel);
		if ($access instanceof JSONResponse) {
			return $access;
		}

		$owned = $this->ownSubscriptions(access: $access, style: 'push');
		$match = array_values(array_filter($owned, static fn (ObjectEntity $row): bool => (string)$row->getUuid() === $id));
		if ($match === []) {
			return new JSONResponse(['error' => 'not_found', 'message' => 'No such subscription on this channel'], Http::STATUS_NOT_FOUND);
		}

		try {
			$this->objects->deleteObject(
				new ObjectDeletion(uuid: $id, target: new ObjectFindTarget(register: 'integriq', schema: 'event_subscription')),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			$this->logger->warning('integriq: a product channel push subscription could not be deleted: ' . $exception->getMessage());
			return $this->unavailable();
		}

		return new JSONResponse([], Http::STATUS_OK, $access['headers']);
	}//end unsubscribe()

	/**
	 * The product, the channel, the consumer and its subscription behind one
	 * channel request, with the tier budget spent; or the refusal.
	 *
	 * @param string $productSlug The product.
	 * @param string $channel     The channel slug.
	 *
	 * @return array<string, mixed>|JSONResponse `product`, `channel`, `consumer`, `subscription` and `headers`, or the refusal.
	 *
	 * @spec openspec/changes/archive/2026-11-20-events-async-api-products/specs/api-product-gateway/spec.md#requirement-a-consumer-reaches-a-channel-under-the-products-policies-req-aapi-002
	 */
	private function channelAccess(string $productSlug, string $channel): array|JSONResponse {
		$product = $this->documents->latestVersion(versions: $this->versionsOf(productSlug: $productSlug));
		$declared = $this->declaredChannel(product: $product, channel: $channel);
		if ($product === null || $declared === null) {
			return new JSONResponse(['error' => 'not_found', 'message' => 'No such channel on this API product'], Http::STATUS_NOT_FOUND);
		}

		$subscriber = $this->subscriber(product: $product);
		if ($subscriber instanceof JSONResponse) {
			return $subscriber;
		}

		$headers = $this->policy->buildDeprecationHeaders(product: $product);
		$limited = $this->spendBudget(product: $product, subscriber: $subscriber);
		if ($limited instanceof JSONResponse) {
			return $limited;
		}

		return [
			'product' => $product,
			'channel' => $declared,
			'consumer' => $subscriber['consumer'],
			'subscription' => $subscriber['subscription'],
			'headers' => array_merge($headers, $limited),
		];
	}//end channelAccess()

	/**
	 * The channel the product declares under a slug, the last one when repeated.
	 *
	 * @param ObjectEntity|null $product The product, or null.
	 * @param string            $channel The channel slug.
	 *
	 * @return array<string, mixed>|null The declared channel, or null.
	 */
	private function declaredChannel(?ObjectEntity $product, string $channel): ?array {
		$declared = null;
		foreach ((array)($product?->getObject()['channels'] ?? []) as $candidate) {
			if (is_array($candidate) === true && ($candidate['slug'] ?? null) === $channel) {
				$declared = $candidate;
			}
		}

		return $declared;
	}//end declaredChannel()

	/**
	 * Spend one request of the subscription tier's budget, under the counter key
	 * the product's endpoints use.
	 *
	 * @param ObjectEntity                                              $product    The product.
	 * @param array{consumer: ObjectEntity, subscription: ObjectEntity} $subscriber The consumer and its product subscription.
	 *
	 * @return array<string, string>|JSONResponse The RateLimit headers, or 429.
	 */
	private function spendBudget(ObjectEntity $product, array $subscriber): array|JSONResponse {
		$consumer = $subscriber['consumer'];
		$tier = $this->policy->resolveTierPolicy(product: $product, subscription: $subscriber['subscription']);
		if ($tier === null || $this->rateLimits === null || ($tier['rateLimit'] === null && $tier['quota'] === null)) {
			return [];
		}

		$decision = $this->rateLimits->enforce(
			consumerKey: $this->policy->counterKey(product: $product, consumerUuid: (string)$consumer->getUuid()),
			rateLimit: $tier['rateLimit'],
			quota: $tier['quota']
		);
		if ($decision->allowed === true) {
			return $decision->toHeaders();
		}

		return new JSONResponse(
			['error' => 'rate_limited', 'message' => 'Too Many Requests', 'reason' => $decision->reason],
			Http::STATUS_TOO_MANY_REQUESTS,
			$decision->toHeaders()
		);
	}//end spendBudget()

	/**
	 * Refuse a push sink that is not https or that the egress guard refuses.
	 *
	 * @param string $sink The sink the consumer named.
	 *
	 * @return JSONResponse|null The 400, or null when the sink may be called.
	 *
	 * @spec openspec/changes/archive/2026-11-20-events-async-api-products/specs/api-product-gateway/spec.md#requirement-push-deliveries-follow-the-product-subscription-req-aapi-003
	 */
	private function refuseSink(string $sink): ?JSONResponse {
		if ($this->isHttpsUrl(sink: $sink) === false) {
			return new JSONResponse(['error' => 'invalid_sink', 'message' => 'The sink must be an https URL'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->egressGuard === null) {
			return $this->unavailable();
		}

		try {
			$this->egressGuard->assertAllowed(url: $sink);
		} catch (EgressRefusedException $exception) {
			return new JSONResponse(
				['error' => 'invalid_sink', 'message' => 'The sink may not be called: ' . $exception->getMessage()],
				Http::STATUS_BAD_REQUEST
			);
		}

		return null;
	}//end refuseSink()

	/**
	 * Whether the sink is a well-formed https URL.
	 *
	 * @param string $sink The sink the consumer named.
	 *
	 * @return bool True for an https URL.
	 */
	private function isHttpsUrl(string $sink): bool {
		return $sink !== '' && strtolower((string)parse_url($sink, PHP_URL_SCHEME)) === 'https' && filter_var($sink, FILTER_VALIDATE_URL) !== false;
	}//end isHttpsUrl()

	/**
	 * The consumer's pull subscription on the channel, opened on first use.
	 *
	 * @param array<string, mixed> $access The channel access.
	 *
	 * @return ObjectEntity|null The subscription, or null when it could not be read or opened.
	 */
	private function pullSubscription(array $access): ?ObjectEntity {
		$existing = $this->ownSubscriptions(access: $access, style: 'pull');
		if ($existing !== []) {
			return $existing[0];
		}

		try {
			$saved = $this->objects->saveObject(
				object: $this->subscriptionFor(access: $access, style: 'pull'),
				options: RegisterSave::options(register: 'integriq', schema: 'event_subscription'),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			$this->logger->warning('integriq: a product channel pull subscription could not be opened: ' . $exception->getMessage());
			return null;
		}

		return $saved;
	}//end pullSubscription()

	/**
	 * The consumer's subscriptions of one style on this channel of its product subscription.
	 *
	 * @param array<string, mixed> $access The channel access.
	 * @param string               $style  `pull` or `push`.
	 *
	 * @return array<int, ObjectEntity> The subscriptions.
	 */
	private function ownSubscriptions(array $access, string $style): array {
		$productSubscription = (string)$access['subscription']->getUuid();
		$channel = (string)$access['channel']['slug'];
		try {
			$matches = $this->objects->findAll(
				config: [
					'filters' => [
						'register' => 'integriq',
						'schema' => 'event_subscription',
						'productSubscription' => $productSubscription,
						'productChannel' => $channel,
						'style' => $style,
					],
					'limit' => 100,
				],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			$this->logger->warning('integriq: product channel subscriptions could not be read: ' . $exception->getMessage());
			return [];
		}

		$wanted = ['productSubscription' => $productSubscription, 'productChannel' => $channel, 'style' => $style];

		return array_values(array_filter((array)($matches['results'] ?? $matches), static fn ($row): bool => self::matches(row: $row, wanted: $wanted)));
	}//end ownSubscriptions()

	/**
	 * Whether a row is an event_subscription carrying every wanted value.
	 *
	 * @param mixed                 $row    The row.
	 * @param array<string, string> $wanted The field values it must carry.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-11-20-events-async-api-products/specs/api-product-gateway/spec.md#requirement-push-deliveries-follow-the-product-subscription-req-aapi-003
	 */
	private static function matches(mixed $row, array $wanted): bool {
		if ($row instanceof ObjectEntity === false) {
			return false;
		}

		$object = $row->getObject();
		foreach ($wanted as $field => $value) {
			if (($object[$field] ?? null) !== $value) {
				return false;
			}
		}

		return true;
	}//end matches()

	/**
	 * A new event subscription on this channel for the consumer.
	 *
	 * @param array<string, mixed> $access The channel access.
	 * @param string               $style  `pull` or `push`.
	 *
	 * @return array<string, mixed> The subscription.
	 */
	private function subscriptionFor(array $access, string $style): array {
		return [
			'types' => array_values(array_filter((array)($access['channel']['types'] ?? []), 'is_string')),
			'style' => $style,
			'status' => 'active',
			'protocol' => 'HTTP',
			'source' => 'integriq',
			'productSubscription' => (string)$access['subscription']->getUuid(),
			'productChannel' => (string)$access['channel']['slug'],
			'reference' => 'consumer:' . (string)$access['consumer']->getUuid(),
		];
	}//end subscriptionFor()

	/**
	 * A 503 for a dependency that is not there.
	 *
	 * @return JSONResponse
	 */
	private function unavailable(): JSONResponse {
		return new JSONResponse(['error' => 'unavailable', 'message' => 'The channel cannot be served right now'], Http::STATUS_SERVICE_UNAVAILABLE);
	}//end unavailable()

	/**
	 * Refuse a caller that is not a consumer with an active subscription to the product.
	 *
	 * @param ObjectEntity $product The product version.
	 *
	 * @return JSONResponse|null The refusal, or null when the caller may read.
	 */
	private function refuseWithoutSubscription(ObjectEntity $product): ?JSONResponse {
		$subscriber = $this->subscriber(product: $product);
		if ($subscriber instanceof JSONResponse) {
			return $subscriber;
		}

		return null;
	}//end refuseWithoutSubscription()

	/**
	 * The authenticated consumer and its active subscription to the product, or the refusal.
	 *
	 * The consumer authenticates with a JWT, as on the AsyncAPI route; without
	 * one, or without an active subscription, the answer is 403
	 * `subscription_required`, and a token that does not authenticate is 401.
	 *
	 * @param ObjectEntity $product The product version.
	 *
	 * @return array{consumer: ObjectEntity, subscription: ObjectEntity}|JSONResponse
	 *
	 * @spec openspec/changes/archive/2026-11-20-events-async-api-products/specs/api-product-gateway/spec.md#requirement-a-consumer-reaches-a-channel-under-the-products-policies-req-aapi-002
	 */
	private function subscriber(ObjectEntity $product): array|JSONResponse {
		return (new ProductSubscriber(credentials: $this->credentials, policy: $this->policy))->resolve(
			authorization: (string)$this->request->getHeader('Authorization'),
			product: $product
		);
	}//end subscriber()

	/**
	 * Every version row of a product, read in system context so an anonymous caller can read a public product.
	 *
	 * @param string $productSlug The product's slug.
	 *
	 * @return array<int,ObjectEntity> The rows.
	 */
	private function versionsOf(string $productSlug): array {
		try {
			// Unscoped: a public page: an anonymous caller reads a public product's versions (see the docblock).
			$matches = $this->objects->findAll(
				config: ['filters' => ['register' => 'integriq', 'schema' => 'api_product', 'productSlug' => $productSlug], 'limit' => 100],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			$this->logger->warning('integriq: failed to read api_product versions: ' . $exception->getMessage());
			return [];
		}

		$rows = ($matches['results'] ?? $matches);
		if (is_array($rows) === false) {
			return [];
		}

		return array_values(
			array_filter(
				$rows,
				static fn ($row): bool => $row instanceof ObjectEntity && ($row->getObject()['productSlug'] ?? null) === $productSlug
			)
		);
	}//end versionsOf()
}//end class

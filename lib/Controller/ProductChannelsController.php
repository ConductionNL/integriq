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
 * @spec openspec/changes/events-async-api-products/tasks.md#task-5
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Exception\AuthenticationException;
use OCA\Integriq\Service\AsyncApiDocumentService;
use OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridge;
use OCA\Integriq\Service\ProductPolicyService;
use OCA\OpenRegister\Db\ObjectEntity;
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
 * @spec openspec/changes/events-async-api-products/specs/api-product-gateway/spec.md#requirement-the-product-publishes-an-asyncapi-document-of-its-channels-req-aapi-004
 */
class ProductChannelsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                       $appName      The app name.
	 * @param IRequest                     $request      The request.
	 * @param OrObjectService              $objects      OpenRegister's object service.
	 * @param AsyncApiDocumentService      $documents    Builds the document.
	 * @param ProductPolicyService         $policy       The product subscription lookup.
	 * @param OpenRegisterCredentialBridge $credentials  Authenticates a consumer.
	 * @param IURLGenerator                $urlGenerator Builds the server URL.
	 * @param LoggerInterface              $logger       The logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly OrObjectService $objects,
		private readonly AsyncApiDocumentService $documents,
		private readonly ProductPolicyService $policy,
		private readonly OpenRegisterCredentialBridge $credentials,
		private readonly IURLGenerator $urlGenerator,
		private readonly LoggerInterface $logger,
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
	 * @spec openspec/changes/events-async-api-products/specs/api-product-gateway/spec.md#requirement-the-product-publishes-an-asyncapi-document-of-its-channels-req-aapi-004
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
	 * Refuse a caller that is not a consumer with an active subscription to the product.
	 *
	 * @param ObjectEntity $product The product version.
	 *
	 * @return JSONResponse|null The refusal, or null when the caller may read.
	 */
	private function refuseWithoutSubscription(ObjectEntity $product): ?JSONResponse {
		$subscriptionRequired = new JSONResponse(
			['error' => 'subscription_required', 'message' => 'No active subscription to this API product'],
			Http::STATUS_FORBIDDEN
		);

		$header = (string)$this->request->getHeader('Authorization');
		if (str_starts_with($header, 'Bearer ') === false || substr_count($header, '.') !== 2) {
			return $subscriptionRequired;
		}

		try {
			$this->credentials->authorizeJwt(authorization: $header);
		} catch (AuthenticationException $exception) {
			return new JSONResponse(['error' => 'unauthorized', 'message' => $exception->getMessage()], Http::STATUS_UNAUTHORIZED);
		}

		$consumer = $this->credentials->getResolvedConsumer();
		if ($consumer === null) {
			return $subscriptionRequired;
		}

		$subscription = $this->policy->resolveActiveSubscription(consumerUuid: (string)$consumer->getUuid(), productUuid: (string)$product->getUuid());
		if ($subscription === null) {
			return $subscriptionRequired;
		}

		return null;
	}//end refuseWithoutSubscription()

	/**
	 * Every version row of a product, read in system context so an anonymous caller can read a public product.
	 *
	 * @param string $productSlug The product's slug.
	 *
	 * @return array<int,ObjectEntity> The rows.
	 */
	private function versionsOf(string $productSlug): array {
		try {
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

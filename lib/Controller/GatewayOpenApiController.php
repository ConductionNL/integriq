<?php

/**
 * Integriq GatewayOpenApiController.
 *
 * Serves the OpenAPI 3.1 description of the gateway's published endpoints and
 * of one API product.
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
 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-integriq-publishes-an-openapi-description-of-its-endpoints-req-oapi-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Exception\AuthenticationException;
use OCA\Integriq\Service\AsyncApiDocumentService;
use OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridge;
use OCA\Integriq\Service\OpenApi\OpenApiPublishService;
use OCA\Integriq\Service\ProductPolicyService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * `GET /api/gateway/openapi.json` describes every endpoint in a public, active
 * product and is readable by anyone. `GET /api/products/{id}/openapi.json`
 * describes one product: a public product is readable by anyone, a private one
 * by an administrator or a consumer holding an active subscription to it (the
 * same check the product's AsyncAPI document makes). `/api/openapi.json` stays
 * the app's own API contract.
 *
 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-integriq-publishes-an-openapi-description-of-its-endpoints-req-oapi-002
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class GatewayOpenApiController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                       $appName      The app name.
	 * @param IRequest                     $request      The request.
	 * @param OpenApiPublishService        $publisher    Builds the documents.
	 * @param OrObjectService              $objects      OpenRegister's object service.
	 * @param AsyncApiDocumentService      $versions     Picks a product's latest version.
	 * @param ProductPolicyService         $policy       The product subscription lookup.
	 * @param OpenRegisterCredentialBridge $credentials  Authenticates a consumer.
	 * @param IUserSession                 $userSession  The session user.
	 * @param IGroupManager                $groupManager Tells an administrator.
	 * @param IAppManager                  $appManager   The installed app version.
	 * @param IURLGenerator                $urlGenerator Builds the server URL.
	 * @param LoggerInterface              $logger       The logger.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly OpenApiPublishService $publisher,
		private readonly OrObjectService $objects,
		private readonly AsyncApiDocumentService $versions,
		private readonly ProductPolicyService $policy,
		private readonly OpenRegisterCredentialBridge $credentials,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly IAppManager $appManager,
		private readonly IURLGenerator $urlGenerator,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The OpenAPI 3.1 document of every endpoint in a public, active API product.
	 *
	 * @return JSONResponse The document.
	 *
	 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-integriq-publishes-an-openapi-description-of-its-endpoints-req-oapi-002
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function gateway(): JSONResponse {
		$document = $this->publisher->gatewayDocument(
			serverUrl: $this->serverUrl(),
			version: (string) $this->appManager->getAppVersion(appId: 'integriq')
		);

		return self::publicResponse(data: $document);
	}//end gateway()

	/**
	 * The OpenAPI 3.1 document of one API product, by uuid or by productSlug (its latest active version).
	 *
	 * @param string $id The product's uuid or productSlug.
	 *
	 * @return JSONResponse 200 with the document, 403 for a private product without an administrator or a subscribed consumer,
	 *                      401 for a credential that does not authenticate, 404 for an unknown product.
	 *
	 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-integriq-publishes-an-openapi-description-of-its-endpoints-req-oapi-002
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function product(string $id): JSONResponse {
		$product = $this->productOf(id: $id);
		if ($product === null) {
			return new JSONResponse(['error' => 'not_found', 'message' => 'No API product with this id'], Http::STATUS_NOT_FOUND);
		}

		if (($product->getObject()['visibility'] ?? 'public') !== 'public' && $this->isAdmin() === false) {
			$refusal = $this->refuseWithoutSubscription(product: $product);
			if ($refusal !== null) {
				return $refusal;
			}
		}

		return self::publicResponse(data: $this->publisher->productDocument(product: $product, serverUrl: $this->serverUrl()));
	}//end product()

	/**
	 * A product by uuid, else the latest version of the product with that slug.
	 *
	 * @param string $id The uuid or productSlug.
	 *
	 * @return ObjectEntity|null The product row, or null when there is none.
	 */
	private function productOf(string $id): ?ObjectEntity {
		try {
			$product = $this->objects->find(id: $id, register: 'integriq', schema: 'api_product', _rbac: false, _multitenancy: false);
			if ($product instanceof ObjectEntity) {
				return $product;
			}
		} catch (Throwable $exception) {
			// Not a uuid of a product: try it as a productSlug below.
			$this->logger->debug('integriq: no api_product with uuid '.$id.': '.$exception->getMessage());
		}

		try {
			$matches = $this->objects->findAll(
				config: ['filters' => ['register' => 'integriq', 'schema' => 'api_product', 'productSlug' => $id], 'limit' => 100],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			$this->logger->warning('integriq: failed to read api_product versions: '.$exception->getMessage());
			return null;
		}

		$rows = array_filter(
			(array) ($matches['results'] ?? $matches),
			static fn (mixed $row): bool => $row instanceof ObjectEntity && ($row->getObject()['productSlug'] ?? null) === $id
		);

		return $this->versions->latestVersion(versions: array_values($rows));
	}//end productOf()

	/**
	 * Whether the session user is an administrator.
	 *
	 * @return bool True for an administrator.
	 */
	private function isAdmin(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		return $this->groupManager->isAdmin($user->getUID());
	}//end isAdmin()

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

		$header = (string) $this->request->getHeader('Authorization');
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

		$subscription = $this->policy->resolveActiveSubscription(consumerUuid: (string) $consumer->getUuid(), productUuid: (string) $product->getUuid());
		if ($subscription === null) {
			return $subscriptionRequired;
		}

		return null;
	}//end refuseWithoutSubscription()

	/**
	 * The absolute URL the gateway serves endpoints under.
	 *
	 * @return string The URL.
	 */
	private function serverUrl(): string {
		return $this->urlGenerator->getAbsoluteURL('/index.php/apps/integriq/api/endpoint');
	}//end serverUrl()

	/**
	 * A document readable from any origin.
	 *
	 * @param array<string,mixed> $data The document.
	 *
	 * @return JSONResponse The response.
	 */
	private static function publicResponse(array $data): JSONResponse {
		$response = new JSONResponse($data);
		$response->addHeader(name: 'Access-Control-Allow-Origin', value: '*');

		return $response;
	}//end publicResponse()
}//end class

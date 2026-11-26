<?php

/**
 * Integriq Portal Controller.
 *
 * The developer portal: members of the developer group browse the public API
 * products, create applications (consumers they own) and request access to a
 * product tier through the product's existing approval. Every route checks
 * the developer group in its body, and every action on an application checks
 * that the developer owns it before it reads or writes anything
 * (access-developer-portal-and-subscriptions D1-D3). The administrator names
 * the developer group through the two settings routes.
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-25-access-developer-portal-and-subscriptions/design.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use InvalidArgumentException;
use OCA\Integriq\Kernel\AppIdentity;
use OCA\Integriq\Service\DeveloperPortalAccess;
use OCA\Integriq\Service\Helper\RegisterSave;
use OCA\Integriq\Service\ProductSubscriptionService;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Developer portal REST surface.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
 *
 * @spec openspec/specs/api-product-gateway/spec.md
 */
class PortalController extends Controller {

	/**
	 * OpenRegister register slug.
	 *
	 * @var string
	 */
	private const REGISTER = 'integriq';

	/**
	 * The most rows one portal list reads.
	 *
	 * @var integer
	 */
	private const LIST_LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param IRequest                   $request       The request.
	 * @param OrObjectService            $objects       OpenRegister's object service.
	 * @param ProductSubscriptionService $subscriptions The shared subscribe path.
	 * @param DeveloperPortalAccess      $access        Who a developer is.
	 * @param IUserSession               $userSession   The session.
	 * @param IAppConfig                 $appConfig     The app configuration, for the developer group.
	 * @param IGroupManager              $groupManager  Checks a named group exists.
	 * @param IL10N                      $l             Translates the refusals.
	 * @param LoggerInterface            $logger        The logger.
	 *
	 * @spec openspec/changes/archive/2026-11-25-access-developer-portal-and-subscriptions/design.md
	 */
	public function __construct(
		IRequest $request,
		private readonly OrObjectService $objects,
		private readonly ProductSubscriptionService $subscriptions,
		private readonly DeveloperPortalAccess $access,
		private readonly IUserSession $userSession,
		private readonly IAppConfig $appConfig,
		private readonly IGroupManager $groupManager,
		private readonly IL10N $l,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The public API products, with their versions, tiers and documentation.
	 *
	 * A private or retired product is not listed.
	 *
	 * @return JSONResponse `{results: list}`, or 403 for an account outside the developer group.
	 *
	 * @spec openspec/specs/api-product-gateway/spec.md#requirement-a-developer-finds-the-published-api-products-req-devp-001
	 */
	#[NoAdminRequired]
	public function products(): JSONResponse {
		[$user, $refusal] = $this->requireDeveloper();
		if ($user === null) {
			return $refusal;
		}

		$products = [];
		foreach ($this->find(filters: ['schema' => 'api_product', 'visibility' => 'public']) as $product) {
			$summary = self::productSummary(product: $product);
			if ($summary['status'] !== 'retired' && $summary['visibility'] === 'public') {
				$products[] = $summary;
			}
		}

		return new JSONResponse(['results' => $products]);
	}//end products()

	/**
	 * The developer's own applications, each with its subscriptions.
	 *
	 * @return JSONResponse `{results: list}`, or 403 for an account outside the developer group.
	 *
	 * @spec openspec/specs/api-product-gateway/spec.md#requirement-a-developer-requests-access-for-their-own-application-req-devp-002
	 */
	#[NoAdminRequired]
	public function applications(): JSONResponse {
		[$user, $refusal] = $this->requireDeveloper();
		if ($user === null) {
			return $refusal;
		}

		$applications = [];
		foreach ($this->find(filters: ['schema' => 'consumer', 'userId' => $user->getUID()]) as $consumer) {
			if ($this->owns(user: $user, consumer: $consumer) === false) {
				continue;
			}

			$applications[] = $this->applicationSummary(consumer: $consumer);
		}

		return new JSONResponse(['results' => $applications]);
	}//end applications()

	/**
	 * Create an application: a consumer the developer owns.
	 *
	 * @return JSONResponse 201 with the application, 400 without a name, or 403 outside the developer group.
	 *
	 * @spec openspec/specs/api-product-gateway/spec.md#requirement-a-developer-requests-access-for-their-own-application-req-devp-002
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) RegisterSave::options() is the shared save-options builder.
	 */
	#[NoAdminRequired]
	public function createApplication(): JSONResponse {
		[$user, $refusal] = $this->requireDeveloper();
		if ($user === null) {
			return $refusal;
		}

		$name = trim((string)$this->request->getParam('name', ''));
		if ($name === '') {
			return new JSONResponse(['error' => $this->l->t('Give the application a name.')], Http::STATUS_BAD_REQUEST);
		}

		try {
			$consumer = $this->objects->saveObject(
				object: [
					'name' => $name,
					'description' => trim((string)$this->request->getParam('description', '')),
					'userId' => $user->getUID(),
					'ownerKind' => 'developer',
				],
				options: RegisterSave::options(register: self::REGISTER, schema: 'consumer'),
				// The consumer schema is admin-only (99-consumer-lockdown), so a
				// developer's own write is refused there. The gate is this
				// controller: requireDeveloper() above, and the application is
				// stamped with the developer as its owner, as every portal read
				// of it (RBAC off, owns()) already assumes.
				_rbac: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('integriq: developer portal could not create an application: ' . $e->getMessage());
			return new JSONResponse(['error' => $this->l->t('The application could not be created.')], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($this->applicationSummary(consumer: $consumer), Http::STATUS_CREATED);
	}//end createApplication()

	/**
	 * Request access for one of the developer's applications to a product tier.
	 *
	 * An application the developer does not own, or a product that is not
	 * public, answers 404 and nothing is written: the developer learns nothing
	 * about objects that are not theirs.
	 *
	 * @param string $consumerId The application's id.
	 *
	 * @return JSONResponse 201 (active), 202 (pending approval), 400, 403 or 404.
	 *
	 * @spec openspec/specs/api-product-gateway/spec.md#requirement-a-developer-requests-access-for-their-own-application-req-devp-002
	 */
	#[NoAdminRequired]
	public function requestAccess(string $consumerId): JSONResponse {
		[$user, $refusal] = $this->requireDeveloper();
		if ($user === null) {
			return $refusal;
		}

		$consumer = $this->findOne(id: $consumerId, schema: 'consumer');
		if ($this->owns(user: $user, consumer: $consumer) === false) {
			return new JSONResponse(['error' => $this->l->t('No such application')], Http::STATUS_NOT_FOUND);
		}

		$product = $this->findOne(id: (string)$this->request->getParam('productId', ''), schema: 'api_product');
		if (self::isOffered(product: $product) === false) {
			return new JSONResponse(['error' => $this->l->t('No such API product')], Http::STATUS_NOT_FOUND);
		}

		$tier = (string)$this->request->getParam('tier', '');
		if ($tier === '') {
			$tier = (string)($product->getObject()['defaultTier'] ?? '');
		}

		try {
			$result = $this->subscriptions->subscribe(
				product: $product,
				consumerId: (string)$consumer->getUuid(),
				tierName: $tier,
				requesterUserId: $user->getUID()
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (Throwable $e) {
			$this->logger->warning('integriq: developer portal access request failed: ' . $e->getMessage());
			return new JSONResponse(['error' => $this->l->t('The access request could not be saved.')], Http::STATUS_BAD_REQUEST);
		}

		$status = Http::STATUS_CREATED;
		if ($result['pending'] === true) {
			$status = Http::STATUS_ACCEPTED;
		}

		return new JSONResponse(self::subscriptionSummary(subscription: $result['subscription']), $status);
	}//end requestAccess()

	/**
	 * The developer group and how many accounts are in it.
	 *
	 * @return JSONResponse `{group, members}`; members is null when the group does not exist.
	 *
	 * @spec openspec/changes/archive/2026-11-25-access-developer-portal-and-subscriptions/design.md#d1-who-a-developer-is
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function getSettings(): JSONResponse {
		return new JSONResponse(['group' => $this->access->group(), 'members' => $this->access->memberCount()]);
	}//end getSettings()

	/**
	 * Name the developer group, or clear it to put the default back.
	 *
	 * @return JSONResponse `{group, members}`, or 400 naming a group that does not exist.
	 *
	 * @spec openspec/changes/archive/2026-11-25-access-developer-portal-and-subscriptions/design.md#d1-who-a-developer-is
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function setSettings(): JSONResponse {
		$group = trim((string)$this->request->getParam('group', ''));
		if ($group !== '' && $this->groupManager->get($group) === null) {
			return new JSONResponse(['error' => $this->l->t('There is no group called %s.', [$group])], Http::STATUS_BAD_REQUEST);
		}

		if ($group === '') {
			$this->appConfig->deleteKey(AppIdentity::APP_ID, DeveloperPortalAccess::CONFIG_KEY);
			return $this->getSettings();
		}

		$this->appConfig->setValueString(AppIdentity::APP_ID, DeveloperPortalAccess::CONFIG_KEY, $group);
		return $this->getSettings();
	}//end setSettings()

	/**
	 * The current account when it is a developer, else the refusal to answer with.
	 *
	 * @return array{0: IUser|null, 1: JSONResponse} The developer, or null and a 401/403 answer.
	 *
	 * @spec openspec/changes/archive/2026-11-25-access-developer-portal-and-subscriptions/design.md#d1-who-a-developer-is
	 */
	private function requireDeveloper(): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return [null, new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED)];
		}

		if ($this->access->isDeveloper(user: $user) === false) {
			$message = $this->l->t('The developer portal is open to members of the developer group only.');
			return [null, new JSONResponse(['error' => $message], Http::STATUS_FORBIDDEN)];
		}

		return [$user, new JSONResponse([])];
	}//end requireDeveloper()

	/**
	 * Whether a consumer is an application the developer owns.
	 *
	 * @param IUser             $user     The developer.
	 * @param ObjectEntity|null $consumer The consumer, or null when there is none.
	 *
	 * @return bool True when its owner is this developer.
	 *
	 * @phpstan-assert-if-true ObjectEntity $consumer
	 * @psalm-assert-if-true ObjectEntity $consumer
	 *
	 * @spec openspec/changes/archive/2026-11-25-access-developer-portal-and-subscriptions/design.md#d2-an-application-is-a-consumer-the-developer-owns
	 */
	private function owns(IUser $user, ?ObjectEntity $consumer): bool {
		$data = ($consumer?->getObject() ?? []);

		return ($data['ownerKind'] ?? '') === 'developer' && (string)($data['userId'] ?? '') === $user->getUID();
	}//end owns()

	/**
	 * Whether a product is offered in the portal: public and not retired.
	 *
	 * @param ObjectEntity|null $product The product, or null when there is none.
	 *
	 * @return bool True when a developer may see and request it.
	 *
	 * @phpstan-assert-if-true ObjectEntity $product
	 * @psalm-assert-if-true ObjectEntity $product
	 */
	private static function isOffered(?ObjectEntity $product): bool {
		$data = $product?->getObject();

		return $data !== null && ($data['visibility'] ?? 'public') === 'public' && ($data['status'] ?? 'active') !== 'retired';
	}//end isOffered()

	/**
	 * One application with its subscriptions.
	 *
	 * @param ObjectEntity $consumer The consumer.
	 *
	 * @return array<string, mixed>
	 */
	private function applicationSummary(ObjectEntity $consumer): array {
		$data = $consumer->getObject();
		$subscriptions = [];
		$found = $this->find(filters: ['schema' => 'api_product_subscription', 'consumer' => (string)$consumer->getUuid()]);
		foreach ($found as $subscription) {
			$subscriptions[] = self::subscriptionSummary(subscription: $subscription);
		}

		return [
			'id' => $consumer->getUuid(),
			'name' => ($data['name'] ?? ''),
			'description' => ($data['description'] ?? ''),
			'subscriptions' => $subscriptions,
		];
	}//end applicationSummary()

	/**
	 * What a developer may see of one product.
	 *
	 * @param ObjectEntity $product The product.
	 *
	 * @return array<string, mixed>
	 */
	private static function productSummary(ObjectEntity $product): array {
		$data = $product->getObject();
		$tiers = [];
		$declared = ($data['tiers'] ?? []);
		if (is_array($declared) === false) {
			$declared = [];
		}

		foreach ($declared as $name => $tier) {
			$tiers[] = ['name' => (string)$name, 'requiresApproval' => (bool)(is_array($tier) === true && ($tier['requiresApproval'] ?? false) === true)];
		}

		return [
			'id' => $product->getUuid(),
			'name' => ($data['name'] ?? ''),
			'description' => ($data['description'] ?? ''),
			'productSlug' => ($data['productSlug'] ?? ''),
			'version' => ($data['version'] ?? ''),
			'status' => ($data['status'] ?? 'active'),
			'visibility' => ($data['visibility'] ?? 'public'),
			'sunsetDate' => ($data['sunsetDate'] ?? null),
			'tiers' => $tiers,
			'defaultTier' => ($data['defaultTier'] ?? null),
			'openApiUrl' => ($data['openApiUrl'] ?? null),
		];
	}//end productSummary()

	/**
	 * What a developer may see of one subscription.
	 *
	 * @param ObjectEntity $subscription The subscription.
	 *
	 * @return array<string, mixed>
	 */
	private static function subscriptionSummary(ObjectEntity $subscription): array {
		$data = $subscription->getObject();

		return [
			'id' => $subscription->getUuid(),
			'product' => ($data['product'] ?? null),
			'tier' => ($data['tier'] ?? null),
			'status' => ($data['status'] ?? 'pending_approval'),
			'expiresAt' => ($data['expiresAt'] ?? null),
		];
	}//end subscriptionSummary()

	/**
	 * One object of the register, or null when it does not exist.
	 *
	 * RBAC is off: a developer holds no register rights, and this controller
	 * has already decided what the developer may reach.
	 *
	 * @param string $id     The object id.
	 * @param string $schema The schema slug.
	 *
	 * @return ObjectEntity|null The object.
	 */
	private function findOne(string $id, string $schema): ?ObjectEntity {
		if ($id === '') {
			return null;
		}

		try {
			$object = $this->objects->find(id: $id, options: new ObjectFindOptions(register: self::REGISTER, schema: $schema), _rbac: false);
		} catch (Throwable $e) {
			return null;
		}

		if ($object instanceof ObjectEntity) {
			return $object;
		}

		return null;
	}//end findOne()

	/**
	 * Objects of the register matching filters, RBAC off as in findOne().
	 *
	 * @param array<string, string> $filters The filters besides the register.
	 *
	 * @return list<ObjectEntity>
	 */
	private function find(array $filters): array {
		try {
			$matches = $this->objects->findAll(
				config: ['filters' => ['register' => self::REGISTER] + $filters, 'limit' => self::LIST_LIMIT],
				_rbac: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('integriq: developer portal lookup failed: ' . $e->getMessage(), ['filters' => $filters]);
			return [];
		}

		$results = ($matches['results'] ?? $matches);
		if (is_array($results) === false) {
			return [];
		}

		return array_values(array_filter($results, static fn (mixed $row): bool => $row instanceof ObjectEntity));
	}//end find()
}//end class

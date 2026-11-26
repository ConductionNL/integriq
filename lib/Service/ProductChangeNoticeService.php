<?php

/**
 * Integriq ProductChangeNoticeService.
 *
 * Writes `product_change_notice` objects: one per active subscription when an
 * administrator deprecates an API product, sets or moves its sunset date,
 * publishes a new version or retires it, and one for a subscription that ends
 * within fourteen days. The notice schema's `created` notification sends the
 * Nextcloud notification and the mail; the notice itself is the record of who
 * was told what and when (access-developer-portal-and-subscriptions D5, D6).
 *
 * @category Service
 * @package  OCA\Integriq\Service
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
 * @spec openspec/changes/archive/2026-11-25-access-developer-portal-and-subscriptions/design.md#d5-the-change-notice-is-an-object-with-a-declared-notification
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use DateTimeImmutable;
use OCA\Integriq\Service\Helper\RegisterSave;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Tells the owners of active subscriptions that a product they use changed.
 *
 * @spec openspec/specs/api-product-gateway/spec.md#requirement-subscribers-are-told-when-a-product-they-use-changes-req-devp-004
 */
class ProductChangeNoticeService {

	/**
	 * OpenRegister register slug.
	 *
	 * @var string
	 */
	private const REGISTER = 'integriq';

	/**
	 * The most subscriptions of one product read per notice run.
	 *
	 * @var integer
	 */
	private const SUBSCRIPTION_LIMIT = 1000;

	/**
	 * Constructor.
	 *
	 * @param OrObjectService $orObjectService OpenRegister's object service.
	 * @param IL10N           $l               Translates the notice text.
	 * @param LoggerInterface $logger          The logger.
	 *
	 * @spec openspec/changes/archive/2026-11-25-access-developer-portal-and-subscriptions/design.md#d5-the-change-notice-is-an-object-with-a-declared-notification
	 */
	public function __construct(
		private readonly OrObjectService $orObjectService,
		private readonly IL10N $l,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The kind of change between two versions of one product, or null when
	 * subscribers need not hear about it.
	 *
	 * Retired weighs more than deprecated, and deprecated more than a moved
	 * sunset date: deprecating with a sunset date in one save is one notice
	 * that names the date, not two.
	 *
	 * @param array<string, mixed> $old The product before the save; empty for a new one.
	 * @param array<string, mixed> $new The product after the save.
	 *
	 * @return string|null `retired`, `deprecated`, `sunset-date-set` or null.
	 *
	 * @spec openspec/specs/api-product-gateway/spec.md#requirement-subscribers-are-told-when-a-product-they-use-changes-req-devp-004
	 */
	public function kindFor(array $old, array $new): ?string {
		$oldStatus = (string)($old['status'] ?? '');
		$newStatus = (string)($new['status'] ?? '');

		if ($newStatus === 'retired' && $oldStatus !== 'retired') {
			return 'retired';
		}

		if ($newStatus === 'deprecated' && $oldStatus !== 'deprecated') {
			return 'deprecated';
		}

		$newSunset = self::text(value: ($new['sunsetDate'] ?? null));
		if ($newStatus !== 'retired' && $newSunset !== '' && $newSunset !== self::text(value: ($old['sunsetDate'] ?? null))) {
			return 'sunset-date-set';
		}

		return null;
	}//end kindFor()

	/**
	 * Write one notice of a kind for every active subscription of a product.
	 *
	 * @param ObjectEntity $product The api_product that changed.
	 * @param string       $kind    `deprecated`, `sunset-date-set` or `retired`.
	 *
	 * @return int The number of notices written.
	 *
	 * @spec openspec/specs/api-product-gateway/spec.md#requirement-subscribers-are-told-when-a-product-they-use-changes-req-devp-004
	 */
	public function notifySubscribers(ObjectEntity $product, string $kind): int {
		$data = $product->getObject();
		$name = self::nameOf(product: $product);
		$sunset = self::text(value: ($data['sunsetDate'] ?? null));
		$message = $this->message(kind: $kind, productName: $name, date: self::day(value: $sunset), version: '');

		$written = 0;
		foreach ($this->activeSubscriptions(productUuid: (string)$product->getUuid()) as $subscription) {
			$notice = $this->writeNotice(
				product: $product,
				subscription: $subscription,
				kind: $kind,
				message: $message,
				effectiveDate: $sunset
			);
			if ($notice !== null) {
				$written++;
			}
		}

		return $written;
	}//end notifySubscribers()

	/**
	 * Tell the subscribers of a product's other versions that a new version was published.
	 *
	 * @param ObjectEntity $newProduct The api_product row just created.
	 *
	 * @return int The number of notices written.
	 *
	 * @spec openspec/specs/api-product-gateway/spec.md#requirement-subscribers-are-told-when-a-product-they-use-changes-req-devp-004
	 */
	public function notifyNewVersion(ObjectEntity $newProduct): int {
		$data = $newProduct->getObject();
		$slug = self::text(value: ($data['productSlug'] ?? null));
		if ($slug === '') {
			return 0;
		}

		$version = self::text(value: ($data['version'] ?? null));
		$written = 0;
		foreach ($this->productsWithSlug(slug: $slug) as $sibling) {
			if ((string)$sibling->getUuid() === (string)$newProduct->getUuid()) {
				continue;
			}

			$message = $this->message(kind: 'new-version', productName: self::nameOf(product: $sibling), date: '', version: $version);
			foreach ($this->activeSubscriptions(productUuid: (string)$sibling->getUuid()) as $subscription) {
				$notice = $this->writeNotice(product: $sibling, subscription: $subscription, kind: 'new-version', message: $message, effectiveDate: '');
				if ($notice !== null) {
					$written++;
				}
			}
		}

		return $written;
	}//end notifyNewVersion()

	/**
	 * Remind the owner of one subscription that it ends soon.
	 *
	 * @param ObjectEntity $subscription The api_product_subscription.
	 * @param ObjectEntity $product      Its api_product.
	 * @param string       $expiresAt    The subscription's `expiresAt`.
	 *
	 * @return ObjectEntity|null The notice, or null when the subscription has no owner to tell.
	 *
	 * @spec openspec/specs/api-product-gateway/spec.md#requirement-a-subscription-can-end-on-a-date-req-devp-005
	 */
	public function notifyExpiring(ObjectEntity $subscription, ObjectEntity $product, string $expiresAt): ?ObjectEntity {
		$message = $this->message(
			kind: 'subscription-expiring',
			productName: self::nameOf(product: $product),
			date: self::day(value: $expiresAt),
			version: ''
		);

		return $this->writeNotice(
			product: $product,
			subscription: $subscription,
			kind: 'subscription-expiring',
			message: $message,
			effectiveDate: $expiresAt
		);
	}//end notifyExpiring()

	/**
	 * The text a subscriber reads for one kind of notice.
	 *
	 * @param string $kind        The notice kind.
	 * @param string $productName The product's name.
	 * @param string $date        The date the notice is about as `Y-m-d`, or ''.
	 * @param string $version     The new version, for `new-version`.
	 *
	 * @return string The translated message.
	 *
	 * @spec openspec/specs/api-product-gateway/spec.md#requirement-subscribers-are-told-when-a-product-they-use-changes-req-devp-004
	 */
	public function message(string $kind, string $productName, string $date, string $version): string {
		return match (true) {
			$kind === 'deprecated' && $date !== '' => $this->l->t('%1$s is deprecated and is retired on %2$s.', [$productName, $date]),
			$kind === 'deprecated' => $this->l->t('%s is deprecated.', [$productName]),
			$kind === 'sunset-date-set' => $this->l->t('%1$s is retired on %2$s.', [$productName, $date]),
			$kind === 'retired' => $this->l->t('%s is retired. Its endpoints no longer answer.', [$productName]),
			$kind === 'new-version' => $this->l->t('%1$s has a new version: %2$s.', [$productName, $version]),
			default => $this->l->t('Your subscription to %1$s ends on %2$s.', [$productName, $date]),
		};
	}//end message()

	/**
	 * Write one notice for one subscription, addressed to its owner.
	 *
	 * @param ObjectEntity $product       The product.
	 * @param ObjectEntity $subscription  The subscription.
	 * @param string       $kind          The notice kind.
	 * @param string       $message       The text.
	 * @param string       $effectiveDate The date the notice is about, or ''.
	 *
	 * @return ObjectEntity|null The notice, or null when nobody owns the subscription or the save failed.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) RegisterSave::options() is the shared save-options builder.
	 */
	private function writeNotice(
		ObjectEntity $product,
		ObjectEntity $subscription,
		string $kind,
		string $message,
		string $effectiveDate
	): ?ObjectEntity {
		$recipient = $this->recipientOf(subscription: $subscription);
		if ($recipient === null) {
			$this->logger->info(
				'integriq: product change notice skipped, subscription has no owner',
				['subscription' => $subscription->getUuid(), 'kind' => $kind]
			);
			return null;
		}

		$notice = [
			'product' => (string)$product->getUuid(),
			'productName' => self::nameOf(product: $product),
			'subscription' => (string)$subscription->getUuid(),
			'recipientUserId' => $recipient,
			'kind' => $kind,
			'message' => $message,
			'sentAt' => (new DateTimeImmutable())->format('c'),
		];
		if ($effectiveDate !== '') {
			$notice['effectiveDate'] = $effectiveDate;
		}

		try {
			// System work: a notice is written from the product save of an
			// administrator and from the daily expiry job, which has no user.
			return $this->orObjectService->saveObject(
				object: $notice,
				options: RegisterSave::options(register: self::REGISTER, schema: 'product_change_notice'),
				_rbac: false
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'integriq: failed to write a product change notice: ' . $e->getMessage(),
				['subscription' => $subscription->getUuid(), 'kind' => $kind]
			);
			return null;
		}
	}//end writeNotice()

	/**
	 * Who owns a subscription: its consumer's account, else the user who requested it.
	 *
	 * @param ObjectEntity $subscription The subscription.
	 *
	 * @return string|null The Nextcloud user id, or null when there is none.
	 *
	 * @spec openspec/changes/archive/2026-11-25-access-developer-portal-and-subscriptions/design.md#d2-an-application-is-a-consumer-the-developer-owns
	 */
	public function recipientOf(ObjectEntity $subscription): ?string {
		$data = $subscription->getObject();
		$consumerId = self::text(value: ($data['consumer'] ?? null));

		if ($consumerId !== '') {
			try {
				$consumer = $this->orObjectService->find(
					id: $consumerId,
					options: new ObjectFindOptions(register: self::REGISTER, schema: 'consumer'),
					_rbac: false
				);
				$owner = self::text(value: ($consumer?->getObject()['userId'] ?? null));
				if ($owner !== '') {
					return $owner;
				}
			} catch (Throwable $e) {
				$this->logger->debug('integriq: subscription consumer could not be read for a notice: ' . $e->getMessage());
			}
		}

		$requester = self::text(value: ($data['requesterUserId'] ?? null));
		if ($requester === '') {
			return null;
		}

		return $requester;
	}//end recipientOf()

	/**
	 * The active subscriptions of a product.
	 *
	 * @param string $productUuid The product's uuid.
	 *
	 * @return list<ObjectEntity>
	 */
	private function activeSubscriptions(string $productUuid): array {
		return $this->findObjects(
			filters: ['schema' => 'api_product_subscription', 'product' => $productUuid, 'status' => 'active']
		);
	}//end activeSubscriptions()

	/**
	 * Every version row of one logical product.
	 *
	 * @param string $slug The productSlug.
	 *
	 * @return list<ObjectEntity>
	 */
	private function productsWithSlug(string $slug): array {
		return $this->findObjects(filters: ['schema' => 'api_product', 'productSlug' => $slug]);
	}//end productsWithSlug()

	/**
	 * Objects of the integriq register matching filters, RBAC off: a notice
	 * run is system work on behalf of the administrator who changed the product.
	 *
	 * @param array<string, string> $filters The filters besides the register.
	 *
	 * @return list<ObjectEntity>
	 */
	private function findObjects(array $filters): array {
		try {
			$matches = $this->orObjectService->findAll(
				config: [
					'filters' => ['register' => self::REGISTER] + $filters,
					'limit' => self::SUBSCRIPTION_LIMIT,
				],
				_rbac: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('integriq: product change notice lookup failed: ' . $e->getMessage(), ['filters' => $filters]);
			return [];
		}

		$results = ($matches['results'] ?? $matches);
		if (is_array($results) === false) {
			return [];
		}

		return array_values(array_filter($results, static fn (mixed $row): bool => $row instanceof ObjectEntity));
	}//end findObjects()

	/**
	 * A product's name, or its uuid when it has none.
	 *
	 * @param ObjectEntity $product The product.
	 *
	 * @return string The name.
	 */
	private static function nameOf(ObjectEntity $product): string {
		$name = self::text(value: ($product->getObject()['name'] ?? null));
		if ($name === '') {
			return (string)$product->getUuid();
		}

		return $name;
	}//end nameOf()

	/**
	 * A date-time as `Y-m-d`, or the value as given when it does not parse.
	 *
	 * @param string $value The date-time, or ''.
	 *
	 * @return string The day.
	 */
	private static function day(string $value): string {
		if ($value === '') {
			return '';
		}

		try {
			return (new DateTimeImmutable($value))->format('Y-m-d');
		} catch (Throwable $e) {
			return $value;
		}
	}//end day()

	/**
	 * A trimmed string, '' for anything that is not one.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The text.
	 */
	private static function text(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end text()
}//end class

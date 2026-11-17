<?php

/**
 * Integriq SubscriptionExpiryService.
 *
 * The daily pass over active API product subscriptions that carry an end
 * date: a subscription whose `expiresAt` has passed is marked `expired`, and
 * one that ends within fourteen days gets a single `subscription-expiring`
 * notice for its owner. Calls after the end date are already refused at
 * request time by ProductPolicyService::expiredOn(); this pass makes the
 * status say so (access-developer-portal-and-subscriptions D6).
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
 * @spec openspec/changes/access-developer-portal-and-subscriptions/design.md#d6-subscription-end-dates
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use OCA\Integriq\Service\Helper\RegisterSave;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Marks ended subscriptions expired and reminds owners fourteen days ahead.
 *
 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-a-subscription-can-end-on-a-date-req-devp-005
 */
class SubscriptionExpiryService {

	/**
	 * OpenRegister register slug.
	 *
	 * @var string
	 */
	private const REGISTER = 'integriq';

	/**
	 * How long before the end date the owner is reminded.
	 *
	 * @var string
	 */
	public const REMINDER_AHEAD = 'P14D';

	/**
	 * Subscriptions read per page.
	 *
	 * @var integer
	 */
	private const PAGE_SIZE = 200;

	/**
	 * The most pages one pass reads, so a pass is bounded.
	 *
	 * @var integer
	 */
	private const MAX_PAGES = 50;

	/**
	 * Constructor.
	 *
	 * @param OrObjectService            $orObjectService OpenRegister's object service.
	 * @param ProductChangeNoticeService $notices         Writes the reminder notice.
	 * @param ProductPolicyService       $policy          Judges whether a subscription has ended.
	 * @param LoggerInterface            $logger          The logger.
	 *
	 * @spec openspec/changes/access-developer-portal-and-subscriptions/design.md#d6-subscription-end-dates
	 */
	public function __construct(
		private readonly OrObjectService $orObjectService,
		private readonly ProductChangeNoticeService $notices,
		private readonly ProductPolicyService $policy,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * One pass over the active subscriptions.
	 *
	 * @param DateTimeInterface|null $now The moment to judge at; now when null.
	 *
	 * @return array{expired: int, reminded: int} How many were marked expired and how many owners reminded.
	 *
	 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-a-subscription-can-end-on-a-date-req-devp-005
	 */
	public function sweep(?DateTimeInterface $now=null): array {
		$now = DateTimeImmutable::createFromInterface($now ?? new DateTimeImmutable());
		$remindFrom = $now->add(new DateInterval(self::REMINDER_AHEAD));
		$counts = ['expired' => 0, 'reminded' => 0];

		for ($page = 1; $page <= self::MAX_PAGES; $page++) {
			$rows = $this->activePage(page: $page);
			foreach ($rows as $subscription) {
				$outcome = $this->handle(subscription: $subscription, now: $now, remindFrom: $remindFrom);
				if ($outcome !== null) {
					$counts[$outcome]++;
				}
			}

			if (count($rows) < self::PAGE_SIZE) {
				break;
			}
		}

		return $counts;
	}//end sweep()

	/**
	 * Expire or remind one subscription.
	 *
	 * @param ObjectEntity      $subscription The subscription.
	 * @param DateTimeImmutable $now          Now.
	 * @param DateTimeImmutable $remindFrom   Ends before this moment: remind.
	 *
	 * @return string|null `expired`, `reminded`, or null when nothing was done.
	 */
	private function handle(ObjectEntity $subscription, DateTimeImmutable $now, DateTimeImmutable $remindFrom): ?string {
		$data = $subscription->getObject();
		$expiresAt = ($data['expiresAt'] ?? null);
		if (is_string($expiresAt) === false || trim($expiresAt) === '') {
			return null;
		}

		try {
			if ($this->policy->expiredOn(subscription: $subscription, now: $now) !== null) {
				$data['status'] = 'expired';
				$this->save(subscription: $subscription, data: $data);
				return 'expired';
			}

			$alreadyReminded = (is_string($data['expiryReminderSentAt'] ?? null) === true && $data['expiryReminderSentAt'] !== '');
			if ($alreadyReminded === true || new DateTimeImmutable($expiresAt) > $remindFrom) {
				return null;
			}

			$product = $this->orObjectService->find(
				id: (string)($data['product'] ?? ''),
				options: new ObjectFindOptions(register: self::REGISTER, schema: 'api_product'),
				_rbac: false
			);
			if ($product === null || $this->notices->notifyExpiring(subscription: $subscription, product: $product, expiresAt: $expiresAt) === null) {
				return null;
			}

			$data['expiryReminderSentAt'] = $now->format('c');
			$this->save(subscription: $subscription, data: $data);
			return 'reminded';
		} catch (Throwable $e) {
			$this->logger->warning(
				'integriq: subscription expiry pass skipped a subscription: ' . $e->getMessage(),
				['subscription' => $subscription->getUuid()]
			);
			return null;
		}//end try
	}//end handle()

	/**
	 * One page of active subscriptions, RBAC off: this is the system's own pass.
	 *
	 * @param int $page The 1-based page.
	 *
	 * @return list<ObjectEntity>
	 */
	private function activePage(int $page): array {
		try {
			$matches = $this->orObjectService->findAll(
				config: [
					'filters' => ['register' => self::REGISTER, 'schema' => 'api_product_subscription', 'status' => 'active'],
					'limit' => self::PAGE_SIZE,
					'offset' => (($page - 1) * self::PAGE_SIZE),
				],
				_rbac: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('integriq: subscription expiry pass could not read subscriptions: ' . $e->getMessage());
			return [];
		}

		$results = ($matches['results'] ?? $matches);
		if (is_array($results) === false) {
			return [];
		}

		return array_values(array_filter($results, static fn (mixed $row): bool => $row instanceof ObjectEntity));
	}//end activePage()

	/**
	 * Save a subscription's changed data.
	 *
	 * @param ObjectEntity         $subscription The subscription.
	 * @param array<string, mixed> $data         Its new data.
	 *
	 * @return void
	 */
	private function save(ObjectEntity $subscription, array $data): void {
		$this->orObjectService->saveObject(
			object: $data,
			options: RegisterSave::options(register: self::REGISTER, schema: 'api_product_subscription', uuid: $subscription->getUuid())
		);
	}//end save()
}//end class

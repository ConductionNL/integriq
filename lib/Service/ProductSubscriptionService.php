<?php

/**
 * Integriq ProductSubscriptionService.
 *
 * The one subscribe path of the API product gateway: it writes the
 * `api_product_subscription`, activates it at once for a tier without an
 * approval gate, and otherwise opens the tier's approval for the product's
 * approver group. The administrator's subscribe route and the developer
 * portal's request-access route both call it, so there is one approval
 * mechanism, not two (access-developer-portal-and-subscriptions D3).
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
 * @spec openspec/changes/access-developer-portal-and-subscriptions/design.md#d3-request-access-reuses-the-approval-flow
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use DateTime;
use InvalidArgumentException;
use OCA\Integriq\Service\Helper\RegisterSave;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\IL10N;

/**
 * Subscribes a consumer to a product tier, behind the tier's approval when it has one.
 *
 * @spec openspec/specs/api-product-gateway/spec.md#requirement-consumer-subscribes-to-an-api-product-at-a-tier-req-apg-003
 */
class ProductSubscriptionService {

	/**
	 * OpenRegister register slug.
	 *
	 * @var string
	 */
	private const REGISTER = 'integriq';

	/**
	 * Constructor.
	 *
	 * @param ApprovalServiceInterface $approvalService The approval state machine the gated tiers suspend on.
	 * @param OrObjectService          $orObjectService OpenRegister's object service.
	 * @param IL10N                    $l               Translates the refusals.
	 *
	 * @spec openspec/changes/access-developer-portal-and-subscriptions/design.md#d3-request-access-reuses-the-approval-flow
	 */
	public function __construct(
		private readonly ApprovalServiceInterface $approvalService,
		private readonly OrObjectService $orObjectService,
		private readonly IL10N $l,
	) {
	}//end __construct()

	/**
	 * Subscribe a consumer to one tier of a product.
	 *
	 * The subscription is written as `pending_approval` first. A tier without
	 * `requiresApproval` is activated at once; a gated tier opens an approval
	 * for its approver group and the subscription carries the approval's id.
	 *
	 * @param ObjectEntity $product         The api_product.
	 * @param string       $consumerId      The consumer's uuid.
	 * @param string       $tierName        The tier, a key of the product's `tiers`.
	 * @param string|null  $requesterUserId Who asked, or null for a caller without an account.
	 *
	 * @return array{subscription: ObjectEntity, pending: bool} The subscription, and whether it waits for approval.
	 *
	 * @throws InvalidArgumentException When the tier is not one of the product's; the message is translated.
	 *                                  OpenRegister's ValidationException on a refused save propagates too.
	 *
	 * @spec openspec/specs/api-product-gateway/spec.md#requirement-consumer-subscribes-to-an-api-product-at-a-tier-req-apg-003
	 * @spec openspec/specs/api-product-gateway/spec.md#requirement-subscription-approval-gate-reuses-the-hitl-approvalservice-req-apg-004
	 * @spec openspec/changes/access-developer-portal-and-subscriptions/design.md#d3-request-access-reuses-the-approval-flow
	 */
	public function subscribe(ObjectEntity $product, string $consumerId, string $tierName, ?string $requesterUserId): array {
		$tier = $this->tierOf(product: $product, tierName: $tierName);
		$now = new DateTime();

		$subscription = $this->orObjectService->saveObject(
			object: [
				'product' => (string)$product->getUuid(),
				'consumer' => $consumerId,
				'tier' => $tierName,
				'status' => 'pending_approval',
				'requesterUserId' => $requesterUserId,
				'createdAt' => $now->format('c'),
			],
			options: RegisterSave::options(register: self::REGISTER, schema: 'api_product_subscription')
		);

		if ((bool)($tier['requiresApproval'] ?? false) === false) {
			return ['subscription' => $this->activate(subscription: $subscription, activatedAt: $now), 'pending' => false];
		}

		$approvalRequest = $this->approvalService->suspendForSubscription(
			subscriptionId: (string)$subscription->getUuid(),
			approverGroup: (string)($tier['approverGroup'] ?? ''),
			onReject: 'error',
			ttlSeconds: ApprovalServiceInterface::DEFAULT_TTL_SECONDS
		);

		$data = $subscription->getObject();
		$data['approvalRequestId'] = $approvalRequest->getUuid();
		$subscription = $this->orObjectService->saveObject(
			object: $data,
			options: RegisterSave::options(register: self::REGISTER, schema: 'api_product_subscription', uuid: $subscription->getUuid())
		);

		return ['subscription' => $subscription, 'pending' => true];
	}//end subscribe()

	/**
	 * Flip a subscription to `active` and stamp `activatedAt`.
	 *
	 * @param ObjectEntity $subscription The subscription.
	 * @param DateTime     $activatedAt  The activation time.
	 *
	 * @return ObjectEntity The saved subscription.
	 *
	 * @throws ValidationException When OpenRegister refuses the save.
	 *
	 * @spec openspec/specs/api-product-gateway/spec.md#requirement-subscription-approval-gate-reuses-the-hitl-approvalservice-req-apg-004
	 */
	public function activate(ObjectEntity $subscription, DateTime $activatedAt): ObjectEntity {
		$data = $subscription->getObject();
		$data['status'] = 'active';
		$data['activatedAt'] = $activatedAt->format('c');

		return $this->orObjectService->saveObject(
			object: $data,
			options: RegisterSave::options(register: self::REGISTER, schema: 'api_product_subscription', uuid: $subscription->getUuid())
		);
	}//end activate()

	/**
	 * The named tier of a product.
	 *
	 * @param ObjectEntity $product  The api_product.
	 * @param string       $tierName The tier's name.
	 *
	 * @return array<string, mixed> The tier.
	 *
	 * @throws InvalidArgumentException When the product has no such tier.
	 */
	private function tierOf(ObjectEntity $product, string $tierName): array {
		$tiers = ($product->getObject()['tiers'] ?? []);
		if ($tierName === '' || is_array($tiers) === false || isset($tiers[$tierName]) === false || is_array($tiers[$tierName]) === false) {
			throw new InvalidArgumentException($this->l->t('Unknown tier: %s', [$tierName]));
		}

		return $tiers[$tierName];
	}//end tierOf()
}//end class

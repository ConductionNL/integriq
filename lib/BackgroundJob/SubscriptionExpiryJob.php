<?php

/**
 * Integriq Subscription Expiry Job.
 *
 * Once a day, marks API product subscriptions whose end date has passed as
 * expired and reminds owners of those ending within fourteen days
 * (access-developer-portal-and-subscriptions D6). Request-time refusal does
 * not wait for this job: ProductPolicyService refuses from the end date on.
 *
 * @category Cron
 * @package  OCA\Integriq\BackgroundJob
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

namespace OCA\Integriq\BackgroundJob;

use OCA\Integriq\Service\SubscriptionExpiryService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Daily subscription expiry pass.
 *
 * @psalm-api
 *
 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-a-subscription-can-end-on-a-date-req-devp-005
 */
class SubscriptionExpiryJob extends TimedJob {

	/**
	 * Once a day.
	 *
	 * @var integer
	 */
	private const INTERVAL = 86400;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory              $time    Time factory for job scheduling.
	 * @param SubscriptionExpiryService $expiry  The expiry pass.
	 * @param LoggerInterface           $logger  Logger for the outcome.
	 *
	 * @spec openspec/changes/access-developer-portal-and-subscriptions/design.md#d6-subscription-end-dates
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly SubscriptionExpiryService $expiry,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

		$this->setInterval(seconds: self::INTERVAL);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(allow: false);
	}//end __construct()

	/**
	 * Run the pass; a failure is logged, never rethrown into cron.
	 *
	 * @param mixed $argument Not used.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-a-subscription-can-end-on-a-date-req-devp-005
	 */
	public function run(mixed $argument): void {
		try {
			$this->logger->info('SubscriptionExpiryJob: pass complete', $this->expiry->sweep());
		} catch (Throwable $e) {
			$this->logger->error('SubscriptionExpiryJob: pass failed: ' . $e->getMessage(), ['exception' => $e]);
		}
	}//end run()
}//end class

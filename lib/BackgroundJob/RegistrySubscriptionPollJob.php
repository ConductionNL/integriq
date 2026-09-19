<?php

/**
 * Polls every registry binding and posts what changed to OpenRegister.
 *
 * @category BackgroundJob
 * @package  OCA\Integriq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\BackgroundJob;

use OCA\Integriq\Service\Registry\RegistryUpdateClient;
use OCA\Integriq\Service\Registry\SubscriptionChange;
use OCA\Integriq\Service\Registry\SubscriptionRegistry;
use OCA\Integriq\Service\Registry\SubscriptionRoster;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Nothing about the changed record is written here. The change is read, it is
 * posted to OpenRegister, and it is gone. A 422 means the registry sent a
 * property OpenRegister does not let this connector own: it is logged and not
 * retried. Anything else is left for the next run.
 *
 * @psalm-api
 *
 * @spec openspec/changes/registry-subscription-connector/specs/registry-subscription-connector/spec.md#requirement-a-polled-change-is-posted-to-openregister-not-stored-locally-req-rsc-003
 */
class RegistrySubscriptionPollJob extends TimedJob {
	/**
	 * Poll interval in seconds (15 minutes).
	 *
	 * @var integer
	 */
	private const DEFAULT_INTERVAL = 900;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory for job scheduling.
	 * @param SubscriptionRegistry $registry The bindings.
	 * @param SubscriptionRoster $roster Which identities are followed.
	 * @param RegistryUpdateClient $updateClient The inbound update endpoint.
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly SubscriptionRegistry $registry,
		private readonly SubscriptionRoster $roster,
		private readonly RegistryUpdateClient $updateClient,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

		$this->setInterval(seconds: self::DEFAULT_INTERVAL);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(allow: false);
	}//end __construct()

	/**
	 * Poll every binding that has someone to follow.
	 *
	 * @param mixed $argument Job argument, unused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/registry-subscription-connector/specs/registry-subscription-connector/spec.md
	 */
	protected function run($argument): void {
		foreach ($this->registry->all() as $registryId => $provider) {
			$identities = array_keys($this->roster->identities($registryId));
			if ($identities === []) {
				continue;
			}

			try {
				$changes = $provider->pollChanges($identities);
			} catch (Throwable $e) {
				$this->logger->warning(
					'registry-subscription.poll.failed',
					[
						'registry' => $registryId,
						'error' => $e->getMessage(),
					]
				);
				continue;
			}

			$this->postChanges(registryId: (string)$registryId, changes: $changes);
		}
	}//end run()

	/**
	 * Post every non-empty change for one registry.
	 *
	 * @param string $registryId Registry id.
	 * @param iterable<SubscriptionChange> $changes The polled changes.
	 *
	 * @return int How many changes were posted.
	 *
	 * @spec openspec/changes/registry-subscription-connector/specs/registry-subscription-connector/spec.md
	 */
	public function postChanges(string $registryId, iterable $changes): int {
		$posted = 0;

		foreach ($changes as $change) {
			if ($change->isEmpty() === true) {
				// An unchanged payload posts nothing.
				continue;
			}

			$status = $this->updateClient->postUpdate($registryId, $change->toArray());
			if ($status === 422) {
				$this->logger->warning(
					'registry-subscription.update.rejected',
					[
						'registry' => $registryId,
						'eventReference' => $change->getEventReference(),
						'reason' => 'a property outside the owned set; not retried',
					]
				);
				continue;
			}

			if ($status >= 200 && $status <= 299) {
				$posted++;
				continue;
			}

			$this->logger->warning(
				'registry-subscription.update.deferred',
				[
					'registry' => $registryId,
					'status' => $status,
					'reason' => 'left for the next scheduled run',
				]
			);
		}//end foreach

		return $posted;
	}//end postChanges()
}//end class

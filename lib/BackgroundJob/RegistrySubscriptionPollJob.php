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

use OCA\Integriq\Service\Registry\MapsSourceFieldsInterface;
use OCA\Integriq\Service\Registry\RegistryUpdateClient;
use OCA\Integriq\Service\Registry\SubscriptionChange;
use OCA\Integriq\Service\Registry\SubscriptionProviderInterface;
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
 * @spec openspec/specs/registry-subscription-connector/spec.md#requirement-a-polled-change-is-posted-to-openregister-not-stored-locally-req-rsc-003
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
	 * @spec openspec/specs/registry-subscription-connector/spec.md
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

			$this->postChanges(registryId: (string)$registryId, changes: $changes, provider: $provider);
		}
	}//end run()

	/**
	 * Post every non-empty change for one registry.
	 *
	 * @param string $registryId Registry id.
	 * @param iterable<SubscriptionChange> $changes The polled changes.
	 * @param SubscriptionProviderInterface|null $provider The binding that polled them, for its field maps.
	 *
	 * @return int How many updates were posted.
	 *
	 * @spec openspec/specs/registry-subscription-connector/spec.md
	 * @spec openspec/changes/registry-update-maps-source-fields/specs/registry-subscription-connector/spec.md#requirement-a-change-is-posted-in-each-target-schemas-own-property-names-req-rsc-004
	 */
	public function postChanges(string $registryId, iterable $changes, ?SubscriptionProviderInterface $provider = null): int {
		$posted = 0;

		foreach ($changes as $polled) {
			foreach ($this->perTarget(registryId: $registryId, change: $polled, provider: $provider) as $change) {
				if ($this->post(registryId: $registryId, change: $change) === true) {
					$posted++;
				}
			}
		}

		return $posted;
	}//end postChanges()

	/**
	 * One change per target schema that follows the identity, each in that
	 * schema's own names. An identity with no recorded target, or a binding
	 * without maps, gives the change unchanged.
	 *
	 * @param string $registryId Registry id.
	 * @param SubscriptionChange $change The polled change, in the source's names.
	 * @param SubscriptionProviderInterface|null $provider The binding.
	 *
	 * @return array<int,SubscriptionChange> The changes to post.
	 *
	 * @spec openspec/changes/registry-update-maps-source-fields/specs/registry-subscription-connector/spec.md#requirement-a-change-is-posted-in-each-target-schemas-own-property-names-req-rsc-004
	 */
	private function perTarget(string $registryId, SubscriptionChange $change, ?SubscriptionProviderInterface $provider): array {
		if (($provider instanceof MapsSourceFieldsInterface) === false) {
			return [$change];
		}

		$targets = $this->roster->targets(registryId: $registryId, identity: $change->getIdentity());
		if ($targets === []) {
			return [$change];
		}

		$changes = [];
		foreach ($targets as $target) {
			$map = $provider->fieldMapFor(targetSchema: $target);
			if ($map === null) {
				$changes[] = $change;
				continue;
			}

			$changes[] = $change->mappedTo(map: $map);
		}

		return $changes;
	}//end perTarget()

	/**
	 * Post one change. A 422 is a property outside the owned set and is not
	 * retried; any other failure is left for the next run.
	 *
	 * @param string $registryId Registry id.
	 * @param SubscriptionChange $change The change.
	 *
	 * @return bool True when OpenRegister accepted it.
	 */
	private function post(string $registryId, SubscriptionChange $change): bool {
		if ($change->isEmpty() === true) {
			// An unchanged payload posts nothing.
			return false;
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
			return false;
		}

		if ($status >= 200 && $status <= 299) {
			return true;
		}

		$this->logger->warning(
			'registry-subscription.update.deferred',
			[
				'registry' => $registryId,
				'status' => $status,
				'reason' => 'left for the next scheduled run',
			]
		);

		return false;
	}//end post()
}//end class

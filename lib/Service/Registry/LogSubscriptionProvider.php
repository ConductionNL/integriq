<?php

/**
 * The development binding: it accepts every subscription and yields only what
 * a fixture queued.
 *
 * @category Provider
 * @package  OCA\Integriq\Service\Registry
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

namespace OCA\Integriq\Service\Registry;

use Psr\Log\LoggerInterface;

/**
 * Bound while no real registry contract is in place. It logs what it was
 * asked and never pretends a registry answered.
 *
 * @spec openspec/changes/registry-subscription-connector/specs/registry-subscription-connector/spec.md#requirement-a-subscription-provider-per-registry-req-rsc-001
 */
class LogSubscriptionProvider implements SubscriptionProviderInterface {
	/**
	 * Registry id this binding answers to.
	 */
	public const REGISTRY_ID = 'log';

	/**
	 * Changes a test or a demo queued for the next poll.
	 *
	 * @var array<int,SubscriptionChange>
	 */
	private array $queued = [];

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(private readonly LoggerInterface $logger) {
	}//end __construct()

	/**
	 * The registry id.
	 *
	 * @return string Registry id.
	 */
	public function registryId(): string {
		return self::REGISTRY_ID;
	}//end registryId()

	/**
	 * Accept the subscription and say so.
	 *
	 * @param string $identity The identity value.
	 *
	 * @return SubscriptionResult Always active.
	 */
	public function subscribe(string $identity): SubscriptionResult {
		$this->logger->info('registry-subscription.log.subscribe', ['identity' => $identity]);

		return SubscriptionResult::active($identity, 'log:' . $identity);
	}//end subscribe()

	/**
	 * Drop the subscription and say so.
	 *
	 * @param string $identity The identity value.
	 *
	 * @return void
	 */
	public function unsubscribe(string $identity): void {
		$this->logger->info('registry-subscription.log.unsubscribe', ['identity' => $identity]);
	}//end unsubscribe()

	/**
	 * Queue a change for the next poll.
	 *
	 * @param SubscriptionChange $change The change to queue.
	 *
	 * @return void
	 */
	public function queue(SubscriptionChange $change): void {
		$this->queued[] = $change;
	}//end queue()

	/**
	 * Everything queued since the last poll, and nothing else.
	 *
	 * @param array<int,string> $identities Identities with an active subscription.
	 *
	 * @return iterable<SubscriptionChange> The queued changes.
	 */
	public function pollChanges(array $identities = []): iterable {
		$changes = $this->queued;
		$this->queued = [];

		if ($identities === []) {
			return $changes;
		}

		return array_values(
			array_filter(
				$changes,
				static fn (SubscriptionChange $change): bool => in_array($change->getIdentity(), $identities, true)
			)
		);
	}//end pollChanges()
}//end class

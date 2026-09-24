<?php

/**
 * Turns a subscription request into a live subscription.
 *
 * @category Service
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
 * This is the half of REQ-RSC-002 that does not depend on how OpenRegister
 * delivers the request. It takes the request as a payload, whatever carried
 * it, subscribes through the matching binding, records the identity on the
 * roster and reports the state back through the inbound update endpoint.
 *
 * The listener that binds it to `RegistrySubscriptionRequestedEvent` is
 * deliberately not written yet: `registry-subscriptions` has no
 * implementation, so its wire shape would be a guess, and tasks.md blocks
 * Task 3 on that question rather than inventing one.
 *
 * @spec openspec/changes/registry-subscription-connector/specs/registry-subscription-connector/spec.md#requirement-a-subscription-request-is-turned-into-a-live-subscription-req-rsc-002
 */
class SubscriptionRequestHandler {
	/**
	 * Constructor.
	 *
	 * @param SubscriptionRegistry $registry The bindings.
	 * @param SubscriptionRoster $roster Which identities are followed.
	 * @param RegistryUpdateClient $updateClient The inbound update endpoint.
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly SubscriptionRegistry $registry,
		private readonly SubscriptionRoster $roster,
		private readonly RegistryUpdateClient $updateClient,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle one subscription request.
	 *
	 * @param array<string,mixed> $request The request payload, carrying a registry id and an identity value.
	 *
	 * @return SubscriptionResult|null The result, or null when the request named a registry nothing answers to.
	 */
	public function handle(array $request): ?SubscriptionResult {
		$registryId = (string)($request['registry'] ?? $request['registryId'] ?? '');
		$identity = (string)($request['identity'] ?? $request['identityValue'] ?? '');

		if ($registryId === '' || $identity === '') {
			$this->logger->warning('registry-subscription.request.incomplete', ['request' => array_keys($request)]);
			return null;
		}

		$provider = $this->registry->get($registryId);
		if ($provider === null) {
			$this->logger->warning('registry-subscription.request.unknown-registry', ['registry' => $registryId]);
			return null;
		}

		$result = $provider->subscribe($identity);

		if ($result->isActive() === true) {
			$this->roster->add($registryId, $identity, $result->getReference());
		}

		// A zero-property update carrying only the state is how the connector
		// confirms, per design D2, until OpenRegister names a dedicated
		// endpoint for it.
		$this->updateClient->postUpdate(
			$registryId,
			[
				'identity' => $identity,
				'properties' => [],
				'eventReference' => $result->getReference(),
				'subscriptionState' => $result->getState(),
				'error' => $result->getError(),
			]
		);

		return $result;
	}//end handle()
}//end class

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

use OCA\OpenRegister\Db\SchemaMapper;
use Psr\Log\LoggerInterface;
use Throwable;

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
 * @spec openspec/specs/registry-subscription-connector/spec.md#requirement-a-subscription-request-is-turned-into-a-live-subscription-req-rsc-002
 */
class SubscriptionRequestHandler {
	/**
	 * Constructor.
	 *
	 * @param SubscriptionRegistry $registry The bindings.
	 * @param SubscriptionRoster $roster Which identities are followed.
	 * @param RegistryUpdateClient $updateClient The inbound update endpoint.
	 * @param LoggerInterface $logger Structured logger.
	 * @param SchemaMapper|null $schemaMapper Resolves the requesting object's schema id to its slug.
	 */
	public function __construct(
		private readonly SubscriptionRegistry $registry,
		private readonly SubscriptionRoster $roster,
		private readonly RegistryUpdateClient $updateClient,
		private readonly LoggerInterface $logger,
		private readonly ?SchemaMapper $schemaMapper = null,
	) {
	}//end __construct()

	/**
	 * Handle one subscription request.
	 *
	 * @param array<string,mixed> $request The request payload, carrying a registry id and an identity value.
	 *
	 * @return SubscriptionResult|null The result, or null when the request named a registry nothing answers to.
	 *
	 * @spec openspec/specs/registry-subscription-connector/spec.md#requirement-a-subscription-request-is-turned-into-a-live-subscription-req-rsc-002
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

			$schema = (string)($request['schema'] ?? '');
			if ($schema !== '') {
				$this->roster->addTarget(
					registryId: $registryId,
					identity: $identity,
					targetSchema: $this->schemaSlug(schema: $schema)
				);
			}
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

	/**
	 * The slug of the requesting object's schema, which is what a provider's
	 * field map is keyed by. When it does not resolve, the value as given:
	 * that target then receives the source's own names, as before maps.
	 *
	 * @param string $schema The schema id (or slug) from the request.
	 *
	 * @return string The slug, or the value as given.
	 *
	 * @spec openspec/changes/registry-update-maps-source-fields/specs/registry-subscription-connector/spec.md#requirement-a-change-is-posted-in-each-target-schemas-own-property-names-req-rsc-004
	 */
	private function schemaSlug(string $schema): string {
		if ($this->schemaMapper === null) {
			return $schema;
		}

		try {
			$slug = (string)($this->schemaMapper->find($schema)?->getSlug() ?? '');
		} catch (Throwable $e) {
			$this->logger->warning(
				'registry-subscription.request.schema-unresolved',
				[
					'schema' => $schema,
					'error' => $e->getMessage(),
				]
			);
			return $schema;
		}

		if ($slug === '') {
			return $schema;
		}

		return $slug;
	}//end schemaSlug()
}//end class

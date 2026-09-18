<?php

/**
 * Registering a public-law restriction against a property.
 *
 * @category Adapter
 * @package  OCA\Integriq\Gateway\Adapter
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

namespace OCA\Integriq\Gateway\Adapter;

use OCA\Integriq\Gateway\GatewayDelivery;
use OCA\Integriq\Gateway\GatewayTransport;
use OCA\Integriq\PropertySource\Exception\PropertySourceException;
use OCA\Integriq\PropertySource\PropertySourceResolver;

/**
 * A restriction is registered against a property, so the property reference
 * has to resolve first. It is resolved through the `bag` property source,
 * rather than through a second lookup written here.
 *
 * @spec openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-a-wkpb-restriction-is-registered-through-a-gateway-req-sg-009
 */
class WkpbGateway {
	/**
	 * The gateway id.
	 */
	public const ID = 'wkpb';

	/**
	 * Constructor.
	 *
	 * @param GatewayTransport $transport The shared delivery machinery.
	 * @param PropertySourceResolver $propertySource The resolver that checks a property reference.
	 */
	public function __construct(
		private readonly GatewayTransport $transport,
		private readonly PropertySourceResolver $propertySource,
	) {
	}//end __construct()

	/**
	 * Register one restriction.
	 *
	 * @param string $propertyReference The property the restriction sits on.
	 * @param array<string,mixed> $restriction The restriction.
	 * @param array<string,mixed> $config The gateway's configuration.
	 *
	 * @return GatewayDelivery What happened.
	 */
	public function register(string $propertyReference, array $restriction, array $config = []): GatewayDelivery {
		if ($propertyReference === '') {
			return GatewayDelivery::notSent(self::ID, 'A restriction needs a property reference. Nothing was sent.');
		}

		foreach (['restrictionType', 'decisionDate', 'decisionReference'] as $field) {
			if ((string)($restriction[$field] ?? '') === '') {
				return GatewayDelivery::notSent(
					self::ID,
					sprintf('The restriction names no "%s". Nothing was sent.', $field)
				);
			}
		}

		if ($this->resolves($propertyReference) === false) {
			return GatewayDelivery::notSent(
				self::ID,
				sprintf('The property reference "%s" does not resolve. Nothing was sent.', $propertyReference)
			);
		}

		return $this->transport->send(
			self::ID,
			['property' => $propertyReference, 'restriction' => $restriction],
			$config
		);
	}//end register()

	/**
	 * Whether a property reference resolves at the BAG.
	 *
	 * @param string $propertyReference The reference.
	 *
	 * @return bool True when it resolves to a value.
	 */
	private function resolves(string $propertyReference): bool {
		try {
			$resolved = $this->propertySource->resolve('bag', $propertyReference);
		} catch (PropertySourceException $e) {
			return false;
		}

		return ($resolved->getValue() !== null);
	}//end resolves()
}//end class

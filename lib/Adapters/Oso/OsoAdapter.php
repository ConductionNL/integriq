<?php

/**
 * Integriq — OSO adapter catalogue descriptor.
 *
 * ADR-017 Rule 1: a new adapter family ships as a CARD in the *Adapters*
 * catalogue plus a configuration schema — never as a new top-level menu item
 * or a `/beheer` route. This descriptor is that catalogue entry for OSO
 * (Overstapservice Onderwijs, Kennisnet): it declares the adapter's
 * identity, category, the provider bindings it offers, and the JSON
 * configuration schema a Verbinding (source) fills in to select it.
 *
 * Live traffic over the `kennisnet` binding is gated on Kennisnet's OSO
 * aansluiting approval (M3(c), open in
 * `market-intelligence/learniq/decisions.md`).
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\Oso
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\Oso;

use OCA\Integriq\Adapters\CatalogueAdapter;

/**
 * Catalogue descriptor for the OSO adapter (ADR-017 Rule 1).
 *
 * @spec openspec/specs/oso-adapter/spec.md
 *
 * @SuppressWarnings(PHPMD.ShortMethodName)
 */
final class OsoAdapter extends CatalogueAdapter {

	/**
	 * Stable adapter id.
	 *
	 * @var string
	 */
	public const ID = 'oso';

	/**
	 * Live Kennisnet provider binding.
	 *
	 * @var string
	 */
	public const PROVIDER_KENNISNET = 'kennisnet';

	/**
	 * Catalogue id.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/oso-adapter/spec.md
	 */
	public function id(): string {
		return self::ID;
	}//end id()

	/**
	 * Human-readable catalogue label.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/oso-adapter/spec.md
	 */
	public function label(): string {
		return 'OSO';
	}//end label()

	/**
	 * The provider bindings this adapter offers.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/oso-adapter/spec.md#requirement-req-001-oso-export-provider-abstraction-with-log-and-kennisnet-bindings
	 */
	public function providers(): array {
		return [self::PROVIDER_LOG, self::PROVIDER_KENNISNET];
	}//end providers()

	/**
	 * The configuration schema a Verbinding fills in to use this adapter.
	 *
	 * @return array<string, mixed> A JSON-schema fragment.
	 *
	 * @spec openspec/specs/oso-adapter/spec.md#requirement-req-001-oso-export-provider-abstraction-with-log-and-kennisnet-bindings
	 */
	public function configSchema(): array {
		return [
			'type' => 'object',
			'title' => 'OSO',
			'properties' => [
				'provider' => $this->providerSetting(
					providers: $this->providers(),
					description: '`log` (default) simulates every export. `kennisnet` dispatches over the '
						. 'live OSO koppelvlak. It requires a certificate reference and Kennisnet OSO '
						. 'aansluiting approval (see decisions.md M3(c)).'
				),
				'endpoint' => $this->endpointSetting(
					description: 'Kennisnet OSO export endpoint URL. Required when provider=kennisnet.'
				),
				'certificateRef' => $this->certificateSetting(
					description: 'Broker credentialRef for the certificate. Never stored here (ADR-007). '
						. 'Required when provider=kennisnet.'
				),
				'webhookSignature' => $this->signatureSetting(
					title: 'Inbound signature',
					description: 'HMAC verification settings for the inbound import and export-retour endpoints.'
				),
			],
		];

	}//end configSchema()
}//end class

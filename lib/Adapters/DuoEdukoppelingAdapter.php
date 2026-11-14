<?php

/**
 * Integriq — common answers of a DUO adapter over Edukoppeling.
 *
 * The DUO ROD and DUO Verzuimloket descriptors offer the same two bindings
 * (`log` and `edukoppeling`) and the same configuration schema, apart from
 * the koppelvlak's name. Live traffic over `edukoppeling` is gated on the DUO
 * software-vendor certificate holder decision (M3(c), open in
 * `market-intelligence/learniq/decisions.md`).
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters
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

namespace OCA\Integriq\Adapters;

/**
 * Base of the DUO catalogue descriptors whose live binding is Edukoppeling.
 *
 * @spec openspec/specs/rod-adapter/spec.md#requirement-req-001-rod-provider-abstraction-with-log-and-edukoppeling-bindings
 * @spec openspec/specs/verzuimloket-adapter/spec.md#requirement-req-001-verzuimloket-provider-abstraction-with-log-and-edukoppeling-bindings
 */
abstract class DuoEdukoppelingAdapter extends CatalogueAdapter {

	/**
	 * Live Edukoppeling (Digikoppeling WUS) provider binding.
	 *
	 * @var string
	 */
	public const PROVIDER_EDUKOPPELING = 'edukoppeling';

	/**
	 * Human-readable catalogue label, which names the koppelvlak.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/rod-adapter/spec.md
	 */
	abstract public function label(): string;

	/**
	 * The provider bindings this adapter offers.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/rod-adapter/spec.md#requirement-req-001-rod-provider-abstraction-with-log-and-edukoppeling-bindings
	 */
	public function providers(): array {
		return [self::PROVIDER_LOG, self::PROVIDER_EDUKOPPELING];
	}//end providers()

	/**
	 * The configuration schema a Verbinding fills in to use this adapter.
	 *
	 * @return array<string, mixed> A JSON-schema fragment.
	 *
	 * @spec openspec/specs/rod-adapter/spec.md#requirement-req-001-rod-provider-abstraction-with-log-and-edukoppeling-bindings
	 */
	public function configSchema(): array {
		$label = $this->label();

		return [
			'type' => 'object',
			'title' => $label,
			'properties' => [
				'provider' => $this->providerSetting(
					providers: $this->providers(),
					description: '`log` (default) simulates every send. `edukoppeling` dispatches over the '
						. 'live ' . $label . ' koppelvlak. It requires a DUO software-vendor certificate reference and '
						. 'is gated until that certificate is held (see decisions.md M3(c)).'
				),
				'endpoint' => $this->endpointSetting(
					description: $label . ' Edukoppeling endpoint URL. Required when provider=edukoppeling.'
				),
				'certificateRef' => $this->certificateSetting(
					description: 'Broker credentialRef for the DUO software-vendor certificate. Never stored '
						. 'here (ADR-007). Required when provider=edukoppeling.'
				),
				'webhookSignature' => $this->signatureSetting(
					title: 'Retour signature',
					description: 'HMAC verification settings for the inbound DUO acknowledgement/retour.'
				),
			],
		];

	}//end configSchema()
}//end class

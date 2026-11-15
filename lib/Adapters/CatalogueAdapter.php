<?php

/**
 * Integriq — common answers of an adapter catalogue descriptor.
 *
 * ADR-017 Rule 1: a new adapter family ships as a CARD in the *Adapters*
 * catalogue plus a configuration schema — never as a new top-level menu item
 * or a `/beheer` route. Every government adapter descriptor answers the
 * category and the two "adds no menu, adds no route" flags the same way, and
 * the connection-based ones describe their provider, endpoint, certificate and
 * inbound-signature settings with the same schema fragments. Those answers
 * live here once.
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
 * Base of the government adapter catalogue descriptors (ADR-017 Rule 1).
 *
 * @spec openspec/specs/digikoppeling-adapter/spec.md
 */
abstract class CatalogueAdapter {

	/**
	 * Sandbox/mock provider binding, the default of every provider setting.
	 *
	 * @var string
	 */
	public const PROVIDER_LOG = 'log';

	/**
	 * Adapters catalogue category.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/digikoppeling-adapter/spec.md
	 */
	public function category(): string {
		return 'government';
	}//end category()

	/**
	 * ADR-017 Rule 1: an adapter family adds NO top-level menu.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/digikoppeling-adapter/spec.md
	 */
	public function addsTopLevelMenu(): bool {
		return false;
	}//end addsTopLevelMenu()

	/**
	 * ADR-017 Rule 1: an adapter family adds NO per-adapter /beheer route.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/digikoppeling-adapter/spec.md
	 */
	public function addsManagementRoute(): bool {
		return false;
	}//end addsManagementRoute()

	/**
	 * The `provider` setting: one of the bindings, `log` by default.
	 *
	 * @param list<string> $providers   The provider bindings the adapter offers.
	 * @param string       $description What each binding does.
	 *
	 * @return array<string, mixed> A JSON-schema fragment.
	 */
	protected function providerSetting(array $providers, string $description): array {
		return [
			'type' => 'string',
			'enum' => $providers,
			'default' => self::PROVIDER_LOG,
			'title' => 'Provider binding',
			'description' => $description,
		];

	}//end providerSetting()

	/**
	 * The `endpoint` setting: the live koppelvlak's URL.
	 *
	 * @param string $description Which endpoint, and when it is required.
	 *
	 * @return array<string, mixed> A JSON-schema fragment.
	 */
	protected function endpointSetting(string $description): array {
		return [
			'type' => 'string',
			'format' => 'uri',
			'title' => 'Endpoint URL',
			'description' => $description,
		];

	}//end endpointSetting()

	/**
	 * The `certificateRef` setting: the broker reference of the PKIoverheid certificate.
	 *
	 * @param string $description Which certificate, and when it is required.
	 *
	 * @return array<string, mixed> A JSON-schema fragment.
	 */
	protected function certificateSetting(string $description): array {
		return [
			'type' => 'string',
			'title' => 'PKIoverheid certificate reference',
			'description' => $description,
		];

	}//end certificateSetting()

	/**
	 * The `webhookSignature` setting: how an inbound message is verified.
	 *
	 * @param string $title       The setting's title.
	 * @param string $description Which inbound messages it verifies.
	 *
	 * @return array<string, mixed> A JSON-schema fragment.
	 */
	protected function signatureSetting(string $title, string $description): array {
		return [
			'type' => 'object',
			'title' => $title,
			'description' => $description,
			'properties' => [
				'scheme' => ['type' => 'string', 'default' => 'openconnector'],
				'secret' => ['type' => 'string'],
				'header' => ['type' => 'string', 'default' => 'X-OpenConnector-Signature'],
				'toleranceSeconds' => ['type' => 'integer'],
			],
		];

	}//end signatureSetting()
}//end class

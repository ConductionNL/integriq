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
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\Oso;

/**
 * Catalogue descriptor for the OSO adapter (ADR-017 Rule 1).
 *
 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md
 *
 * @SuppressWarnings(PHPMD.ShortMethodName)
 */
final class OsoAdapter {

	/**
	 * Stable adapter id.
	 *
	 * @var string
	 */
	public const ID = 'oso';

	/**
	 * Sandbox/mock provider binding.
	 *
	 * @var string
	 */
	public const PROVIDER_LOG = 'log';

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
	 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md
	 */
	public function id(): string {
		return self::ID;
	}//end id()

	/**
	 * Human-readable catalogue label.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md
	 */
	public function label(): string {
		return 'OSO';
	}//end label()

	/**
	 * Adapters catalogue category.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md
	 */
	public function category(): string {
		return 'government';
	}//end category()

	/**
	 * ADR-017 Rule 1: an adapter family adds NO top-level menu.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md
	 */
	public function addsTopLevelMenu(): bool {
		return false;
	}//end addsTopLevelMenu()

	/**
	 * ADR-017 Rule 1: an adapter family adds NO per-adapter /beheer route.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md
	 */
	public function addsManagementRoute(): bool {
		return false;
	}//end addsManagementRoute()

	/**
	 * The provider bindings this adapter offers.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#requirement-req-001-oso-export-provider-abstraction-with-log-and-kennisnet-bindings
	 */
	public function providers(): array {
		return [self::PROVIDER_LOG, self::PROVIDER_KENNISNET];
	}//end providers()

	/**
	 * The configuration schema a Verbinding fills in to use this adapter.
	 *
	 * @return array<string, mixed> A JSON-schema fragment.
	 *
	 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#requirement-req-001-oso-export-provider-abstraction-with-log-and-kennisnet-bindings
	 */
	public function configSchema(): array {
		return [
			'type' => 'object',
			'title' => 'OSO',
			'properties' => [
				'provider' => [
					'type' => 'string',
					'enum' => [self::PROVIDER_LOG, self::PROVIDER_KENNISNET],
					'default' => self::PROVIDER_LOG,
					'title' => 'Provider binding',
					'description' => '`log` (default) simulates every export. `kennisnet` dispatches over the '
						. 'live OSO koppelvlak. It requires a certificate reference and Kennisnet OSO '
						. 'aansluiting approval (see decisions.md M3(c)).',
				],
				'endpoint' => [
					'type' => 'string',
					'format' => 'uri',
					'title' => 'Endpoint URL',
					'description' => 'Kennisnet OSO export endpoint URL. Required when provider=kennisnet.',
				],
				'certificateRef' => [
					'type' => 'string',
					'title' => 'PKIoverheid certificate reference',
					'description' => 'Broker credentialRef for the certificate. Never stored here (ADR-007). '
						. 'Required when provider=kennisnet.',
				],
				'webhookSignature' => [
					'type' => 'object',
					'title' => 'Inbound signature',
					'description' => 'HMAC verification settings for the inbound import and export-retour endpoints.',
					'properties' => [
						'scheme' => ['type' => 'string', 'default' => 'openconnector'],
						'secret' => ['type' => 'string'],
						'header' => ['type' => 'string', 'default' => 'X-OpenConnector-Signature'],
						'toleranceSeconds' => ['type' => 'integer'],
					],
				],
			],
		];

	}//end configSchema()
}//end class

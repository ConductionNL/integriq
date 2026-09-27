<?php

/**
 * Integriq — DUO ROD adapter catalogue descriptor.
 *
 * ADR-017 Rule 1: a new adapter family ships as a CARD in the *Adapters*
 * catalogue plus a configuration schema — never as a new top-level menu item
 * or a `/beheer` route. This descriptor is that catalogue entry for DUO ROD
 * (Register Onderwijsdeelnemers): it declares the adapter's identity,
 * category, the provider bindings it offers, and the JSON configuration
 * schema a Verbinding (source) fills in to select it.
 *
 * Live traffic over the `edukoppeling` binding is gated on the DUO
 * software-vendor certificate holder decision (M3(c), open in
 * `market-intelligence/learniq/decisions.md`) — an operational/governance
 * gate, not a reason this card is absent from the catalogue.
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\Rod
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

namespace OCA\Integriq\Adapters\Rod;

/**
 * Catalogue descriptor for the DUO ROD adapter (ADR-017 Rule 1).
 *
 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md
 *
 * @SuppressWarnings(PHPMD.ShortMethodName)
 */
final class RodAdapter {

	/**
	 * Stable adapter id.
	 *
	 * @var string
	 */
	public const ID = 'rod';

	/**
	 * Sandbox/mock provider binding.
	 *
	 * @var string
	 */
	public const PROVIDER_LOG = 'log';

	/**
	 * Live Edukoppeling (Digikoppeling WUS) provider binding.
	 *
	 * @var string
	 */
	public const PROVIDER_EDUKOPPELING = 'edukoppeling';

	/**
	 * Catalogue id.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md
	 */
	public function id(): string {
		return self::ID;
	}//end id()

	/**
	 * Human-readable catalogue label.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md
	 */
	public function label(): string {
		return 'DUO ROD';
	}//end label()

	/**
	 * Adapters catalogue category.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md
	 */
	public function category(): string {
		return 'government';
	}//end category()

	/**
	 * ADR-017 Rule 1: an adapter family adds NO top-level menu.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md
	 */
	public function addsTopLevelMenu(): bool {
		return false;
	}//end addsTopLevelMenu()

	/**
	 * ADR-017 Rule 1: an adapter family adds NO per-adapter /beheer route.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md
	 */
	public function addsManagementRoute(): bool {
		return false;
	}//end addsManagementRoute()

	/**
	 * The provider bindings this adapter offers.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#requirement-req-001-rod-provider-abstraction-with-log-and-edukoppeling-bindings
	 */
	public function providers(): array {
		return [self::PROVIDER_LOG, self::PROVIDER_EDUKOPPELING];
	}//end providers()

	/**
	 * The configuration schema a Verbinding fills in to use this adapter.
	 *
	 * @return array<string, mixed> A JSON-schema fragment.
	 *
	 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#requirement-req-001-rod-provider-abstraction-with-log-and-edukoppeling-bindings
	 */
	public function configSchema(): array {
		return [
			'type' => 'object',
			'title' => 'DUO ROD',
			'properties' => [
				'provider' => [
					'type' => 'string',
					'enum' => [self::PROVIDER_LOG, self::PROVIDER_EDUKOPPELING],
					'default' => self::PROVIDER_LOG,
					'title' => 'Provider binding',
					'description' => '`log` (default) simulates every send. `edukoppeling` dispatches over the '
						. 'live DUO ROD koppelvlak. It requires a DUO software-vendor certificate reference and '
						. 'is gated until that certificate is held (see decisions.md M3(c)).',
				],
				'endpoint' => [
					'type' => 'string',
					'format' => 'uri',
					'title' => 'Endpoint URL',
					'description' => 'DUO ROD Edukoppeling endpoint URL. Required when provider=edukoppeling.',
				],
				'certificateRef' => [
					'type' => 'string',
					'title' => 'PKIoverheid certificate reference',
					'description' => 'Broker credentialRef for the DUO software-vendor certificate. Never stored '
						. 'here (ADR-007). Required when provider=edukoppeling.',
				],
				'webhookSignature' => [
					'type' => 'object',
					'title' => 'Retour signature',
					'description' => 'HMAC verification settings for the inbound DUO acknowledgement/retour.',
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

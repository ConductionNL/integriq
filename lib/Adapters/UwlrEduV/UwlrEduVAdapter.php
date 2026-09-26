<?php

/**
 * Integriq — UWLR/Edu-V/Basispoort/Entree-content adapter catalogue descriptor.
 *
 * ADR-017 Rule 1: a new adapter family ships as a CARD in the *Adapters*
 * catalogue plus a configuration schema — never as a new top-level menu item
 * or a `/beheer` route. This descriptor is that catalogue entry for the
 * shared UWLR, Edu-V, Basispoort and Entree-content connection family
 * (Kennisnet): it declares the adapter's identity, category, the provider
 * bindings it offers, and the JSON configuration schema a Verbinding
 * (source) fills in to select it.
 *
 * Live traffic over the `uwlr-eduv` binding is gated on each target's own
 * certification/aansluiting: Edu-V keurmerk (per data service), Basispoort
 * connection agreement, UWLR access agreement — all open in
 * `market-intelligence/learniq/decisions.md` M3(c).
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\UwlrEduV
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

namespace OCA\Integriq\Adapters\UwlrEduV;

/**
 * Catalogue descriptor for the UWLR/Edu-V/Basispoort/Entree-content adapter (ADR-017 Rule 1).
 *
 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md
 *
 * @SuppressWarnings(PHPMD.ShortMethodName)
 */
final class UwlrEduVAdapter {

	/**
	 * Stable adapter id.
	 *
	 * @var string
	 */
	public const ID = 'uwlr-eduv';

	/**
	 * Sandbox/mock provider binding.
	 *
	 * @var string
	 */
	public const PROVIDER_LOG = 'log';

	/**
	 * Live Kennisnet-adjacent provider binding.
	 *
	 * @var string
	 */
	public const PROVIDER_UWLR_EDUV = 'uwlr-eduv';

	/**
	 * Catalogue id.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md
	 */
	public function id(): string {
		return self::ID;
	}//end id()

	/**
	 * Human-readable catalogue label.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md
	 */
	public function label(): string {
		return 'UWLR / Edu-V / Basispoort';
	}//end label()

	/**
	 * Adapters catalogue category.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md
	 */
	public function category(): string {
		return 'government';
	}//end category()

	/**
	 * ADR-017 Rule 1: an adapter family adds NO top-level menu.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md
	 */
	public function addsTopLevelMenu(): bool {
		return false;
	}//end addsTopLevelMenu()

	/**
	 * ADR-017 Rule 1: an adapter family adds NO per-adapter /beheer route.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md
	 */
	public function addsManagementRoute(): bool {
		return false;
	}//end addsManagementRoute()

	/**
	 * The provider bindings this adapter offers.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#requirement-req-001-shared-provider-abstraction-with-log-and-uwlr-eduv-bindings
	 */
	public function providers(): array {
		return [self::PROVIDER_LOG, self::PROVIDER_UWLR_EDUV];
	}//end providers()

	/**
	 * The four data-exchange targets this adapter serves.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md
	 */
	public function targets(): array {
		return ['uwlr', 'edu-v', 'basispoort', 'entree-content'];
	}//end targets()

	/**
	 * The configuration schema a Verbinding fills in to use this adapter.
	 *
	 * @return array<string, mixed> A JSON-schema fragment.
	 *
	 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#requirement-req-001-shared-provider-abstraction-with-log-and-uwlr-eduv-bindings
	 */
	public function configSchema(): array {
		return [
			'type' => 'object',
			'title' => 'UWLR / Edu-V / Basispoort',
			'properties' => [
				'provider' => [
					'type' => 'string',
					'enum' => [self::PROVIDER_LOG, self::PROVIDER_UWLR_EDUV],
					'default' => self::PROVIDER_LOG,
					'title' => 'Provider binding',
					'description' => '`log` (default) simulates every send. `uwlr-eduv` dispatches over the '
						. 'live koppelvlak. It requires a certificate reference and, per target, its own '
						. 'certification/aansluiting (see decisions.md M3(c)).',
				],
				'endpoint' => [
					'type' => 'string',
					'format' => 'uri',
					'title' => 'Endpoint URL',
					'description' => 'UWLR/Edu-V/Basispoort/Entree-content koppelvlak endpoint URL. Required '
						. 'when provider=uwlr-eduv.',
				],
				'certificateRef' => [
					'type' => 'string',
					'title' => 'PKIoverheid certificate reference',
					'description' => 'Broker credentialRef for the certificate. Never stored here (ADR-007). '
						. 'Required when provider=uwlr-eduv.',
				],
				'webhookSignature' => [
					'type' => 'object',
					'title' => 'Inbound signature',
					'description' => 'HMAC verification settings for the shared inbound acknowledgement/retour endpoint.',
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

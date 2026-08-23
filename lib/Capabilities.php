<?php

/**
 * Integriq Capabilities.
 *
 * Contributes `{ dashboard_http_datasource: { name, version, enabled } }` to
 * the Nextcloud capabilities document so a leaf dashboard/widget host (e.g.
 * LaunchPad's `live-data-tile-widget`) can probe for the
 * `dashboard-http-datasource` façade at runtime and degrade cleanly when
 * Integriq is absent or disabled — see
 * openspec/changes/dashboard-http-datasource/specs/dashboard-http-datasource/spec.md
 * "Requirement: dashboard-http-datasource capability is advertised for leaf
 * probing".
 *
 * `enabled` is always `true` when this capability is contributed at all —
 * the OCS capabilities endpoint only ever calls into an app's registered
 * `ICapability` when that app is enabled, so a disabled/absent Integriq
 * naturally omits the whole `dashboard_http_datasource` key rather than
 * reporting `enabled: false` (mirrors the "Capability absent" scenario: the
 * leaf's probe simply finds no key at all).
 *
 * @category Capabilities
 * @package  OCA\Integriq
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/dashboard-http-datasource/specs/dashboard-http-datasource/spec.md#requirement-dashboard-http-datasource-capability-is-advertised-for-leaf-probing
 */

declare(strict_types=1);

namespace OCA\Integriq;

use OCP\Capabilities\ICapability;

/**
 * Advertises the `dashboard-http-datasource` capability.
 *
 * @spec openspec/changes/dashboard-http-datasource/specs/dashboard-http-datasource/spec.md#requirement-dashboard-http-datasource-capability-is-advertised-for-leaf-probing
 */
class Capabilities implements ICapability {

	/**
	 * Semantic version of the `dashboard-http-datasource` façade contract
	 * (resolve endpoint request/response shape). Bump on a breaking change
	 * to the `{ valueExpr, params?, ttl? }` request or the
	 * `{ value, fetchedAt, stale }` response so a leaf app's probe can
	 * feature-detect a contract it doesn't yet understand.
	 *
	 * @var string
	 */
	private const CAPABILITY_VERSION = '1.0.0';

	/**
	 * Return Integriq's capabilities block.
	 *
	 * @return array<string, array<string, mixed>> The capabilities document fragment.
	 *
	 * @spec openspec/changes/dashboard-http-datasource/specs/dashboard-http-datasource/spec.md#requirement-dashboard-http-datasource-capability-is-advertised-for-leaf-probing
	 */
	public function getCapabilities(): array {
		return [
			'dashboard_http_datasource' => [
				'name' => 'dashboard-http-datasource',
				'version' => self::CAPABILITY_VERSION,
				'enabled' => true,
			],
		];

	}//end getCapabilities()
}//end class

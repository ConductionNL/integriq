<?php

/**
 * Integriq provider ids.
 *
 * The two id rules every provider registry applies: an empty id falls back
 * to the `log` binding, and a refusal lists the registered ids, or
 * `(none)` when nothing is registered.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Helper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Helper;

/**
 * Resolves and lists provider ids for the provider registries.
 *
 * @spec openspec/specs/rod-adapter/spec.md#requirement-req-001-rod-provider-abstraction-with-log-and-edukoppeling-bindings
 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md#requirement-one-provider-seam-with-log-berichtenbox-and-postex-bindings-req-dpa-001
 */
final class ProviderIds {

	/**
	 * The id itself, or `log` when it is empty.
	 *
	 * @param string $providerId Provider id.
	 *
	 * @return string The id to look up.
	 *
	 * @spec openspec/specs/rod-adapter/spec.md#requirement-req-001-rod-provider-abstraction-with-log-and-edukoppeling-bindings
	 */
	public static function orLog(string $providerId): string {
		if ($providerId === '') {
			return 'log';
		}

		return $providerId;
	}//end orLog()

	/**
	 * The registered ids as a refusal lists them.
	 *
	 * @param array<int, string> $ids Registered provider ids.
	 *
	 * @return string The comma-separated ids, or `(none)`.
	 *
	 * @spec openspec/specs/rod-adapter/spec.md#requirement-req-001-rod-provider-abstraction-with-log-and-edukoppeling-bindings
	 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md#requirement-one-provider-seam-with-log-berichtenbox-and-postex-bindings-req-dpa-001
	 */
	public static function listed(array $ids): string {
		if ($ids === []) {
			return '(none)';
		}

		return implode(', ', $ids);
	}//end listed()
}//end class

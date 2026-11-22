<?php

/**
 * ZgwVersionRange
 *
 * The two ZGW API versions one translation runs between, as resolved from the
 * request body, the `X-ZGW-Version` header, the `Accept` header or the default.
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/zgw-version-translation/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

/**
 * A `fromVersion` and `toVersion` pair.
 *
 * @spec openspec/specs/zgw-version-translation/spec.md
 */
final class ZgwVersionRange {

	/**
	 * Constructor.
	 *
	 * @param string $fromVersion The version the payload is in.
	 * @param string $toVersion   The version it is translated to.
	 */
	public function __construct(
		public readonly string $fromVersion,
		public readonly string $toVersion,
	) {

	}//end __construct()

	/**
	 * The pair as the translate response names it.
	 *
	 * @return array{fromVersion: string, toVersion: string}
	 *
	 * @spec openspec/specs/zgw-version-translation/spec.md#requirement-rest-surface-for-sibling-apps-and-external-consumers-req-003
	 */
	public function asResponseFields(): array {
		return [
			'fromVersion' => $this->fromVersion,
			'toVersion' => $this->toVersion,
		];

	}//end asResponseFields()
}//end class

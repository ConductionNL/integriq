<?php

/**
 * Integriq OCS API.
 *
 * One sibling Nextcloud app's OCS API as an integriq client calls it: the
 * name refusals and log lines use (`Forms`, `Tables`) and the exception
 * classes its client raises for a permission refusal, a missing resource
 * and any other upstream failure.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Helper
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
 * @spec openspec/specs/nextcloud-tables-connector/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Helper;

/**
 * A sibling app's OCS API and its client's exceptions.
 *
 * @spec openspec/specs/nextcloud-tables-connector/spec.md
 */
final class OcsApi {

	/**
	 * Constructor.
	 *
	 * @param string                    $label            The API's name, as messages use it (`Forms`).
	 * @param class-string<\Exception> $permissionDenied Raised on a 401 or 403, with the status code.
	 * @param class-string<\Exception> $notFound         Raised on a 404.
	 * @param class-string<\Exception> $upstream         Raised on a transport failure or another non-2xx status.
	 */
	public function __construct(
		public readonly string $label,
		public readonly string $permissionDenied,
		public readonly string $notFound,
		public readonly string $upstream,
	) {

	}//end __construct()
}//end class

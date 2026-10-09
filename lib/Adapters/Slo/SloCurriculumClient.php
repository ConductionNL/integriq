<?php

/**
 * Integriq SLO curriculum client (abstract base).
 *
 * Low-level transport to SLO's curriculum REST API
 * (`https://opendata.slo.nl/curriculum/api/v1/`, CC BY 4.0). Concrete
 * flavours:
 *
 *   - {@see SloCurriculumClientMock}: the dormant default. Serves recorded
 *     responses of real SLO data from `slo-curriculum-recorded.json`, never
 *     touches the network.
 *   - {@see SloCurriculumClientHttp}: live, through integriq's `CallService`
 *     and the seeded `slo-curriculum` source (HTTP Basic: the registered
 *     e-mail and API key SLO requires for every JSON call).
 *
 * DI binds the mock unless `slo.curriculum.feature_flag` is `1` or `true`
 * (lib/AppInfo/Application.php).
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\Slo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-the-client-is-a-mock-by-default-and-live-only-behind-the-flag-req-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\Slo;

use OCA\Integriq\Exception\SloCurriculumException;

/**
 * Abstract SLO curriculum client: one GET, one raw body.
 *
 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-the-client-is-a-mock-by-default-and-live-only-behind-the-flag-req-003
 */
abstract class SloCurriculumClient {
	/**
	 * Which binding handles calls: `mock` or `https`.
	 *
	 * @return string The flavour.
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-the-client-is-a-mock-by-default-and-live-only-behind-the-flag-req-003
	 */
	abstract public function flavour(): string;

	/**
	 * GET one path under the API base and return the raw body.
	 *
	 * @param string $path Path under `.../api/v1/`, such as `tree/<uuid>` or `fo_kerndoelen/`.
	 * @param array<string,scalar> $query Query parameters.
	 * @param string $accept The Accept header: `application/json` or `application/jsontag`.
	 *
	 * @return string The response body.
	 *
	 * @throws SloCurriculumException On an error status, a transport failure, or (mock) an unrecorded request.
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-the-client-is-a-mock-by-default-and-live-only-behind-the-flag-req-003
	 */
	abstract public function fetch(string $path, array $query = [], string $accept = 'application/json'): string;

	/**
	 * The canonical key of a request: path without leading slash, then the
	 * query sorted by name.
	 *
	 * @param string $path The path.
	 * @param array<string,scalar> $query The query parameters.
	 *
	 * @return string Such as `examenprogramma?page=0&perPage=1000`.
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-the-client-is-a-mock-by-default-and-live-only-behind-the-flag-req-003
	 */
	public static function requestKey(string $path, array $query = []): string {
		$key = ltrim($path, '/');
		if ($query === []) {
			return $key;
		}

		ksort($query);
		return $key . '?' . http_build_query($query);
	}//end requestKey()
}//end class

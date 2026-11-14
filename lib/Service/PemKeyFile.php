<?php

/**
 * Integriq PemKeyFile.
 *
 * Reads a PEM key as a signing JWK the way every key holder here does
 * (#1012a): through a tempnam() file, so the name is unpredictable, chmod
 * 0600 before and after the write, so the bytes are never readable to other
 * local users, and a finally that removes the file even when the key cannot
 * be parsed. Without a temp file it answers null, so each caller keeps its
 * own refusal.
 *
 * @category Service
 * @package  OCA\Integriq\Service
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
 * @spec openspec/specs/authentication-twig/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;

/**
 * Reads a PEM key through a private temp file.
 *
 * @spec openspec/specs/authentication-twig/spec.md
 */
final class PemKeyFile {

	/**
	 * The PEM as a `use: sig` JWK, or null when no temp file could be allocated.
	 *
	 * @param PemKeyFileRequest $request The PEM, owner prefix, kid and algorithm.
	 *
	 * @return JWK|null The JWK, or null.
	 *
	 * @spec openspec/specs/authentication-twig/spec.md
	 */
	public static function jwk(PemKeyFileRequest $request): ?JWK {
		$filename = tempnam(sys_get_temp_dir(), $request->prefix);
		if ($filename === false) {
			return null;
		}

		@chmod($filename, 0600);
		file_put_contents($filename, $request->pem);
		@chmod($filename, 0600);

		try {
			return JWKFactory::createFromKeyFile($filename, null, self::parameters(request: $request));
		} finally {
			if (file_exists($filename) === true) {
				@unlink($filename);
			}
		}
	}//end jwk()

	/**
	 * The JWK parameters, in the order the key holders gave them.
	 *
	 * @param PemKeyFileRequest $request The request.
	 *
	 * @return array<string, string>
	 */
	private static function parameters(PemKeyFileRequest $request): array {
		$parameters = [];
		if ($request->kid !== null) {
			$parameters['kid'] = $request->kid;
		}

		if ($request->algorithm !== null) {
			$parameters['alg'] = $request->algorithm;
		}

		$parameters['use'] = 'sig';

		return $parameters;
	}//end parameters()
}//end class

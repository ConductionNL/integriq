<?php

/**
 * Integriq PemKeyFileRequest.
 *
 * One PEM key to read as a JWK: the temp-file prefix that names its owner,
 * the PEM itself, and the kid and algorithm the JWK carries when it has them.
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

/**
 * The PEM, owner prefix, kid and algorithm of one key read.
 *
 * @spec openspec/specs/authentication-twig/spec.md
 */
final class PemKeyFileRequest {

	/**
	 * Constructor.
	 *
	 * @param string      $prefix    The temp-file name prefix, e.g. `oc-lti-key-`.
	 * @param string      $pem       The PEM key.
	 * @param string|null $kid       The JWK's kid, or null for none.
	 * @param string|null $algorithm The JWK's alg, or null for none.
	 */
	public function __construct(
		public readonly string $prefix,
		public readonly string $pem,
		public readonly ?string $kid = null,
		public readonly ?string $algorithm = null,
	) {
	}//end __construct()
}//end class

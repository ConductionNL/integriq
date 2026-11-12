<?php

/**
 * Integriq id_token verification run.
 *
 * The state of one LtiLaunchService::verifyIdTokenSignature() call while
 * its checks run in order: the parsed JWS and the registration type it is
 * verified against, then the header `kid`/`alg`, the decoded payload and the
 * registration resolved from the (unverified) issuer as each check fills
 * them in. Never leaves LtiLaunchService.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Lti
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/lti-platform/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Lti;

use Jose\Component\Signature\JWS;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * One id_token verification in progress.
 *
 * @spec openspec/specs/lti-platform/spec.md
 */
final class IdTokenVerificationRun {

	/**
	 * The protected header's `kid`, as presented (not yet trusted).
	 *
	 * @var mixed
	 */
	public mixed $kid = null;

	/**
	 * The protected header's `alg`, as presented (not yet trusted).
	 *
	 * @var mixed
	 */
	public mixed $alg = null;

	/**
	 * The decoded (not yet verified) payload.
	 *
	 * @var array
	 */
	public array $payload = [];

	/**
	 * The registration resolved from the payload's `iss`.
	 *
	 * @var ObjectEntity|null
	 */
	public ?ObjectEntity $registration = null;

	/**
	 * Constructor.
	 *
	 * @param JWS    $jws              The parsed id_token.
	 * @param string $registrationType `lti_platform` or `lti_tool`.
	 */
	public function __construct(
		public readonly JWS $jws,
		public readonly string $registrationType,
	) {

	}//end __construct()
}//end class

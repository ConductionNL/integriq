<?php

/**
 * Integriq launch validation run.
 *
 * The values the later steps of LtiLaunchService::validateLaunch() share once
 * the id_token signature is verified: the verified payload, the platform
 * registration it was verified against, and the deployment the launch was
 * posted to. Never leaves LtiLaunchService.
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

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * One launch validation past its signature check.
 *
 * @spec openspec/specs/lti-platform/spec.md
 */
final class LaunchValidationRun {

	/**
	 * Constructor.
	 *
	 * @param array        $payload    The verified id_token payload.
	 * @param ObjectEntity $platform   The platform registration the token was verified against.
	 * @param ObjectEntity $deployment The deployment the launch was posted to (the route parameter).
	 */
	public function __construct(
		public readonly array $payload,
		public readonly ObjectEntity $platform,
		public readonly ObjectEntity $deployment,
	) {

	}//end __construct()
}//end class

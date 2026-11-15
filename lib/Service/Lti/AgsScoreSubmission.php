<?php

/**
 * Integriq AgsScoreSubmission.
 *
 * One score a tool posts to a line item: the access token it presents, the
 * deployment it posts for, the line item and the score payload. Built by
 * LtiAgsService::receiveScore() and never handed out.
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

/**
 * A posted score.
 *
 * @spec openspec/specs/lti-platform/spec.md
 */
final class AgsScoreSubmission {

	/**
	 * The line item the score is for.
	 *
	 * @var string
	 */
	public string $lineItemId = '';

	/**
	 * The AGS Score payload.
	 *
	 * @var array<string, mixed>
	 */
	public array $payload = [];

	/**
	 * Constructor.
	 *
	 * @param string $accessToken The bearer token the tool presents.
	 * @param string $deploymentUuid The deployment the score is posted for.
	 */
	public function __construct(
		public readonly string $accessToken,
		public readonly string $deploymentUuid,
	) {
	}//end __construct()
}//end class

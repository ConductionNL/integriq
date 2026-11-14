<?php

/**
 * Integriq IdpReadiness.
 *
 * Whether an identity provider registration can serve logins, and why not.
 *
 * @category Auth
 * @package  OCA\Integriq\Auth\Idp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-readiness-is-one-answer-for-the-screen-and-the-runtime-req-idpc-007
 */

declare(strict_types=1);

namespace OCA\Integriq\Auth\Idp;

/**
 * One readiness status of a registration, with reasons that name properties
 * and credential roles, never values (design D6).
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-readiness-is-one-answer-for-the-screen-and-the-runtime-req-idpc-007
 */
final class IdpReadiness {

	public const READY = 'ready';

	public const FLAG_OFF = 'flag-off';

	public const INCOMPLETE = 'incomplete';

	public const SECRET_MISSING = 'secret-missing';

	public const KEEPIQ_MISSING = 'keepiq-missing';

	public const DISABLED = 'disabled';

	/**
	 * Constructor.
	 *
	 * @param string       $status  One of the status constants.
	 * @param list<string> $reasons Why, naming properties and roles only.
	 */
	public function __construct(
		public readonly string $status,
		public readonly array $reasons = [],
	) {

	}//end __construct()

	/**
	 * Whether a live adapter may serve logins.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-readiness-is-one-answer-for-the-screen-and-the-runtime-req-idpc-007
	 */
	public function isReady(): bool {
		return $this->status === self::READY;

	}//end isReady()

	/**
	 * The status and reasons as the settings screen shows them.
	 *
	 * @return array{status: string, reasons: list<string>}
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-readiness-is-one-answer-for-the-screen-and-the-runtime-req-idpc-007
	 */
	public function toArray(): array {
		return ['status' => $this->status, 'reasons' => $this->reasons];

	}//end toArray()
}//end class

<?php

/**
 * Integriq quiet call.
 *
 * Runs a PHP function that reports a failure both by its return value and by
 * a warning, such as the openssl_* readers, keeping the return value and
 * dropping the warning: the caller already handles the failure.
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
 * Runs a call with PHP warnings silenced for its duration only.
 *
 * @spec openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-can-require-a-client-certificate-req-cred-004
 */
final class QuietCall {

	/**
	 * The call's return value; a warning it raises is not reported.
	 *
	 * @param callable $call The call.
	 *
	 * @return mixed
	 *
	 * @spec openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-can-require-a-client-certificate-req-cred-004
	 */
	public function run(callable $call): mixed {
		set_error_handler(static fn (): bool => true);
		try {
			return $call();
		} finally {
			restore_error_handler();
		}
	}//end run()
}//end class

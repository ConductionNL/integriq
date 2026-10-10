<?php

/**
 * Integriq Host Key Mismatch Exception.
 *
 * Raised when an SFTP server presents a host key other than the one pinned on
 * the source. The message names both SHA-256 fingerprints, never a credential.
 *
 * @category Exception
 * @package  OCA\Integriq\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Exception;

use RuntimeException;

/**
 * A changed host key stops the connection (REQ-SFTP-001).
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
 */
class HostKeyMismatchException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string $pinned    The fingerprint pinned on the source.
	 * @param string $presented The fingerprint the server presented.
	 */
	public function __construct(
		public readonly string $pinned,
		public readonly string $presented,
	) {
		parent::__construct(
			message: sprintf(
				'The server presented host key %s, but the source is pinned to %s. The connection was refused.',
				$presented,
				$pinned
			)
		);

	}//end __construct()
}//end class

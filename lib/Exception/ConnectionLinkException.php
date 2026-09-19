<?php

/**
 * Integriq ConnectionLinkException.
 *
 * Thrown when a source cannot be linked to a connection. It carries a reason
 * code rather than prose, so the controller can pick a translated message and
 * an HTTP status for each reason.
 *
 * @category Exception
 * @package  OCA\Integriq\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-add-integration-links-a-source-and-probes-it-at-once-req-conn-007
 */

declare(strict_types=1);

namespace OCA\Integriq\Exception;

use RuntimeException;

/**
 * A refused link, with a reason code.
 *
 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-add-integration-links-a-source-and-probes-it-at-once-req-conn-007
 */
class ConnectionLinkException extends RuntimeException {

	/**
	 * No connection row has the given id.
	 *
	 * @var string
	 */
	public const CONNECTION_NOT_FOUND = 'connection-not-found';

	/**
	 * The connection already has a source.
	 *
	 * @var string
	 */
	public const ALREADY_LINKED = 'already-linked';

	/**
	 * No source has the given id.
	 *
	 * @var string
	 */
	public const SOURCE_NOT_FOUND = 'source-not-found';

	/**
	 * The connection declares no source template.
	 *
	 * @var string
	 */
	public const NO_TEMPLATE = 'no-template';

	/**
	 * The declared source template does not exist.
	 *
	 * @var string
	 */
	public const TEMPLATE_NOT_FOUND = 'template-not-found';

	/**
	 * Constructor.
	 *
	 * @param string $reason One of the reason constants.
	 */
	public function __construct(
		private readonly string $reason,
	) {
		parent::__construct(message: 'Connection link refused: ' . $reason);
	}//end __construct()

	/**
	 * The reason code.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-add-integration-links-a-source-and-probes-it-at-once-req-conn-007
	 */
	public function getReason(): string {
		return $this->reason;
	}//end getReason()
}//end class

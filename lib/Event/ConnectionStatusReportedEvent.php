<?php

/**
 * Integriq ConnectionStatusReported Event.
 *
 * The ADR-041 contract an app uses to report what only it knows about one of
 * its declared connections, such as an IMAP login test or a StUF endpoint's
 * breaker. The sender references this class by string constant and sends
 * nothing when the class is absent. Integriq writes the report to the row's
 * `lastReport` and works the status out itself.
 *
 * @category Event
 * @package  OCA\Integriq\Event
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
 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-apps-report-and-refresh-through-two-typed-events-req-conn-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

use OCP\EventDispatcher\Event;

/**
 * A status report for one declared connection.
 *
 * The shape is fixed by the hydra umbrella design D6. Do not add or rename
 * a constructor argument without changing that contract first: every
 * adopting app constructs this class by name.
 *
 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-apps-report-and-refresh-through-two-typed-events-req-conn-004
 */
final class ConnectionStatusReportedEvent extends Event {
	/**
	 * Constructor.
	 *
	 * @param string $app The declaring app id.
	 * @param string $key The connection key from the app's connections.json.
	 * @param string $status One of configured, limited, unconfigured, simulated, disabled, unavailable, error.
	 * @param string $message A short reason, shown as the status message.
	 */
	public function __construct(
		public readonly string $app,
		public readonly string $key,
		public readonly string $status,
		public readonly string $message = '',
	) {
		parent::__construct();
	}//end __construct()
}//end class

<?php

/**
 * Integriq ConnectionRefreshRequested Event.
 *
 * Sent by an app whose settings save changed a value a connection depends on,
 * such as a `requiredConfig` key or an adapter class. Integriq then works the
 * status out again. The app does not decide the status, integriq does.
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
 * A request to resolve one app's connections again.
 *
 * The shape is fixed by the hydra umbrella design D6.
 *
 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-apps-report-and-refresh-through-two-typed-events-req-conn-004
 */
final class ConnectionRefreshRequestedEvent extends Event {
	/**
	 * Constructor.
	 *
	 * @param string $app The declaring app id.
	 * @param string|null $key One connection key, or null for every connection of the app.
	 */
	public function __construct(
		public readonly string $app,
		public readonly ?string $key = null,
	) {
		parent::__construct();
	}//end __construct()
}//end class

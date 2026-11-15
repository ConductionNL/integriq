<?php

/**
 * Integriq ReplayTarget.
 *
 * The call one replay acts on: its record, who replays it, the mapping version
 * it runs under and the dispatch target it goes to.
 *
 * @category Outbound
 * @package  OCA\Integriq\Outbound\Call
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/specs/outbound-call-log/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Outbound\Call;

/**
 * Value object for one replay of a recorded call.
 *
 * @spec openspec/specs/outbound-call-log/spec.md
 */
final class ReplayTarget {

	/**
	 * Constructor.
	 *
	 * @param string $uuid           The call record uuid.
	 * @param string $actorUid       Who replays it.
	 * @param mixed  $mappingVersion The mapping version the replay runs under.
	 * @param string $target         The dispatch target the call goes to.
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly string $actorUid,
		public readonly mixed $mappingVersion,
		public readonly string $target,
	) {

	}//end __construct()
}//end class

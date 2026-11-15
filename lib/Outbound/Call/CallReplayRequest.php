<?php

/**
 * Integriq CallReplayRequest.
 *
 * One replay of a recorded call as it was asked for: the call record, who
 * replays it, the mapping version asked for (null for the one it ran under),
 * and whether it is only a dry run. Built by CallReplayService::replay() and
 * never handed out.
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
 * A replay as asked for.
 *
 * @spec openspec/specs/outbound-call-log/spec.md
 */
final class CallReplayRequest {

	/**
	 * The mapping version asked for; null for the version the call ran under.
	 *
	 * @var string|null
	 */
	public ?string $mappingVersion = null;

	/**
	 * True to show the request instead of sending it.
	 *
	 * @var bool
	 */
	public bool $dryRun = false;

	/**
	 * Constructor.
	 *
	 * @param string $uuid The call record uuid.
	 * @param string $actorUid Who replays it.
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly string $actorUid,
	) {
	}//end __construct()
}//end class

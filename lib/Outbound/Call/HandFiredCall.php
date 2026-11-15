<?php

/**
 * Integriq HandFiredCall.
 *
 * One call an administrator fires by hand from the call log: the target, the
 * request, who fires it, and whether it is only a dry run. Built by
 * CallReplayService::fire() and never handed out.
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
 * A call fired by hand.
 *
 * @spec openspec/specs/outbound-call-log/spec.md
 */
final class HandFiredCall {

	/**
	 * Who fires it.
	 *
	 * @var string
	 */
	public string $actorUid = '';

	/**
	 * True to show the request instead of sending it.
	 *
	 * @var bool
	 */
	public bool $dryRun = false;

	/**
	 * Constructor.
	 *
	 * @param string $target The source, endpoint or subscription to call.
	 * @param array<string,mixed> $request The request to send.
	 */
	public function __construct(
		public readonly string $target,
		public readonly array $request,
	) {
	}//end __construct()
}//end class

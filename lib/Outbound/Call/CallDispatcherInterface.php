<?php

/**
 * Integriq CallDispatcherInterface.
 *
 * The seam a replay dispatches through. The production binding is
 * {@see CallServiceDispatcher}, which goes through `CallService::call()`, the
 * same path the original call took: a replay that used a different path would
 * be a different call that happens to look similar.
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
 * @spec openspec/changes/outbound-call-delivery-and-replay/specs/outbound-call-log/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Outbound\Call;

/**
 * Sends one outbound call.
 */
interface CallDispatcherInterface {

	/**
	 * Send one call.
	 *
	 * @param string $target The source, endpoint or subscription to call.
	 * @param array<string,mixed> $request The request: `method`, `endpoint`, `headers`, `body`.
	 *
	 * @return array{statusCode:int,body:mixed,headers:array<string,mixed>,durationMs:int,detail:string}
	 *         What came back.
	 *
	 * @throws \OCA\Integriq\Exception\CallDispatchException When the call could not be made at all.
	 */
	public function dispatch(string $target, array $request): array;

}//end interface

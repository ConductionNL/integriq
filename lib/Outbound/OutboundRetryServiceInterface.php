<?php

/**
 * Contract of the OutboundRetryService.
 *
 * @category Contract
 * @package  OCA\Integriq\Outbound
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Outbound;

/**
 * Public operations of {@see OutboundRetryService} that other components call.
 */
interface OutboundRetryServiceInterface {
	/**
	 * Retry one message's failed recipients.
	 *
	 * @param string $uuid The record uuid.
	 * @param string $actorUid Who retried it.
	 *
	 * Each failed recipient is asked of the opt-out list first, with the
	 * category the first send was decided as (opt-out-before-send). A
	 * recipient who opted out since is skipped, marked failed in the opt-out
	 * step and logged; only the others are re-dispatched.
	 *
	 * @return array{message:string,retried:array<int,string>,skipped:array<int,string>,succeeded:bool,detail:string} The outcome.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-every-integriq-sender-asks-the-opt-out-list-before-it-sends-req-ooa-001
	 */
	public function retry(string $uuid, string $actorUid): array;

	/**
	 * Retry several messages, reporting each one.
	 *
	 * No item is silently skipped: a message with nothing to retry says so,
	 * and a message that fails again says why.
	 *
	 * @param array<int,string> $uuids The record uuids.
	 * @param string $actorUid Who retried them.
	 *
	 * @return array{succeeded:int,failed:int,items:array<int,array<string,mixed>>} The outcomes.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on OutboundRetryService::retryAll()
	 */
	public function retryAll(array $uuids, string $actorUid): array;
}

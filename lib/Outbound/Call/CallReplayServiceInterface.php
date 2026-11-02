<?php

/**
 * Contract of the CallReplayService.
 *
 * @category Contract
 * @package  OCA\Integriq\Outbound\Call
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

namespace OCA\Integriq\Outbound\Call;

/**
 * Public operations of {@see CallReplayService} that other components call.
 *
 * The method signatures repeat those of CallReplayService unchanged, so the findings
 * phpmd reports on them belong to that class and are suppressed here.
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 */
interface CallReplayServiceInterface {
	/**
	 * What a replay would run under, before anybody chooses.
	 *
	 * @param string $uuid The call record uuid.
	 *
	 * @return array{request:array<string,mixed>,versions:array<string,mixed>} The request that
	 *         would be sent, and the mapping versions on offer.
	 *
	 * @spec openspec/specs/outbound-call-log/spec.md#requirement-captured-bodies-age-out-and-the-record-stays-req-ocd-009
	 */
	public function preview(string $uuid): array;

	/**
	 * Replay one failed call.
	 *
	 * @param string $uuid The call record uuid.
	 * @param string $actorUid Who replayed it.
	 * @param array<string,mixed> $options `dryRun` and `mappingVersion`.
	 *
	 * @return array<string,mixed> What happened, per item.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on CallReplayService::replay()
	 */
	public function replay(string $uuid, string $actorUid, array $options = []): array;

	/**
	 * Replay several calls, reporting each.
	 *
	 * @param array<int,string> $uuids The call record uuids.
	 * @param string $actorUid Who replayed them.
	 * @param array<string,mixed> $options `dryRun` and `mappingVersion`.
	 *
	 * @return array{succeeded:int,failed:int,items:array<int,array<string,mixed>>} The outcomes.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on CallReplayService::replayAll()
	 */
	public function replayAll(array $uuids, string $actorUid, array $options = []): array;

	/**
	 * Fire a call by hand, for something that has not happened yet.
	 *
	 * @param string $target The source, endpoint or subscription to call.
	 * @param array<string,mixed> $request The request to send.
	 * @param string $actorUid Who fired it.
	 * @param bool $dryRun Whether to show the request instead of sending it.
	 *
	 * @return array<string,mixed> What happened.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on CallReplayService::fire()
	 */
	public function fire(string $target, array $request, string $actorUid, bool $dryRun = false): array;
}

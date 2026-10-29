<?php

/**
 * Contract of the CallRecorder.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Public operations of {@see CallRecorder} that other components call.
 */
interface CallRecorderInterface {
	/**
	 * Record one call.
	 *
	 * @param array<string,mixed> $call The call: `target`, `traceId`, `request`, `response`,
	 *                                  `statusCode`, `statusMessage`, `durationMs`, `kind`,
	 *                                  `firedBy`, `retryPolicy`, `mapping`, `mappingVersion`.
	 *
	 * @return ObjectEntity The record.
	 *
	 * @spec openspec/specs/outbound-call-log/spec.md#requirement-every-outbound-call-is-a-record-with-its-request-and-its-response-req-ocd-001
	 */
	public function record(array $call): ObjectEntity;

	/**
	 * Append an attempt to a call that already exists.
	 *
	 * The first attempt is never overwritten: what happened the first time is
	 * the reason somebody is looking.
	 *
	 * @param string $uuid The call record uuid.
	 * @param array<string,mixed> $attempt The attempt: `by`, `kind`, `statusCode`, `detail`,
	 *                                     `mappingVersion`, and optionally `request`/`response`.
	 *
	 * @return ObjectEntity The updated record.
	 *
	 * @spec openspec/specs/outbound-call-log/spec.md#requirement-captured-bodies-age-out-and-the-record-stays-req-ocd-009
	 */
	public function appendAttempt(string $uuid, array $attempt): ObjectEntity;

	/**
	 * Mark a call as having exhausted its retries.
	 *
	 * @param string $uuid The call record uuid.
	 * @param string $deadLetterRef The dead-letter entry it landed in.
	 *
	 * @return ObjectEntity The updated record.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on CallRecorder::markDeadLettered()
	 */
	public function markDeadLettered(string $uuid, string $deadLetterRef = ''): ObjectEntity;

	/**
	 * Read one call record.
	 *
	 * @param string $uuid The record uuid.
	 *
	 * @return array<string,mixed> The record.
	 *
	 * @throws DoesNotExistException When there is no such call.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on CallRecorder::read()
	 */
	public function read(string $uuid): array;
}

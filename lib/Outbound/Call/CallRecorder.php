<?php

/**
 * Integriq CallRecorder.
 *
 * Makes one outbound call a thing an administrator can list, filter and act
 * on: the target, the trace id it belongs to, the request as sent, the
 * response as received, the status, the duration and the retry policy that
 * governed it. Recording the policy matters as much as configuring it; a call
 * that stopped after two attempts cannot be explained a month later, when the
 * policy has changed twice.
 *
 * Secrets are redacted before the write. A log that stores a bearer token is
 * a breach with a search box.
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

use DateTimeImmutable;
use OCA\Integriq\Outbound\BodyRedactor;
use OCA\Integriq\Outbound\MessageRecorder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Writes and updates outbound call records.
 *
 * @spec openspec/changes/outbound-call-delivery-and-replay/specs/outbound-call-log/spec.md#requirement-every-outbound-call-is-a-record-with-its-request-and-its-response-req-ocd-001
 */
class CallRecorder {

	/**
	 * The schema one call record is stored under.
	 *
	 * @var string
	 */
	public const SCHEMA = 'call_log';

	/**
	 * The event triggered this call.
	 *
	 * @var string
	 */
	public const KIND_TRIGGERED = 'triggered';

	/**
	 * An administrator replayed a call that had already happened.
	 *
	 * @var string
	 */
	public const KIND_REPLAYED = 'replayed';

	/**
	 * An administrator sent one that had not.
	 *
	 * @var string
	 */
	public const KIND_HAND_FIRED = 'hand-fired';

	/**
	 * Nothing was sent; the request that would be sent was shown.
	 *
	 * @var string
	 */
	public const KIND_DRY_RUN = 'dry-run';

	/**
	 * Constructor.
	 *
	 * @param ORObjectService $objectService Persists the records.
	 * @param BodyRedactor $redactor Redacts before the write, never after.
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
		private readonly BodyRedactor $redactor,
	) {

	}//end __construct()

	/**
	 * Record one call.
	 *
	 * @param array<string,mixed> $call The call: `target`, `traceId`, `request`, `response`,
	 *                                  `statusCode`, `statusMessage`, `durationMs`, `kind`,
	 *                                  `firedBy`, `retryPolicy`, `mapping`, `mappingVersion`.
	 *
	 * @return ObjectEntity The record.
	 */
	public function record(array $call): ObjectEntity {
		$now = (new DateTimeImmutable())->format('c');
		$kind = (string)($call['kind'] ?? self::KIND_TRIGGERED);
		$statusCode = (int)($call['statusCode'] ?? 0);

		$outcome = 'failed';
		if ($this->isSuccess($statusCode) === true) {
			$outcome = 'succeeded';
		}

		$record = [
			'target' => (string)($call['target'] ?? ''),
			'traceId' => (string)($call['traceId'] ?? ''),
			'direction' => 'outbound',
			'request' => $this->redactor->redactContext($this->asArray(($call['request'] ?? []))),
			'response' => $this->redactor->redactContext($this->asArray(($call['response'] ?? []))),
			'statusCode' => $statusCode,
			'statusMessage' => (string)($call['statusMessage'] ?? ''),
			'durationMs' => (int)($call['durationMs'] ?? 0),
			'kind' => $kind,
			'firedBy' => (string)($call['firedBy'] ?? ''),
			'retryPolicy' => $this->asArray(($call['retryPolicy'] ?? [])),
			'mapping' => (string)($call['mapping'] ?? ''),
			'mappingVersion' => (string)($call['mappingVersion'] ?? ''),
			'deadLettered' => false,
			'source' => (string)($call['source'] ?? ($call['target'] ?? '')),
			'created' => $now,
			'attempts' => [
				[
					'at' => $now,
					'by' => (string)($call['firedBy'] ?? ''),
					'kind' => $kind,
					'statusCode' => $statusCode,
					'outcome' => $outcome,
					'detail' => (string)($call['statusMessage'] ?? ''),
					'mappingVersion' => (string)($call['mappingVersion'] ?? ''),
				],
			],
		];

		return $this->objectService->saveObject(
			object: $record,
			register: MessageRecorder::REGISTER,
			schema: self::SCHEMA,
		);

	}//end record()

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
	 */
	public function appendAttempt(string $uuid, array $attempt): ObjectEntity {
		$record = $this->read($uuid);
		$statusCode = (int)($attempt['statusCode'] ?? 0);

		$outcome = 'failed';
		if ($this->isSuccess($statusCode) === true) {
			$outcome = 'succeeded';
		}

		$record['attempts'][] = [
			'at' => (new DateTimeImmutable())->format('c'),
			'by' => (string)($attempt['by'] ?? ''),
			'kind' => (string)($attempt['kind'] ?? self::KIND_REPLAYED),
			'statusCode' => $statusCode,
			'outcome' => $outcome,
			'detail' => (string)($attempt['detail'] ?? ''),
			'mappingVersion' => (string)($attempt['mappingVersion'] ?? ($record['mappingVersion'] ?? '')),
		];

		if (isset($attempt['response']) === true) {
			$record['response'] = $this->redactor->redactContext($this->asArray($attempt['response']));
		}

		$record['statusCode'] = $statusCode;
		$record['statusMessage'] = (string)($attempt['detail'] ?? $record['statusMessage'] ?? '');

		return $this->write($uuid, $record);

	}//end appendAttempt()

	/**
	 * Mark a call as having exhausted its retries.
	 *
	 * @param string $uuid The call record uuid.
	 * @param string $deadLetterRef The dead-letter entry it landed in.
	 *
	 * @return ObjectEntity The updated record.
	 */
	public function markDeadLettered(string $uuid, string $deadLetterRef = ''): ObjectEntity {
		$record = $this->read($uuid);
		$record['deadLettered'] = true;
		$record['deadLetterRef'] = $deadLetterRef;

		return $this->write($uuid, $record);

	}//end markDeadLettered()

	/**
	 * Read one call record.
	 *
	 * @param string $uuid The record uuid.
	 *
	 * @return array<string,mixed> The record.
	 *
	 * @throws DoesNotExistException When there is no such call.
	 */
	public function read(string $uuid): array {
		$entity = $this->objectService->find(
			id: $uuid,
			register: MessageRecorder::REGISTER,
			schema: self::SCHEMA,
		);

		if (($entity instanceof ObjectEntity) === false) {
			throw new DoesNotExistException('No call "' . $uuid . '".');
		}

		$record = $entity->getObject();
		if (is_array(($record['attempts'] ?? null)) === false) {
			$record['attempts'] = [];
		}

		return $record;

	}//end read()

	/**
	 * Whether a status code counts as a success.
	 *
	 * @param int $statusCode The status code.
	 *
	 * @return bool True for 2xx.
	 */
	private function isSuccess(int $statusCode): bool {
		return ($statusCode >= 200 && $statusCode < 300);

	}//end isSuccess()

	/**
	 * Coerce a request or response bag to an array.
	 *
	 * @param mixed $value The bag.
	 *
	 * @return array<string,mixed> The array.
	 */
	private function asArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_string($value) === true && $value !== '') {
			return ['body' => $value];
		}

		return [];

	}//end asArray()

	/**
	 * Write a record back.
	 *
	 * @param string $uuid The record uuid.
	 * @param array<string,mixed> $record The payload.
	 *
	 * @return ObjectEntity The saved record.
	 */
	private function write(string $uuid, array $record): ObjectEntity {
		return $this->objectService->saveObject(
			object: $record,
			register: MessageRecorder::REGISTER,
			schema: self::SCHEMA,
			uuid: $uuid,
		);

	}//end write()

}//end class

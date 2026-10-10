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
 * @spec openspec/specs/outbound-call-log/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Outbound\Call;

use DateTimeImmutable;
use OCA\Integriq\Outbound\BodyRedactor;
use OCA\Integriq\Outbound\MessageRecorder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use Throwable;

/**
 * Writes and updates outbound call records.
 *
 * `call_log` is admin-only in the register, so every read and write here runs
 * with `_rbac: false`: the callers gate access (CallLogController with the
 * call-log permissions), and the engine records calls for whoever triggered them.
 *
 * @spec openspec/specs/outbound-call-log/spec.md#requirement-every-outbound-call-is-a-record-with-its-request-and-its-response-req-ocd-001
 * @spec openspec/specs/outbound-call-log/spec.md#requirement-call-records-are-readable-only-by-admins-and-through-integriqs-own-endpoints-req-ocd-012
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
	 * What the register accepts in the `source` relation.
	 *
	 * @var string
	 */
	private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

	/**
	 * Constructor.
	 *
	 * @param ORObjectService $objectService Persists the records.
	 * @param BodyRedactor $redactor Redacts before the write, never after.
	 * @param BodyCapturePolicy $bodyCapturePolicy Keeps bodies only inside a source's investigation window.
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
		private readonly BodyRedactor $redactor,
		private readonly BodyCapturePolicy $bodyCapturePolicy,
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
	 *
	 * @spec openspec/specs/outbound-call-log/spec.md#requirement-every-outbound-call-is-a-record-with-its-request-and-its-response-req-ocd-001
	 */
	public function record(array $call): ObjectEntity {
		$now = (new DateTimeImmutable())->format('c');
		$kind = (string)($call['kind'] ?? self::KIND_TRIGGERED);
		$statusCode = (int)($call['statusCode'] ?? 0);

		$outcome = 'failed';
		if ($this->isSuccess(statusCode: $statusCode) === true) {
			$outcome = 'succeeded';
		}

		$record = [
			'target' => (string)($call['target'] ?? ''),
			'traceId' => (string)($call['traceId'] ?? ''),
			'direction' => 'outbound',
			'request' => $this->redactor->redactContext($this->asArray(value: ($call['request'] ?? []))),
			'response' => $this->redactor->redactContext($this->asArray(value: ($call['response'] ?? []))),
			'statusCode' => $statusCode,
			'statusMessage' => (string)($call['statusMessage'] ?? ''),
			'durationMs' => (int)($call['durationMs'] ?? 0),
			'kind' => $kind,
			'firedBy' => (string)($call['firedBy'] ?? ''),
			'retryPolicy' => $this->asArray(value: ($call['retryPolicy'] ?? [])),
			'mapping' => (string)($call['mapping'] ?? ''),
			'mappingVersion' => (string)($call['mappingVersion'] ?? ''),
			'deadLettered' => false,
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

		$source = $this->sourceRef(call: $call);
		if ($source !== null) {
			$record['source'] = $source;
		}

		// Bodies only inside the source's investigation window; a failure keeps
		// its request for a replay (REQ-OCD-001, REQ-OCD-009).
		$record = $this->bodyCapturePolicy->apply(
			record: $record,
			sourceData: $this->sourceData(uuid: $source),
			logBody: false,
			errorExpires: null
		);

		return $this->objectService->saveObject(
			object: $record,
			register: MessageRecorder::REGISTER,
			schema: self::SCHEMA,
			_rbac: false,
			_multitenancy: false,
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
	 *
	 * @spec openspec/specs/outbound-call-log/spec.md#requirement-captured-bodies-age-out-and-the-record-stays-req-ocd-009
	 */
	public function appendAttempt(string $uuid, array $attempt): ObjectEntity {
		$record = $this->read(uuid: $uuid);
		$statusCode = (int)($attempt['statusCode'] ?? 0);

		$outcome = 'failed';
		if ($this->isSuccess(statusCode: $statusCode) === true) {
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
			$response = $this->redactor->redactContext($this->asArray(value: $attempt['response']));
			$sourceData = $this->sourceData(uuid: $this->sourceRef(call: $record));
			if ($this->bodyCapturePolicy->isOpen(sourceData: $sourceData, now: $this->bodyCapturePolicy->now()) === false) {
				unset($response['body']);
			}

			$record['response'] = $response;
		}

		// A replay that succeeded no longer needs the request it replayed (REQ-OCD-009).
		if ($outcome === 'succeeded') {
			unset($record['replayRequest']);
		}

		$record['statusCode'] = $statusCode;
		$record['statusMessage'] = (string)($attempt['detail'] ?? $record['statusMessage'] ?? '');

		return $this->write(uuid: $uuid, record: $record);

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
		$record = $this->read(uuid: $uuid);
		$record['deadLettered'] = true;
		$record['deadLetterRef'] = $deadLetterRef;

		return $this->write(uuid: $uuid, record: $record);

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
			_rbac: false,
			_multitenancy: false,
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
	 * The source relation for a call, when there is one.
	 *
	 * `source` on `call_log` is a uuid relation to a source object, so the
	 * register refuses anything else. A call's target is often not a source
	 * (a pre-check URL, a partner name), and writing it there made the
	 * register refuse the whole record, so the call that failed was never
	 * kept. The target stays in `target`; `source` is only set when the call
	 * names a source uuid, or when its target is one.
	 *
	 * @param array<string,mixed> $call The call as handed to record().
	 *
	 * @return string|null The source uuid, or null when the call has none.
	 *
	 * @spec openspec/specs/outbound-call-log/spec.md#requirement-every-outbound-call-is-a-record-with-its-request-and-its-response-req-ocd-001
	 */
	private function sourceRef(array $call): ?string {
		foreach ([($call['source'] ?? null), ($call['target'] ?? null)] as $candidate) {
			if (is_string($candidate) === true && preg_match(self::UUID_PATTERN, $candidate) === 1) {
				return $candidate;
			}
		}

		return null;

	}//end sourceRef()

	/**
	 * The source a call went to, for its investigation window.
	 *
	 * A source that cannot be read has no window, so no body is kept.
	 *
	 * @param string|null $uuid The source uuid, or null when the call has none.
	 *
	 * @return array<string,mixed> The source, or an empty array.
	 *
	 * @spec openspec/specs/outbound-call-log/spec.md#requirement-an-administrator-opens-a-bounded-investigation-window-per-source-req-ocd-008
	 */
	private function sourceData(?string $uuid): array {
		if ($uuid === null) {
			return [];
		}

		try {
			$source = $this->objectService->find(
				id: $uuid,
				register: MessageRecorder::REGISTER,
				schema: 'source',
				_rbac: false,
				_multitenancy: false,
			);
		} catch (Throwable) {
			return [];
		}

		if (($source instanceof ObjectEntity) === false) {
			return [];
		}

		return $source->getObject();

	}//end sourceData()

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
			_rbac: false,
			_multitenancy: false,
		);

	}//end write()

}//end class

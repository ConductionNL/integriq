<?php

/**
 * Integriq CallReplayService.
 *
 * Replaying re-sends something that already happened; firing by hand sends
 * something that has not. They share a permission and a record shape and
 * differ in one field, and to the receiver they are the same request, which
 * is deliberate: a receiver that behaves differently for a hand-fired
 * delivery is a receiver we cannot test.
 *
 * A dry run shows the request that would be sent and sends nothing, which is
 * what an administrator uses when the failure was a bad payload rather than a
 * downtime. A call that exhausts its configured retries lands in the
 * dead-letter list rather than disappearing.
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
use OCA\Integriq\Exception\CallDispatchException;
use OCA\Integriq\Outbound\MessageRecorder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use Throwable;

/**
 * Replays failed calls, fires new ones by hand, and dry runs both.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/outbound-call-delivery-and-replay/specs/outbound-call-log/spec.md#requirement-a-failed-call-is-replayed-from-the-screen-singly-and-in-bulk-req-ocd-002
 */
class CallReplayService {

	/**
	 * The schema an exhausted call is dead-lettered into.
	 *
	 * @var string
	 */
	public const SCHEMA_DEAD_LETTER = 'sync_item_dead_letter';

	/**
	 * Constructor.
	 *
	 * @param CallRecorder $recorder Reads the call and appends the attempt.
	 * @param CallDispatcherInterface $dispatcher Sends through the original dispatch path.
	 * @param MappingVersionService $mappingVersions Offers and resolves the mapping version.
	 * @param ORObjectService $objectService Writes the dead-letter entry.
	 */
	public function __construct(
		private readonly CallRecorder $recorder,
		private readonly CallDispatcherInterface $dispatcher,
		private readonly MappingVersionService $mappingVersions,
		private readonly ORObjectService $objectService,
	) {

	}//end __construct()

	/**
	 * What a replay would run under, before anybody chooses.
	 *
	 * @param string $uuid The call record uuid.
	 *
	 * @return array{request:array<string,mixed>,versions:array<string,mixed>} The request that
	 *         would be sent, and the mapping versions on offer.
	 */
	public function preview(string $uuid): array {
		$record = $this->recorder->read($uuid);

		return [
			'request' => self::requestOf(record: $record),
			'versions' => $this->mappingVersions->choices(
				(string)($record['mapping'] ?? ''),
				(string)($record['mappingVersion'] ?? '')
			),
		];

	}//end preview()

	/**
	 * Replay one failed call.
	 *
	 * @param string $uuid The call record uuid.
	 * @param string $actorUid Who replayed it.
	 * @param array<string,mixed> $options `dryRun` and `mappingVersion`.
	 *
	 * @return array<string,mixed> What happened, per item.
	 */
	public function replay(string $uuid, string $actorUid, array $options = []): array {
		$record = $this->recorder->read($uuid);
		$dryRun = (($options['dryRun'] ?? false) === true);
		$wantedVersion = ($options['mappingVersion'] ?? null);
		if ($wantedVersion !== null) {
			$wantedVersion = (string)$wantedVersion;
		}

		$resolved = $this->mappingVersions->resolve(
			(string)($record['mapping'] ?? ''),
			(string)($record['mappingVersion'] ?? ''),
			$wantedVersion
		);

		$request = self::requestOf(record: $record);

		if ($dryRun === true) {
			// A dry run writes nothing at all: no call, no attempt, no record.
			// An administrator uses it to look at a payload, and looking must
			// not change what they are looking at.
			return [
				'call' => $uuid,
				'kind' => CallRecorder::KIND_DRY_RUN,
				'succeeded' => true,
				'sent' => false,
				'mappingVersion' => $resolved['version'],
				'request' => $request,
				'detail' => 'Dry run: this is the request that would be sent. Nothing was sent.',
			];
		}

		try {
			$response = $this->dispatcher->dispatch((string)($record['target'] ?? ''), $request);
		} catch (CallDispatchException $exception) {
			$this->recorder->appendAttempt(
				$uuid,
				[
					'by' => $actorUid,
					'kind' => CallRecorder::KIND_REPLAYED,
					'statusCode' => 0,
					'detail' => $exception->getMessage(),
					'mappingVersion' => $resolved['version'],
				]
			);
			$this->deadLetterIfExhausted(uuid: $uuid);

			return [
				'call' => $uuid,
				'kind' => CallRecorder::KIND_REPLAYED,
				'succeeded' => false,
				'sent' => false,
				'mappingVersion' => $resolved['version'],
				'detail' => $exception->getMessage(),
			];
		}

		$this->recorder->appendAttempt(
			$uuid,
			[
				'by' => $actorUid,
				'kind' => CallRecorder::KIND_REPLAYED,
				'statusCode' => (int)$response['statusCode'],
				'detail' => (string)$response['detail'],
				'mappingVersion' => $resolved['version'],
				'response' => $response,
			]
		);

		$succeeded = ((int)$response['statusCode'] >= 200 && (int)$response['statusCode'] < 300);
		if ($succeeded === false) {
			$this->deadLetterIfExhausted(uuid: $uuid);
		}

		return [
			'call' => $uuid,
			'kind' => CallRecorder::KIND_REPLAYED,
			'succeeded' => $succeeded,
			'sent' => true,
			'statusCode' => (int)$response['statusCode'],
			'mappingVersion' => $resolved['version'],
			'detail' => (string)$response['detail'],
		];

	}//end replay()

	/**
	 * Replay several calls, reporting each.
	 *
	 * @param array<int,string> $uuids The call record uuids.
	 * @param string $actorUid Who replayed them.
	 * @param array<string,mixed> $options `dryRun` and `mappingVersion`.
	 *
	 * @return array{succeeded:int,failed:int,items:array<int,array<string,mixed>>} The outcomes.
	 */
	public function replayAll(array $uuids, string $actorUid, array $options = []): array {
		$items = [];
		$succeeded = 0;
		$failed = 0;
		foreach ($uuids as $uuid) {
			try {
				$outcome = $this->replay(uuid: (string)$uuid, actorUid: $actorUid, options: $options);
			} catch (Throwable $exception) {
				$outcome = [
					'call' => (string)$uuid,
					'succeeded' => false,
					'sent' => false,
					'detail' => $exception->getMessage(),
				];
			}

			$items[] = $outcome;
			if (($outcome['succeeded'] ?? false) === true) {
				$succeeded++;
				continue;
			}

			$failed++;
		}

		return ['succeeded' => $succeeded, 'failed' => $failed, 'items' => $items];

	}//end replayAll()

	/**
	 * Fire a call by hand, for something that has not happened yet.
	 *
	 * @param string $target The source, endpoint or subscription to call.
	 * @param array<string,mixed> $request The request to send.
	 * @param string $actorUid Who fired it.
	 * @param bool $dryRun Whether to show the request instead of sending it.
	 *
	 * @return array<string,mixed> What happened.
	 */
	public function fire(string $target, array $request, string $actorUid, bool $dryRun = false): array {
		if ($dryRun === true) {
			return [
				'kind' => CallRecorder::KIND_DRY_RUN,
				'succeeded' => true,
				'sent' => false,
				'request' => $request,
				'detail' => 'Dry run: this is the request that would be sent. Nothing was sent.',
			];
		}

		try {
			$response = $this->dispatcher->dispatch($target, $request);
		} catch (CallDispatchException $exception) {
			$record = $this->recorder->record(
				[
					'target' => $target,
					'request' => $request,
					'statusCode' => 0,
					'statusMessage' => $exception->getMessage(),
					'kind' => CallRecorder::KIND_HAND_FIRED,
					'firedBy' => $actorUid,
				]
			);

			return [
				'call' => (string)$record->getUuid(),
				'kind' => CallRecorder::KIND_HAND_FIRED,
				'succeeded' => false,
				'sent' => false,
				'detail' => $exception->getMessage(),
			];
		}

		$record = $this->recorder->record(
			[
				'target' => $target,
				'request' => $request,
				'response' => $response,
				'statusCode' => (int)$response['statusCode'],
				'statusMessage' => (string)$response['detail'],
				'durationMs' => (int)$response['durationMs'],
				'kind' => CallRecorder::KIND_HAND_FIRED,
				'firedBy' => $actorUid,
			]
		);

		return [
			'call' => (string)$record->getUuid(),
			'kind' => CallRecorder::KIND_HAND_FIRED,
			'succeeded' => ((int)$response['statusCode'] >= 200 && (int)$response['statusCode'] < 300),
			'sent' => true,
			'statusCode' => (int)$response['statusCode'],
			'detail' => (string)$response['detail'],
		];

	}//end fire()

	/**
	 * Dead-letter a call that has used up its configured attempts.
	 *
	 * The call stays replayable: a dead letter is where a failure waits for a
	 * human, not where it goes to be forgotten.
	 *
	 * @param string $uuid The call record uuid.
	 *
	 * @return bool True when the call was dead-lettered by this attempt.
	 */
	private function deadLetterIfExhausted(string $uuid): bool {
		$record = $this->recorder->read($uuid);
		if (($record['deadLettered'] ?? false) === true) {
			return false;
		}

		$policy = ($record['retryPolicy'] ?? []);

		$maxAttempts = 1;
		if (is_array($policy) === true) {
			$maxAttempts = (int)($policy['maxAttempts'] ?? 1);
		}

		if ($maxAttempts < 1) {
			$maxAttempts = 1;
		}

		if (count($record['attempts']) < $maxAttempts) {
			return false;
		}

		$entry = $this->objectService->saveObject(
			object: [
				'synchronization' => '',
				'originId' => $uuid,
				'phase' => 'outbound-call',
				'payload' => self::requestOf(record: $record),
				'error' => (string)($record['statusMessage'] ?? 'The call exhausted its retry policy.'),
				'status' => 'failed',
				'retryCount' => count($record['attempts']),
				'attempts' => [
					[
						'at' => (new DateTimeImmutable())->format('c'),
						'error' => (string)($record['statusMessage'] ?? ''),
					],
				],
			],
			register: MessageRecorder::REGISTER,
			schema: self::SCHEMA_DEAD_LETTER,
		);

		$deadLetterId = '';
		if ($entry instanceof ObjectEntity) {
			$deadLetterId = (string)$entry->getUuid();
		}

		$this->recorder->markDeadLettered($uuid, $deadLetterId);

		return true;

	}//end deadLetterIfExhausted()

	/**
	 * The recorded request of a call, as an array.
	 *
	 * @param array<string,mixed> $record The call record.
	 *
	 * @return array<string,mixed> The request, or an empty array when none was recorded.
	 *
	 * @spec openspec/specs/outbound-call-replay/spec.md
	 */
	private static function requestOf(array $record): array {
		$request = ($record['request'] ?? null);
		if (is_array($request) === false) {
			return [];
		}

		return $request;
	}//end requestOf()

}//end class

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
 * @spec openspec/specs/outbound-call-log/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Outbound\Call;

use DateTimeImmutable;
use OCA\Integriq\Outbound\MessageRecorder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use Throwable;

/**
 * Replays failed calls, fires new ones by hand, and dry runs both.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/outbound-call-log/spec.md#requirement-a-failed-call-is-replayed-from-the-screen-singly-and-in-bulk-req-ocd-002
 */
class CallReplayService implements CallReplayServiceInterface {

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
	 *
	 * @spec openspec/specs/outbound-call-log/spec.md#requirement-captured-bodies-age-out-and-the-record-stays-req-ocd-009
	 */
	public function preview(string $uuid): array {
		$record = $this->recorder->read($uuid);

		// The preview shows what the call log shows. The replay request a
		// failure keeps is for the replay itself, behind the replay
		// permission, never for a reader (REQ-OCD-009).
		$request = $this->bagOf(value: ($record['request'] ?? null));

		return [
			'request' => $request,
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
	 *
	 * @spec openspec/specs/outbound-call-log/spec.md#requirement-a-failed-call-is-replayed-from-the-screen-singly-and-in-bulk-req-ocd-002
	 */
	public function replay(string $uuid, string $actorUid, array $options = []): array {
		$request = new CallReplayRequest(uuid: $uuid, actorUid: $actorUid);
		$request->mappingVersion = $this->wantedVersion(options: $options);
		$request->dryRun = (($options['dryRun'] ?? false) === true);

		return $this->replayRequested(request: $request);
	}//end replay()

	/**
	 * Replay a recorded call as asked: refuse one whose request is gone, show a dry run, or send it.
	 *
	 * @param CallReplayRequest $request The replay.
	 *
	 * @return array<string,mixed> What happened.
	 */
	private function replayRequested(CallReplayRequest $request): array {
		$record = $this->recorder->read($request->uuid);
		$call = $this->replayTarget(record: $record, request: $request);
		$payload = $this->requestOf(record: $record);

		if ($this->requestIsKept(record: $record, request: $payload) === false) {
			// The bodies aged out or were never kept (REQ-OCD-009): sending what is left would be a bare
			// request the partner takes for a new call, and it would be recorded as a replay.
			return $this->notSent(call: $call, detail: 'The request is no longer kept, so this call cannot be replayed.');
		}

		if ($request->dryRun === true) {
			return $this->dryRunReplay(call: $call, request: $payload);
		}

		return $this->send(call: $call, request: $payload);
	}//end replayRequested()

	/**
	 * The mapping version a replay asks for, as text; null when it asks for none.
	 *
	 * @param array<string,mixed> $options `mappingVersion`.
	 *
	 * @return string|null The version.
	 */
	private function wantedVersion(array $options): ?string {
		$wantedVersion = ($options['mappingVersion'] ?? null);
		if ($wantedVersion !== null) {
			$wantedVersion = (string)$wantedVersion;
		}

		return $wantedVersion;
	}//end wantedVersion()

	/**
	 * Where and under which mapping version a recorded call is replayed.
	 *
	 * @param array<string,mixed> $record The call record.
	 * @param CallReplayRequest $request The replay.
	 *
	 * @return ReplayTarget The target.
	 */
	private function replayTarget(array $record, CallReplayRequest $request): ReplayTarget {
		$resolved = $this->mappingVersions->resolve(
			(string)($record['mapping'] ?? ''),
			(string)($record['mappingVersion'] ?? ''),
			$request->mappingVersion
		);

		return new ReplayTarget(
			uuid: $request->uuid,
			actorUid: $request->actorUid,
			mappingVersion: $resolved['version'],
			target: (string)($record['target'] ?? '')
		);
	}//end replayTarget()

	/**
	 * The answer to a dry run, which writes nothing at all.
	 *
	 * No call, no attempt, no record: an administrator uses it to look at a
	 * payload, and looking must not change what they are looking at.
	 *
	 * @param ReplayTarget $call The replay target.
	 * @param array<string,mixed> $request The request that would be sent.
	 *
	 * @return array<string,mixed> The dry-run answer.
	 */
	private function dryRunReplay(ReplayTarget $call, array $request): array {
		return [
			'call' => $call->uuid,
			'kind' => CallRecorder::KIND_DRY_RUN,
			'succeeded' => true,
			'sent' => false,
			'mappingVersion' => $call->mappingVersion,
			'request' => $request,
			'detail' => 'Dry run: this is the request that would be sent. Nothing was sent.',
		];
	}//end dryRunReplay()

	/**
	 * Send a replay and record it as an attempt, dead-lettering the call once
	 * it fails and has no attempts left.
	 *
	 * @param ReplayTarget        $call    The call being replayed.
	 * @param array<string,mixed> $request The request to send.
	 *
	 * @return array<string,mixed> What happened.
	 *
	 * @spec openspec/specs/outbound-call-log/spec.md
	 */
	private function send(ReplayTarget $call, array $request): array {
		try {
			$response = $this->dispatcher->dispatch($call->target, $request);
		} catch (CallDispatchException $exception) {
			return $this->dispatchFailed(call: $call, exception: $exception);
		}

		$statusCode = (int)$response['statusCode'];
		$this->recorder->appendAttempt(
			$call->uuid,
			[
				'by' => $call->actorUid,
				'kind' => CallRecorder::KIND_REPLAYED,
				'statusCode' => $statusCode,
				'detail' => (string)$response['detail'],
				'mappingVersion' => $call->mappingVersion,
				'response' => $response,
			]
		);

		$succeeded = ($statusCode >= 200 && $statusCode < 300);
		if ($succeeded === false) {
			$this->deadLetterIfExhausted(uuid: $call->uuid);
		}

		return [
			'call' => $call->uuid,
			'kind' => CallRecorder::KIND_REPLAYED,
			'succeeded' => $succeeded,
			'sent' => true,
			'statusCode' => $statusCode,
			'mappingVersion' => $call->mappingVersion,
			'detail' => (string)$response['detail'],
		];

	}//end send()

	/**
	 * Record a replay that never reached the partner as an attempt too, because
	 * "we could not call them" is an answer somebody needs.
	 *
	 * @param ReplayTarget          $call      The call being replayed.
	 * @param CallDispatchException $exception Why the dispatch failed.
	 *
	 * @return array<string,mixed> The outcome.
	 *
	 * @spec openspec/specs/outbound-call-log/spec.md
	 */
	private function dispatchFailed(ReplayTarget $call, CallDispatchException $exception): array {
		$this->recorder->appendAttempt(
			$call->uuid,
			[
				'by' => $call->actorUid,
				'kind' => CallRecorder::KIND_REPLAYED,
				'statusCode' => 0,
				'detail' => $exception->getMessage(),
				'mappingVersion' => $call->mappingVersion,
			]
		);
		$this->deadLetterIfExhausted(uuid: $call->uuid);

		return $this->notSent(call: $call, detail: $exception->getMessage());

	}//end dispatchFailed()

	/**
	 * The outcome of a replay that never reached the partner.
	 *
	 * @param ReplayTarget $call   The call being replayed.
	 * @param string       $detail Why it was not sent.
	 *
	 * @return array<string,mixed> The outcome.
	 *
	 * @spec openspec/specs/outbound-call-log/spec.md
	 */
	private function notSent(ReplayTarget $call, string $detail): array {
		return [
			'call' => $call->uuid,
			'kind' => CallRecorder::KIND_REPLAYED,
			'succeeded' => false,
			'sent' => false,
			'mappingVersion' => $call->mappingVersion,
			'detail' => $detail,
		];

	}//end notSent()

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
		$call = new HandFiredCall(target: $target, request: $request);
		$call->actorUid = $actorUid;
		$call->dryRun = $dryRun;

		return $this->fireCall(call: $call);
	}//end fire()

	/**
	 * Fire one call by hand: show it on a dry run, otherwise send and record it.
	 *
	 * @param HandFiredCall $call The call.
	 *
	 * @return array<string,mixed> What happened.
	 */
	private function fireCall(HandFiredCall $call): array {
		if ($call->dryRun === true) {
			return $this->dryRunFire(call: $call);
		}

		try {
			$response = $this->dispatcher->dispatch($call->target, $call->request);
		} catch (CallDispatchException $exception) {
			return $this->fireFailed(call: $call, exception: $exception);
		}

		return $this->fired(call: $call, response: $response);
	}//end fireCall()

	/**
	 * The answer to a dry-run hand-fire: the request, unsent and unrecorded.
	 *
	 * @param HandFiredCall $call The call.
	 *
	 * @return array<string,mixed> The dry-run answer.
	 */
	private function dryRunFire(HandFiredCall $call): array {
		return [
			'kind' => CallRecorder::KIND_DRY_RUN,
			'succeeded' => true,
			'sent' => false,
			'request' => $call->request,
			'detail' => 'Dry run: this is the request that would be sent. Nothing was sent.',
		];
	}//end dryRunFire()

	/**
	 * Record a hand-fired call that never reached its target, and say so.
	 *
	 * @param HandFiredCall $call The call.
	 * @param CallDispatchException $exception Why it was not sent.
	 *
	 * @return array<string,mixed> What happened.
	 */
	private function fireFailed(HandFiredCall $call, CallDispatchException $exception): array {
		$record = $this->recorder->record(
			[
				'target' => $call->target,
				'request' => $call->request,
				'statusCode' => 0,
				'statusMessage' => $exception->getMessage(),
				'kind' => CallRecorder::KIND_HAND_FIRED,
				'firedBy' => $call->actorUid,
			]
		);

		return $this->notFired(uuid: (string)$record->getUuid(), detail: $exception->getMessage());
	}//end fireFailed()

	/**
	 * The answer for a recorded hand-fired call that was not sent.
	 *
	 * @param string $uuid The new call record's uuid.
	 * @param string $detail Why it was not sent.
	 *
	 * @return array<string,mixed> What happened.
	 */
	private function notFired(string $uuid, string $detail): array {
		return [
			'call' => $uuid,
			'kind' => CallRecorder::KIND_HAND_FIRED,
			'succeeded' => false,
			'sent' => false,
			'detail' => $detail,
		];
	}//end notFired()

	/**
	 * Record a hand-fired call and its answer; it succeeded when the answer is 2xx.
	 *
	 * @param HandFiredCall $call The call.
	 * @param array<string,mixed> $response The dispatcher's answer.
	 *
	 * @return array<string,mixed> What happened.
	 */
	private function fired(HandFiredCall $call, array $response): array {
		$record = $this->recorder->record(
			[
				'target' => $call->target,
				'request' => $call->request,
				'response' => $response,
				'statusCode' => (int)$response['statusCode'],
				'statusMessage' => (string)$response['detail'],
				'durationMs' => (int)$response['durationMs'],
				'kind' => CallRecorder::KIND_HAND_FIRED,
				'firedBy' => $call->actorUid,
			]
		);

		return $this->firedOutcome(uuid: (string)$record->getUuid(), response: $response);
	}//end fired()

	/**
	 * The answer for a recorded hand-fired call.
	 *
	 * @param string $uuid The new call record's uuid.
	 * @param array<string,mixed> $response The dispatcher's answer.
	 *
	 * @return array<string,mixed> What happened.
	 */
	private function firedOutcome(string $uuid, array $response): array {
		$statusCode = (int)$response['statusCode'];

		return [
			'call' => $uuid,
			'kind' => CallRecorder::KIND_HAND_FIRED,
			'succeeded' => ($statusCode >= 200 && $statusCode < 300),
			'sent' => true,
			'statusCode' => $statusCode,
			'detail' => (string)$response['detail'],
		];
	}//end firedOutcome()

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

		$deadLetterPayload = $this->requestOf(record: $record);

		$entry = $this->objectService->saveObject(
			object: [
				'synchronization' => '',
				'originId' => $uuid,
				'phase' => 'outbound-call',
				'payload' => $deadLetterPayload,
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

		$deadLetterUuid = '';
		if ($entry instanceof ObjectEntity) {
			$deadLetterUuid = (string)$entry->getUuid();
		}

		$this->recorder->markDeadLettered(
			$uuid,
			$deadLetterUuid
		);

		return true;

	}//end deadLetterIfExhausted()

	/**
	 * The request a replay sends, narrowed to an array.
	 *
	 * A failed call keeps the request it sent as `replayRequest`, because the
	 * stored `request` carries no body outside an investigation window
	 * (REQ-OCD-009). The stored `request` is the fallback for records written
	 * before that.
	 *
	 * @param array<string,mixed> $record The stored call record.
	 *
	 * @return array<string,mixed> The request, or an empty array.
	 *
	 * @spec openspec/specs/outbound-call-log/spec.md#requirement-captured-bodies-age-out-and-the-record-stays-req-ocd-009
	 */
	private function requestOf(array $record): array {
		$replayRequest = $this->bagOf(value: ($record['replayRequest'] ?? null));
		if ($replayRequest !== []) {
			return $replayRequest;
		}

		return $this->bagOf(value: ($record['request'] ?? null));
	}//end requestOf()

	/**
	 * Whether the record still holds the request it would replay.
	 *
	 * A call that kept `replayRequest` can always be replayed. Without one, a
	 * GET or HEAD has nothing to lose, but any other method needs the body it
	 * sent: the record is refused when its bodies were stripped (`bodyExpiredAt`),
	 * were withheld or never captured (`bodyCaptured: false`), or, for a record
	 * that predates `bodyCaptured`, when the stored request holds no body.
	 *
	 * @param array<string,mixed> $record The stored call record.
	 * @param array<string,mixed> $request The request `requestOf()` chose.
	 *
	 * @return bool True when the request can be replayed as it was sent.
	 *
	 * @spec openspec/specs/outbound-call-log/spec.md#requirement-captured-bodies-age-out-and-the-record-stays-req-ocd-009
	 */
	private function requestIsKept(array $record, array $request): bool {
		if ($this->bagOf(value: ($record['replayRequest'] ?? null)) !== []) {
			return true;
		}

		if ($request === []) {
			return false;
		}

		$method = strtoupper((string)($request['method'] ?? 'POST'));
		if ($method === 'GET' || $method === 'HEAD') {
			return true;
		}

		$captured = ($record['bodyCaptured'] ?? null);
		$hasBody = (isset($request['body']) === true || isset($request['json']) === true);

		return (
			empty($record['bodyExpiredAt']) === true
			&& $captured !== false
			&& ($captured === true || $hasBody === true)
		);
	}//end requestIsKept()

	/**
	 * A stored bag narrowed to an array.
	 *
	 * A record is whatever was stored, so the value may be anything, and the
	 * coding standard allows neither `?:` nor a ternary to narrow it inline.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return array<string,mixed> The value, or an empty array.
	 */
	private function bagOf(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		return [];
	}//end bagOf()
}//end class

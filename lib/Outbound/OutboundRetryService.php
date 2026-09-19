<?php

/**
 * Integriq OutboundRetryService.
 *
 * A failed send is a failed delivery, so the retry is the replay integriq
 * already has: the message is re-dispatched through the CloudEvents delivery
 * pipeline it went out on, audited with who retried it and when, and appended
 * as a new attempt on the same record. Growing a second retry mechanism would
 * give the fleet two audit trails, two bulk semantics and two places for a
 * message to vanish.
 *
 * A re-dispatch that no subscription picks up is a refusal, not a success:
 * accepted and routed is a different fact from accepted and unrouted.
 *
 * @category Outbound
 * @package  OCA\Integriq\Outbound
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
 * @spec openspec/changes/outbound-communication-log/specs/outbound-message-log/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Outbound;

use OCA\Integriq\Event\DeliveryRequestedEvent;
use OCA\Integriq\Service\EventService;
use Throwable;

/**
 * Retries failed sends through the existing delivery pipeline.
 *
 * @spec openspec/changes/outbound-communication-log/specs/outbound-message-log/spec.md#requirement-a-failed-send-is-retried-from-the-screen-and-the-retry-is-recorded-req-ocl-003
 */
class OutboundRetryService {

	/**
	 * The delivery kind a retried message is re-dispatched as.
	 *
	 * @var string
	 */
	public const DELIVERY_KIND = 'outbound-message-retry';

	/**
	 * Constructor.
	 *
	 * @param MessageRecorder $recorder Reads the record and appends the attempt.
	 * @param EventService $eventService The delivery pipeline the original send used.
	 */
	public function __construct(
		private readonly MessageRecorder $recorder,
		private readonly EventService $eventService,
	) {

	}//end __construct()

	/**
	 * Retry one message's failed recipients.
	 *
	 * @param string $uuid The record uuid.
	 * @param string $actorUid Who retried it.
	 *
	 * @return array{message:string,retried:array<int,string>,succeeded:bool,detail:string} The outcome.
	 */
	public function retry(string $uuid, string $actorUid): array {
		$record = $this->recorder->read($uuid);
		$addresses = $this->failedRecipients(record: $record);
		if ($addresses === []) {
			return [
				'message' => $uuid,
				'retried' => [],
				'succeeded' => false,
				'detail' => 'Nothing to retry: no recipient of this message is failed.',
			];
		}

		[$succeeded, $detail] = $this->redispatch(uuid: $uuid, record: $record, addresses: $addresses);
		$this->recorder->appendAttempt($uuid, $actorUid, $addresses, $succeeded, $detail);

		return [
			'message' => $uuid,
			'retried' => $addresses,
			'succeeded' => $succeeded,
			'detail' => $detail,
		];

	}//end retry()

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
	 */
	public function retryAll(array $uuids, string $actorUid): array {
		$items = [];
		$succeeded = 0;
		$failed = 0;
		foreach ($uuids as $uuid) {
			try {
				$outcome = $this->retry(uuid: (string)$uuid, actorUid: $actorUid);
			} catch (Throwable $exception) {
				$outcome = [
					'message' => (string)$uuid,
					'retried' => [],
					'succeeded' => false,
					'detail' => $exception->getMessage(),
				];
			}

			$items[] = $outcome;
			if ($outcome['succeeded'] === true) {
				$succeeded++;
				continue;
			}

			$failed++;
		}

		return ['succeeded' => $succeeded, 'failed' => $failed, 'items' => $items];

	}//end retryAll()

	/**
	 * Re-dispatch one message through the delivery pipeline.
	 *
	 * @param string $uuid The record uuid.
	 * @param array<string,mixed> $record The record payload.
	 * @param array<int,string> $addresses The recipients being retried.
	 *
	 * @return array{0:bool,1:string} Whether it was accepted and routed, and what happened.
	 */
	private function redispatch(string $uuid, array $record, array $addresses): array {
		$request = new DeliveryRequestedEvent(
			sourceApp: (string)($record['sourceApp'] ?? 'integriq'),
			subjectRegister: MessageRecorder::REGISTER,
			subjectSchema: MessageRecorder::SCHEMA,
			subjectId: $uuid,
			subjectLabel: (string)($record['subject'] ?? ''),
			deliveryKind: self::DELIVERY_KIND,
			channel: (string)($record['channel'] ?? ''),
			payload: [
				'subjectRef' => (string)($record['subjectRef'] ?? ''),
				'subject' => (string)($record['subject'] ?? ''),
				'recipients' => $addresses,
				'attachments' => ($record['attachments'] ?? []),
			],
			correlationId: (string)($record['correlationId'] ?? $uuid));

		try {
			$result = $this->eventService->ingestDeliveryRequest(request: $request);
		} catch (Throwable $exception) {
			return [false, 'The delivery pipeline refused the retry: ' . $exception->getMessage()];
		}

		$matched = count(($result['messages'] ?? []));
		if ($matched === 0) {
			return [
				false,
				'The retry was accepted but no delivery route picked it up, so nothing was sent.',
			];
		}

		return [true, 'Re-dispatched to ' . $matched . ' delivery route(s).'];

	}//end redispatch()

	/**
	 * The addresses of every failed recipient on a record.
	 *
	 * @param array<string,mixed> $record The record payload.
	 *
	 * @return array<int,string> The addresses.
	 */
	private function failedRecipients(array $record): array {
		$addresses = [];
		foreach ($record['recipients'] as $recipient) {
			if (is_array($recipient) === false) {
				continue;
			}

			if ((string)($recipient['status'] ?? '') !== RecipientState::STATUS_FAILED) {
				continue;
			}

			$addresses[] = (string)($recipient['address'] ?? '');
		}

		return array_values(array_filter($addresses, static fn (string $a): bool => ($a !== '')));

	}//end failedRecipients()

}//end class

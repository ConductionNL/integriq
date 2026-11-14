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
use OCA\Integriq\Service\EventServiceInterface;
use Throwable;

/**
 * Retries failed sends through the existing delivery pipeline.
 *
 * @spec openspec/changes/outbound-communication-log/specs/outbound-message-log/spec.md#requirement-a-failed-send-is-retried-from-the-screen-and-the-retry-is-recorded-req-ocl-003
 */
class OutboundRetryService implements OutboundRetryServiceInterface {

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
	 * @param EventServiceInterface $eventService The delivery pipeline the original send used.
	 * @param OutboundSendGate $gate Asks the opt-out list per recipient, as a first send does.
	 */
	public function __construct(
		private readonly MessageRecorder $recorder,
		private readonly EventServiceInterface $eventService,
		private readonly OutboundSendGate $gate,
	) {

	}//end __construct()

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
	public function retry(string $uuid, string $actorUid): array {
		$message = new RetryMessage(uuid: $uuid, record: $this->recorder->read($uuid));
		$message->actorUid = $actorUid;

		$failed = $this->failedRecipients(record: $message->record);
		if ($failed === []) {
			return $this->nothingToRetry(uuid: $uuid);
		}

		$addresses = $this->askOptOuts(message: $message, addresses: $failed);
		if ($addresses === []) {
			return $this->noneLeftToRetry(message: $message);
		}

		return $this->resend(message: $message, addresses: $addresses);

	}//end retry()

	/**
	 * The outcome of a message none of whose recipients failed.
	 *
	 * @param string $uuid The record uuid.
	 *
	 * @return array{message:string,retried:array<int,string>,skipped:array<int,string>,succeeded:bool,detail:string} The outcome.
	 */
	private function nothingToRetry(string $uuid): array {
		return [
			'message' => $uuid,
			'retried' => [],
			'skipped' => [],
			'succeeded' => false,
			'detail' => 'Nothing to retry: no recipient of this message is failed.',
		];

	}//end nothingToRetry()

	/**
	 * Record and answer a retry every failed recipient of which opted out since.
	 *
	 * @param RetryMessage $message The message, its skipped recipients set.
	 *
	 * @return array{message:string,retried:array<int,string>,skipped:array<int,string>,succeeded:bool,detail:string} The outcome.
	 */
	private function noneLeftToRetry(RetryMessage $message): array {
		$detail = 'Not retried: every failed recipient opted out since the first attempt.';
		$this->recorder->appendAttempt($message->uuid, $message->actorUid, [], false, $detail);

		return ['message' => $message->uuid, 'retried' => [], 'skipped' => $message->skipped, 'succeeded' => false, 'detail' => $detail];

	}//end noneLeftToRetry()

	/**
	 * Re-dispatch the recipients the opt-out list allows, and record the attempt.
	 *
	 * @param RetryMessage      $message   The message, its skipped recipients set.
	 * @param array<int,string> $addresses The recipients to re-send.
	 *
	 * @return array{message:string,retried:array<int,string>,skipped:array<int,string>,succeeded:bool,detail:string} The outcome.
	 */
	private function resend(RetryMessage $message, array $addresses): array {
		[$succeeded, $detail] = $this->redispatch(message: $message, addresses: $addresses);
		$this->recorder->appendAttempt($message->uuid, $message->actorUid, $addresses, $succeeded, $detail);

		return [
			'message' => $message->uuid,
			'retried' => $addresses,
			'skipped' => $message->skipped,
			'succeeded' => $succeeded,
			'detail' => $detail,
		];

	}//end resend()

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
	 * Ask the opt-out list about each failed recipient, through the gate a
	 * first send uses; the ones it refuses are kept on the message as skipped.
	 *
	 * @param RetryMessage      $message   The message.
	 * @param array<int,string> $addresses The failed recipients.
	 *
	 * @return array<int,string> The recipients to re-send.
	 */
	private function askOptOuts(RetryMessage $message, array $addresses): array {
		$allowed = [];
		foreach ($addresses as $address) {
			if ($this->mayResendTo(message: $message, address: $address) === true) {
				$allowed[] = $address;
				continue;
			}

			$message->skipped[] = $address;
		}

		return $allowed;

	}//end askOptOuts()

	/**
	 * Ask the opt-out list about one recipient; a refused one is marked failed in the opt-out step.
	 *
	 * @param RetryMessage $message The message.
	 * @param string       $address The recipient.
	 *
	 * @return bool True when the recipient may be sent to again.
	 */
	private function mayResendTo(RetryMessage $message, string $address): bool {
		$decision = $this->optOutDecision(message: $message, address: $address);
		if ($decision['send'] === true) {
			return true;
		}

		$this->recorder->recipientFailed(
			uuid: $message->uuid,
			address: $address,
			step: OutboundSendGate::STEP_OPT_OUT,
			reason: (string)$decision['code'] . ': ' . (string)$decision['reason']
		);
		return false;

	}//end mayResendTo()

	/**
	 * The opt-out list's decision on one recipient, asked with the first send's category.
	 *
	 * @param RetryMessage $message The message.
	 * @param string       $address The recipient.
	 *
	 * @return array<string,mixed> The gate's decision.
	 */
	private function optOutDecision(RetryMessage $message, string $address): array {
		return $this->gate->check(
			channel: (string)($message->record['channel'] ?? ''),
			category: $message->optOutCategory(),
			address: $address,
			options: $message->optOutOptions()
		);

	}//end optOutDecision()

	/**
	 * Re-dispatch one message through the delivery pipeline.
	 *
	 * @param RetryMessage      $message   The message.
	 * @param array<int,string> $addresses The recipients being retried.
	 *
	 * @return array{0:bool,1:string} Whether it was accepted and routed, and what happened.
	 */
	private function redispatch(RetryMessage $message, array $addresses): array {
		$request = $this->deliveryRequest(message: $message, addresses: $addresses);

		try {
			$result = $this->eventService->ingestDeliveryRequest(request: $request);
		} catch (Throwable $exception) {
			return [false, 'The delivery pipeline refused the retry: ' . $exception->getMessage()];
		}

		return $this->routedOutcome(result: $result);

	}//end redispatch()

	/**
	 * The delivery request a retry re-dispatches.
	 *
	 * @param RetryMessage      $message   The message.
	 * @param array<int,string> $addresses The recipients being retried.
	 *
	 * @return DeliveryRequestedEvent The request.
	 */
	private function deliveryRequest(RetryMessage $message, array $addresses): DeliveryRequestedEvent {
		$record = $message->record;

		return new DeliveryRequestedEvent(
			sourceApp: (string)($record['sourceApp'] ?? 'integriq'),
			subjectRegister: MessageRecorder::REGISTER,
			subjectSchema: MessageRecorder::SCHEMA,
			subjectId: $message->uuid,
			subjectLabel: (string)($record['subject'] ?? ''),
			deliveryKind: self::DELIVERY_KIND,
			channel: (string)($record['channel'] ?? ''),
			payload: $this->deliveryPayload(record: $record, addresses: $addresses),
			correlationId: (string)($record['correlationId'] ?? $message->uuid));

	}//end deliveryRequest()

	/**
	 * The payload a retry carries: the message's subject and attachments, and the retried recipients.
	 *
	 * @param array<string,mixed> $record    The record payload.
	 * @param array<int,string>   $addresses The recipients being retried.
	 *
	 * @return array<string,mixed> The payload.
	 */
	private function deliveryPayload(array $record, array $addresses): array {
		return [
			'subjectRef' => (string)($record['subjectRef'] ?? ''),
			'subject' => (string)($record['subject'] ?? ''),
			'recipients' => $addresses,
			'attachments' => ($record['attachments'] ?? []),
		];

	}//end deliveryPayload()

	/**
	 * Whether a re-dispatch the pipeline accepted was picked up by a delivery route.
	 *
	 * @param array<string,mixed> $result The pipeline's answer.
	 *
	 * @return array{0:bool,1:string} Whether it was routed, and what happened.
	 */
	private function routedOutcome(array $result): array {
		$matched = count(($result['messages'] ?? []));
		if ($matched === 0) {
			return [
				false,
				'The retry was accepted but no delivery route picked it up, so nothing was sent.',
			];
		}

		return [true, 'Re-dispatched to ' . $matched . ' delivery route(s).'];

	}//end routedOutcome()

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

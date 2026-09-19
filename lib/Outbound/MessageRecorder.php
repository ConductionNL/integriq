<?php

/**
 * Integriq MessageRecorder.
 *
 * The record of what the product sent to a person. One row per recipient,
 * because the failure is per recipient: three people on one
 * ontvangstbevestiging can have three outcomes, and a record that averages
 * them cannot answer Awb 3:41 for any of them. One entry per step, so a
 * failure names where it happened and what the transport said.
 *
 * A record is written even when the first step fails, because "it never left"
 * is exactly the question being asked.
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

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use RuntimeException;

/**
 * Writes and updates outbound message records.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) -- one method per thing that can happen to a
 * send, which is the vocabulary the spec names; folding them into one setter would move the
 * lifecycle into the callers.
 *
 * @spec openspec/changes/outbound-communication-log/specs/outbound-message-log/spec.md#requirement-every-outbound-message-is-recorded-per-recipient-and-per-step-req-ocl-001
 */
class MessageRecorder {

	/**
	 * The OpenRegister register holding integriq's objects.
	 *
	 * @var string
	 */
	public const REGISTER = 'integriq';

	/**
	 * The schema one outbound message record is stored under.
	 *
	 * @var string
	 */
	public const SCHEMA = 'outbound_message';

	/**
	 * Nothing has been handed to a transport yet.
	 *
	 * @var string
	 */
	public const STATUS_PENDING = 'pending';

	/**
	 * Every recipient's copy was accepted.
	 *
	 * @var string
	 */
	public const STATUS_SENT = 'sent';

	/**
	 * Some recipients were accepted and some were not.
	 *
	 * @var string
	 */
	public const STATUS_PARTIAL = 'partially failed';

	/**
	 * No recipient's copy left.
	 *
	 * @var string
	 */
	public const STATUS_FAILED = 'failed';

	/**
	 * A step succeeded.
	 *
	 * @var string
	 */
	public const OUTCOME_SUCCEEDED = 'succeeded';

	/**
	 * A step failed.
	 *
	 * @var string
	 */
	public const OUTCOME_FAILED = 'failed';

	/**
	 * Constructor.
	 *
	 * @param ORObjectService $objectService Persists the records.
	 * @param BodyRedactor $redactor Redacts the body before it is stored.
	 * @param ChannelReportingCapabilities $capabilities Says what a channel can report.
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
		private readonly BodyRedactor $redactor,
		private readonly ChannelReportingCapabilities $capabilities,
	) {

	}//end __construct()

	/**
	 * Open a record for one outbound message.
	 *
	 * @param string $subjectRef What the message is about, as the sending app references it.
	 * @param string $channel The channel it travels on.
	 * @param string $subject The subject line.
	 * @param string $body The rendered body, redacted here before it is stored.
	 * @param array<int,array<string,mixed>> $recipients `{address, name, accountId}` per recipient.
	 * @param array<string,mixed> $options `sourceApp`, `attachments`, `context`, `correlationId`.
	 *
	 * @return ObjectEntity The opened record.
	 */
	public function start(
		string $subjectRef,
		string $channel,
		string $subject,
		string $body,
		array $recipients,
		array $options = [],
	): ObjectEntity {
		$now = $this->now();
		$context = ($options['context'] ?? []);
		$contextData = $context;
		if (is_array($contextData) === false) {
			$contextData = [];
		}

		$record = [
			'subjectRef' => $subjectRef,
			'channel' => $channel,
			'subject' => $subject,
			'body' => $this->redactor->redactBody($body),
			'context' => $this->redactor->redactContext($contextData),
			'sourceApp' => (string)($options['sourceApp'] ?? ''),
			'correlationId' => (string)($options['correlationId'] ?? ''),
			'attachments' => $this->describeAttachments(($options['attachments'] ?? [])),
			'recipients' => $this->buildRecipients($recipients, $channel),
			'steps' => [
				[
					'step' => 'rendered',
					'outcome' => self::OUTCOME_SUCCEEDED,
					'at' => $now,
					'detail' => '',
				],
			],
			'status' => self::STATUS_PENDING,
			'createdAt' => $now,
			'bodyReads' => [],
			'forwardedFrom' => '',
			'forwardedTo' => [],
		];

		return $this->objectService->saveObject(
			object: $record,
			register: self::REGISTER,
			schema: self::SCHEMA,
		);

	}//end start()

	/**
	 * Record that a step succeeded.
	 *
	 * @param string $uuid The record uuid.
	 * @param string $step The step name.
	 * @param string $detail What it says, if anything.
	 *
	 * @return ObjectEntity The updated record.
	 */
	public function stepSucceeded(string $uuid, string $step, string $detail = ''): ObjectEntity {
		$record = $this->read($uuid);
		$record['steps'][] = [
			'step' => $step,
			'outcome' => self::OUTCOME_SUCCEEDED,
			'at' => $this->now(),
			'detail' => $detail,
		];

		return $this->write($uuid, $record);

	}//end stepSucceeded()

	/**
	 * Record that a step failed, for every recipient or for the named ones.
	 *
	 * @param string $uuid The record uuid.
	 * @param string $step The step name.
	 * @param string $reason What the transport said.
	 * @param array<int,string>|null $addresses The recipients this failure is about, or null for all.
	 *
	 * @return ObjectEntity The updated record.
	 */
	public function stepFailed(string $uuid, string $step, string $reason, ?array $addresses = null): ObjectEntity {
		$record = $this->read($uuid);
		$record['steps'][] = [
			'step' => $step,
			'outcome' => self::OUTCOME_FAILED,
			'at' => $this->now(),
			'detail' => $reason,
		];

		foreach ($record['recipients'] as $index => $recipient) {
			$address = (string)($recipient['address'] ?? '');
			if ($addresses !== null && in_array($address, $addresses, true) === false) {
				continue;
			}

			if ((string)($recipient['status'] ?? '') === RecipientState::STATUS_SENT) {
				continue;
			}

			$record['recipients'][$index]['status'] = RecipientState::STATUS_FAILED;
			$record['recipients'][$index]['failedStep'] = $step;
			$record['recipients'][$index]['reason'] = $reason;
		}

		$record['status'] = $this->deriveStatus($record['recipients']);

		return $this->write($uuid, $record);

	}//end stepFailed()

	/**
	 * Record that a transport accepted one recipient's copy.
	 *
	 * Accepted is not delivered: the delivery state starts at whatever the
	 * channel is capable of saying, never at `reported`.
	 *
	 * @param string $uuid The record uuid.
	 * @param string $address The recipient.
	 * @param string|null $reference The transport's own reference, when it gave one.
	 *
	 * @return ObjectEntity The updated record.
	 */
	public function handedOver(string $uuid, string $address, ?string $reference = null): ObjectEntity {
		$record = $this->read($uuid);
		$index = $this->indexOf($record, $address);

		$record['recipients'][$index]['status'] = RecipientState::STATUS_SENT;
		$record['recipients'][$index]['handedOverAt'] = $this->now();
		$record['recipients'][$index]['reference'] = (string)$reference;
		$record['recipients'][$index]['failedStep'] = '';
		$record['recipients'][$index]['reason'] = '';
		$record['status'] = $this->deriveStatus($record['recipients']);

		return $this->write($uuid, $record);

	}//end handedOver()

	/**
	 * Record that one recipient's copy did not leave.
	 *
	 * @param string $uuid The record uuid.
	 * @param string $address The recipient.
	 * @param string $step The step it failed in.
	 * @param string $reason What the transport said.
	 *
	 * @return ObjectEntity The updated record.
	 */
	public function recipientFailed(string $uuid, string $address, string $step, string $reason): ObjectEntity {
		$record = $this->read($uuid);
		$index = $this->indexOf($record, $address);

		$record['recipients'][$index]['status'] = RecipientState::STATUS_FAILED;
		$record['recipients'][$index]['failedStep'] = $step;
		$record['recipients'][$index]['reason'] = $reason;
		$record['status'] = $this->deriveStatus($record['recipients']);

		return $this->write($uuid, $record);

	}//end recipientFailed()

	/**
	 * Record a delivery the transport reported.
	 *
	 * @param string $uuid The record uuid.
	 * @param string $address The recipient.
	 * @param string $detail What the receipt said.
	 *
	 * @return ObjectEntity The updated record.
	 *
	 * @throws RuntimeException When the channel cannot report deliveries, because a state it
	 *                          cannot produce would be a state nobody can trust.
	 */
	public function deliveryReported(string $uuid, string $address, string $detail = ''): ObjectEntity {
		$record = $this->read($uuid);
		$channel = (string)($record['channel'] ?? '');
		if ($this->capabilities->reportsDelivery($channel) === false) {
			throw new RuntimeException(
				'Channel "' . $channel . '" cannot report deliveries, so this receipt is not its.'
			);
		}

		$index = $this->indexOf($record, $address);
		$record['recipients'][$index]['deliveryState'] = RecipientState::REPORTED;
		$record['recipients'][$index]['deliveredAt'] = $this->now();
		$record['recipients'][$index]['deliveryDetail'] = $detail;

		return $this->write($uuid, $record);

	}//end deliveryReported()

	/**
	 * Record a read the transport reported.
	 *
	 * A read is never inferred from a delivery: the two are separate facts and
	 * only one of them decides whether a term may run.
	 *
	 * @param string $uuid The record uuid.
	 * @param string $address The recipient.
	 * @param string $detail What the receipt said.
	 *
	 * @return ObjectEntity The updated record.
	 *
	 * @throws RuntimeException When the channel cannot report reads.
	 */
	public function readReported(string $uuid, string $address, string $detail = ''): ObjectEntity {
		$record = $this->read($uuid);
		$channel = (string)($record['channel'] ?? '');
		if ($this->capabilities->reportsRead($channel) === false) {
			throw new RuntimeException(
				'Channel "' . $channel . '" cannot report reads, so this receipt is not its.'
			);
		}

		$index = $this->indexOf($record, $address);
		$record['recipients'][$index]['readState'] = RecipientState::REPORTED;
		$record['recipients'][$index]['readAt'] = $this->now();
		$record['recipients'][$index]['readDetail'] = $detail;

		return $this->write($uuid, $record);

	}//end readReported()

	/**
	 * Append one retry attempt to a record.
	 *
	 * @param string $uuid The record uuid.
	 * @param string $actorUid Who retried.
	 * @param array<int,string> $addresses The recipients retried.
	 * @param bool $succeeded Whether the re-dispatch was accepted.
	 * @param string $detail What happened.
	 *
	 * @return ObjectEntity The updated record.
	 */
	public function appendAttempt(
		string $uuid,
		string $actorUid,
		array $addresses,
		bool $succeeded,
		string $detail,
	): ObjectEntity {
		$record = $this->read($uuid);
		$attempts = ($record['attempts'] ?? []);
		if (is_array($attempts) === false) {
			$attempts = [];
		}

		$outcome = self::OUTCOME_FAILED;
		if ($succeeded === true) {
			$outcome = self::OUTCOME_SUCCEEDED;
		}

		$attempts[] = [
			'at' => $this->now(),
			'by' => $actorUid,
			'recipients' => $addresses,
			'outcome' => $outcome,
			'detail' => $detail,
		];
		$record['attempts'] = $attempts;
		$record['retryCount'] = count($attempts);

		foreach ($record['recipients'] as $index => $recipient) {
			if (in_array((string)($recipient['address'] ?? ''), $addresses, true) === false) {
				continue;
			}

			if ($succeeded === true) {
				$record['recipients'][$index]['status'] = RecipientState::STATUS_SENT;
				$record['recipients'][$index]['failedStep'] = '';
				$record['recipients'][$index]['reason'] = '';
				$record['recipients'][$index]['handedOverAt'] = $this->now();
				continue;
			}

			$record['recipients'][$index]['status'] = RecipientState::STATUS_FAILED;
			$record['recipients'][$index]['reason'] = $detail;
		}

		$record['status'] = $this->deriveStatus($record['recipients']);

		return $this->write($uuid, $record);

	}//end appendAttempt()

	/**
	 * Read one record's payload.
	 *
	 * @param string $uuid The record uuid.
	 *
	 * @return array<string,mixed> The payload.
	 *
	 * @throws DoesNotExistException When there is no such record.
	 */
	public function read(string $uuid): array {
		$entity = $this->objectService->find(
			id: $uuid,
			register: self::REGISTER,
			schema: self::SCHEMA,
		);

		if (($entity instanceof ObjectEntity) === false) {
			throw new DoesNotExistException('No outbound message "' . $uuid . '".');
		}

		$record = $entity->getObject();
		if (is_array(($record['recipients'] ?? null)) === false) {
			$record['recipients'] = [];
		}

		if (is_array(($record['steps'] ?? null)) === false) {
			$record['steps'] = [];
		}

		return $record;

	}//end read()

	/**
	 * The status a set of recipient rows adds up to.
	 *
	 * @param array<int,array<string,mixed>> $recipients The rows.
	 *
	 * @return string The record status.
	 */
	public function deriveStatus(array $recipients): string {
		if ($recipients === []) {
			return self::STATUS_PENDING;
		}

		$sent = 0;
		$failed = 0;
		foreach ($recipients as $recipient) {
			$status = (string)($recipient['status'] ?? RecipientState::STATUS_PENDING);
			if ($status === RecipientState::STATUS_SENT) {
				$sent++;
				continue;
			}

			if ($status === RecipientState::STATUS_FAILED) {
				$failed++;
			}
		}

		if (($sent + $failed) < count($recipients)) {
			return self::STATUS_PENDING;
		}

		if ($failed === 0) {
			return self::STATUS_SENT;
		}

		if ($sent === 0) {
			return self::STATUS_FAILED;
		}

		return self::STATUS_PARTIAL;

	}//end deriveStatus()

	/**
	 * Build the recipient rows a new record starts with.
	 *
	 * An external address with no account is a recipient like any other: no
	 * account is created for it, and it is never quietly left out.
	 *
	 * @param array<int,array<string,mixed>> $recipients The recipients as the caller named them.
	 * @param string $channel The channel, which decides the initial states.
	 *
	 * @return array<int,array<string,mixed>> The rows.
	 */
	private function buildRecipients(array $recipients, string $channel): array {
		$rows = [];
		foreach ($recipients as $recipient) {
			if (is_string($recipient) === true) {
				$recipient = ['address' => $recipient];
			}

			if (is_array($recipient) === false) {
				continue;
			}

			$address = trim((string)($recipient['address'] ?? ''));
			if ($address === '') {
				continue;
			}

			$accountId = trim((string)($recipient['accountId'] ?? ''));
			$rows[] = [
				'address' => $address,
				'name' => (string)($recipient['name'] ?? ''),
				'accountId' => $accountId,
				'external' => ($accountId === ''),
				'status' => RecipientState::STATUS_PENDING,
				'deliveryState' => $this->capabilities->initialDeliveryState($channel),
				'readState' => $this->capabilities->initialReadState($channel),
				'failedStep' => '',
				'reason' => '',
				'reference' => '',
				'handedOverAt' => '',
			];
		}

		return $rows;

	}//end buildRecipients()

	/**
	 * Attachment metadata; the bytes are the transport's business.
	 *
	 * @param mixed $attachments The attachments as the caller passed them.
	 *
	 * @return array<int,array<string,mixed>> The metadata.
	 */
	private function describeAttachments(mixed $attachments): array {
		if (is_array($attachments) === false) {
			return [];
		}

		$described = [];
		foreach ($attachments as $attachment) {
			if (is_array($attachment) === false) {
				continue;
			}

			$described[] = [
				'name' => (string)($attachment['name'] ?? 'bijlage'),
				'mime' => (string)($attachment['mime'] ?? 'application/octet-stream'),
				'size' => (int)($attachment['size'] ?? 0),
				'fileRef' => (string)($attachment['fileRef'] ?? ''),
			];
		}

		return $described;

	}//end describeAttachments()

	/**
	 * The index of one recipient row.
	 *
	 * @param array<string,mixed> $record The record.
	 * @param string $address The recipient.
	 *
	 * @return int The index.
	 *
	 * @throws RuntimeException When the record has no such recipient, which would otherwise
	 *                          silently record an outcome against nobody.
	 */
	private function indexOf(array $record, string $address): int {
		foreach ($record['recipients'] as $index => $recipient) {
			if ((string)($recipient['address'] ?? '') === $address) {
				return (int)$index;
			}
		}

		throw new RuntimeException('This message has no recipient "' . $address . '".');

	}//end indexOf()

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
			register: self::REGISTER,
			schema: self::SCHEMA,
			uuid: $uuid,
		);

	}//end write()

	/**
	 * The current time as ISO 8601.
	 *
	 * @return string The timestamp.
	 */
	private function now(): string {
		return (new DateTimeImmutable())->format('c');

	}//end now()

}//end class

<?php

/**
 * Integriq MailIntakeService.
 *
 * Turns a parsed message into an integriq `message` object, detects the case
 * reference, and offers the message to whichever app owns cases through
 * {@see \OCA\Integriq\Event\MessageReceivedEvent}. When nobody claims it the
 * message is `unassigned` and its attachments are offered to filinq's intake
 * inbox. No case schema name appears anywhere in this file, on purpose.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Mail
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
 * @spec openspec/changes/mail-intake-creates-cases/specs/mail-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Mail;

use OCA\Integriq\Event\MessageReceivedEvent;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;

/**
 * Creates `message` objects and drives their intake lifecycle.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/mail-intake-creates-cases/specs/mail-intake/spec.md#requirement-a-received-message-is-offered-to-the-owning-app-as-a-typed-event-req-mail-003
 */
class MailIntakeService {

	/**
	 * The OpenRegister register holding integriq's objects.
	 *
	 * @var string
	 */
	public const REGISTER = 'integriq';

	/**
	 * The schema one received mail message is stored under.
	 *
	 * @var string
	 */
	public const SCHEMA_MESSAGE = 'mail_message';

	/**
	 * The `source.type` a mailbox is.
	 *
	 * @var string
	 */
	public const SOURCE_TYPE = 'mailbox';

	/**
	 * A message has been read in and is waiting for an owner.
	 *
	 * @var string
	 */
	public const STATUS_RECEIVED = 'received';

	/**
	 * A listener linked the message to an object it already had.
	 *
	 * @var string
	 */
	public const STATUS_LINKED = 'linked';

	/**
	 * A listener created an object for the message.
	 *
	 * @var string
	 */
	public const STATUS_CASE_CREATED = 'caseCreated';

	/**
	 * Nobody claimed the message.
	 *
	 * @var string
	 */
	public const STATUS_UNASSIGNED = 'unassigned';

	/**
	 * The intake channel this service writes on every document it hands over.
	 *
	 * @var string
	 */
	public const CHANNEL = 'mail';

	/**
	 * Constructor.
	 *
	 * @param ORObjectService $objectService Persists `message` objects.
	 * @param IEventDispatcher $eventDispatcher Dispatches the typed offer.
	 * @param CaseReferenceDetector $referenceDetector Reads the case reference out of the text.
	 * @param IntakeDocumentDispatcher $intakeDispatcher Hands attachments to filinq.
	 * @param LoggerInterface $logger Records refusals and fallbacks.
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
		private readonly IEventDispatcher $eventDispatcher,
		private readonly CaseReferenceDetector $referenceDetector,
		private readonly IntakeDocumentDispatcher $intakeDispatcher,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Take one parsed message in.
	 *
	 * A message whose `messageId` is already stored for this source is
	 * returned untouched, so a re-poll and a re-upload are both idempotent.
	 *
	 * @param string $sourceId The mailbox source id.
	 * @param ParsedMessage $message The parsed message.
	 * @param string|null $casePattern The source's `casePattern`, or null for the default.
	 *
	 * @return ObjectEntity The stored `message` object.
	 *
	 * @spec openspec/changes/mail-intake-creates-cases/specs/mail-intake/spec.md
	 */
	public function intake(string $sourceId, ParsedMessage $message, ?string $casePattern = null): ObjectEntity {
		$existing = $this->findByMessageId(sourceId: $sourceId, messageId: $message->getMessageId());
		if ($existing !== null) {
			return $existing;
		}

		$reference = $this->referenceDetector->detect($message, $casePattern);
		$payload = array_merge(
			$message->toObject(),
			[
				'sourceId' => $sourceId,
				'detectedReference' => (string)$reference,
				'status' => self::STATUS_RECEIVED,
			]
		);

		$stored = $this->objectService->saveObject(
			object: $payload,
			register: self::REGISTER,
			schema: self::SCHEMA_MESSAGE,
		);

		$event = new MessageReceivedEvent(
			message: $payload,
			detectedReference: $reference,
			messageUuid: (string)$stored->getUuid(),
			sourceId: $sourceId);
		$this->eventDispatcher->dispatchTyped($event);

		$outcome = $this->resolveStatus(event: $event);
		$payload['status'] = $outcome;
		$payload['outcome'] = (string)$event->getOutcome();
		$payload['linkedObject'] = (string)$event->getObjectRef();

		if ($outcome === self::STATUS_UNASSIGNED) {
			$payload['handedToIntake'] = $this->handToIntake(message: $message, messageUuid: (string)$stored->getUuid());
		}

		return $this->objectService->saveObject(
			object: $payload,
			register: self::REGISTER,
			schema: self::SCHEMA_MESSAGE,
			uuid: (string)$stored->getUuid(),
		);

	}//end intake()

	/**
	 * The stored message with this id on this source, when there is one.
	 *
	 * @param string $sourceId The mailbox source id.
	 * @param string $messageId The per-source message id.
	 *
	 * @return ObjectEntity|null The stored message, or null.
	 *
	 * @spec openspec/changes/mail-intake-creates-cases/specs/mail-intake/spec.md
	 */
	public function findByMessageId(string $sourceId, string $messageId): ?ObjectEntity {
		$matches = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema' => self::SCHEMA_MESSAGE,
					'sourceId' => $sourceId,
					'messageId' => $messageId,
				],
				'limit' => 1,
			]
		);

		$results = ($matches['results'] ?? $matches);
		if (is_array($results) === false || $results === []) {
			return null;
		}

		$first = reset($results);
		if (($first instanceof ObjectEntity) === false) {
			return null;
		}

		return $first;

	}//end findByMessageId()

	/**
	 * The lifecycle status an answered offer leads to.
	 *
	 * Silence and a decline lead to the same place: the message still needs
	 * somewhere to go.
	 *
	 * @param MessageReceivedEvent $event The answered event.
	 *
	 * @return string The status.
	 */
	private function resolveStatus(MessageReceivedEvent $event): string {
		return match ($event->getOutcome()) {
			MessageReceivedEvent::OUTCOME_LINKED => self::STATUS_LINKED,
			MessageReceivedEvent::OUTCOME_CREATED => self::STATUS_CASE_CREATED,
			default => self::STATUS_UNASSIGNED,
		};

	}//end resolveStatus()

	/**
	 * Offer every attachment of an unclaimed message to the intake inbox.
	 *
	 * @param ParsedMessage $message The parsed message.
	 * @param string $messageUuid The stored message's uuid, carried as `sourceRef`.
	 *
	 * @return int How many attachments the intake inbox took.
	 */
	private function handToIntake(ParsedMessage $message, string $messageUuid): int {
		$handed = 0;
		foreach ($message->getAttachments() as $attachment) {
			$dispatched = $this->intakeDispatcher->dispatch(
				[
					'channel' => self::CHANNEL,
					'name' => (string)($attachment['name'] ?? 'attachment'),
					'mime' => (string)($attachment['mime'] ?? 'application/octet-stream'),
					'size' => (int)($attachment['size'] ?? 0),
					'content' => base64_encode((string)($attachment['content'] ?? '')),
					'sender' => $message->getFrom(),
					'subject' => $message->getSubject(),
					'sourceRef' => $messageUuid,
					'receivedAt' => $message->getReceivedAt(),
				]
			);

			if ($dispatched === true) {
				$handed++;
			}
		}

		if ($handed === 0 && $message->getAttachments() !== []) {
			$this->logger->info(
				'Integriq mail intake: attachments stay in integriq, nothing took them.',
				['message' => $messageUuid]
			);
		}

		return $handed;

	}//end handToIntake()

}//end class

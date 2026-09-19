<?php

/**
 * Integriq IntakeDocumentDispatcher.
 *
 * Hands an attachment to filinq's document intake inbox. The event class is
 * filinq's, so this is a duck-typed lookup guarded by class_exists: when
 * filinq is not installed the attachment stays in integriq and the dispatcher
 * says so, rather than pretending it was delivered.
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

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Throwable;

/**
 * Dispatches one `IntakeDocumentReceivedEvent` per attachment.
 *
 * @spec openspec/changes/mail-intake-creates-cases/specs/mail-intake/spec.md#requirement-unassigned-attachments-go-to-the-document-intake-inbox-req-mail-004
 */
class IntakeDocumentDispatcher {

	/**
	 * The filinq event class this dispatcher looks for.
	 *
	 * @var string
	 */
	public const FILINQ_EVENT = 'OCA\\Filinq\\Event\\IntakeDocumentReceivedEvent';

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $eventDispatcher The Nextcloud event dispatcher.
	 * @param LoggerInterface $logger Records a refusal, never the attachment bytes.
	 * @param string $eventClass The event class to construct, injectable so tests can watch a real dispatch.
	 */
	public function __construct(
		private readonly IEventDispatcher $eventDispatcher,
		private readonly LoggerInterface $logger,
		private readonly string $eventClass = self::FILINQ_EVENT,
	) {

	}//end __construct()

	/**
	 * Whether the intake inbox is reachable at all.
	 *
	 * @return bool True when the event class is present on this instance.
	 */
	public function isAvailable(): bool {
		return class_exists($this->eventClass);

	}//end isAvailable()

	/**
	 * Offer one attachment to the intake inbox.
	 *
	 * @param array<string,mixed> $payload The intake payload: channel, name, mime, size, content,
	 *                                     sender, subject, sourceRef, receivedAt.
	 *
	 * @return bool True when the event was dispatched, false when nothing here can receive it.
	 *
	 * @spec openspec/changes/mail-intake-creates-cases/specs/mail-intake/spec.md
	 */
	public function dispatch(array $payload): bool {
		if ($this->isAvailable() === false) {
			$this->logger->info(
				'Integriq mail intake: no document intake inbox on this instance, the attachment stays in integriq.',
				['channel' => (string)($payload['channel'] ?? 'mail')]
			);
			return false;
		}

		try {
			$event = $this->construct(payload: $payload);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'Integriq mail intake: the document intake event could not be constructed.',
				['reason' => $exception->getMessage()]
			);
			return false;
		}

		if ($event === null) {
			return false;
		}

		$this->eventDispatcher->dispatchTyped($event);
		return true;

	}//end dispatch()

	/**
	 * Construct the intake event.
	 *
	 * The contract is one array argument. A class that wants something else is
	 * a contract this integriq does not know, and is refused rather than
	 * guessed at.
	 *
	 * @param array<string,mixed> $payload The intake payload.
	 *
	 * @return Event|null The event, or null when the constructor shape is unknown.
	 */
	private function construct(array $payload): ?Event {
		$reflection = new ReflectionClass($this->eventClass);
		$constructor = $reflection->getConstructor();
		if ($constructor === null || $constructor->getNumberOfParameters() !== 1) {
			$this->logger->warning(
				'Integriq mail intake: the document intake event does not take a single payload array.',
				['class' => $this->eventClass]
			);
			return null;
		}

		$event = $reflection->newInstance($payload);
		if (($event instanceof Event) === false) {
			$this->logger->warning(
				'Integriq mail intake: the document intake class is not an event.',
				['class' => $this->eventClass]
			);
			return null;
		}

		return $event;

	}//end construct()

}//end class

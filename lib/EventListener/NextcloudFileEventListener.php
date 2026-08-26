<?php

/**
 * Integriq Nextcloud File EventListener.
 *
 * Normalizes NC core file lifecycle events (`OCP\Files\Events\Node\*`) into
 * the CloudEvents `event` envelope and hands them to the same
 * filter/deliver/retry/sign/dead-letter pipeline OpenRegister object events
 * already use.
 *
 * @category EventListener
 * @package  OCA\Integriq\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/nextcloud-event-triggers/spec.md#requirement-file-events-must-be-normalized-to-cloudevents-req-001
 */

declare(strict_types=1);

namespace OCA\Integriq\EventListener;

use OCA\Integriq\Service\EventService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\AbstractNodeEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use Psr\Log\LoggerInterface;

/**
 * Listener that normalizes `OCP\Files\Events\Node\*` events into CloudEvents.
 *
 * Registered unconditionally in `Application.php::register()` — `NodeCreatedEvent`,
 * `NodeWrittenEvent`, and `NodeDeletedEvent` are stable public `OCP` API present
 * on every supported Nextcloud version this app targets (NC 28-34;
 * `NodeCreatedEvent` has shipped since NC 20.0.0), so no feature-detection is
 * required (REQ-001).
 *
 * Same firehose gate as {@see CloudEventListener}: when the instance has zero
 * active `event_subscription`s, no `event` OR-object is persisted for ANY
 * file mutation fleet-wide.
 *
 * @spec openspec/specs/nextcloud-event-triggers/spec.md#requirement-file-events-must-be-normalized-to-cloudevents-req-001
 */
class NextcloudFileEventListener implements IEventListener {

	/**
	 * The CloudEvents `source` this producer stamps on every event, and the
	 * discriminator {@see \OCA\Integriq\Controller\EventsController}
	 * uses for Nextcloud-event provenance filtering (dead-letter-replay
	 * REQ-DLR-007).
	 *
	 * @var string
	 */
	private const SOURCE = '/nextcloud/files';

	/**
	 * Constructor.
	 *
	 * @param EventService $eventService Service for managing CloudEvents.
	 * @param LoggerInterface $logger Logger instance.
	 */
	public function __construct(
		private readonly EventService $eventService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle a fired file lifecycle event by normalizing and forwarding it.
	 *
	 * @param Event $event The incoming event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/nextcloud-event-triggers/spec.md#requirement-file-events-must-be-normalized-to-cloudevents-req-001
	 */
	public function handle(Event $event): void {
		// Guard on the common AbstractNodeEvent parent first so the later
		// getNode() call is statically known to be safe; the three concrete
		// subclasses discriminate the CloudEvents `type`.
		if ($event instanceof AbstractNodeEvent === false) {
			return;
		}

		$type = null;
		if ($event instanceof NodeCreatedEvent) {
			$type = 'com.nextcloud.files.node.created';
		} elseif ($event instanceof NodeWrittenEvent) {
			$type = 'com.nextcloud.files.node.updated';
		} elseif ($event instanceof NodeDeletedEvent) {
			$type = 'com.nextcloud.files.node.deleted';
		}

		if ($type === null) {
			return;
		}

		try {
			// Firehose gate: no configured subscriptions anywhere on this
			// instance means the outbound-webhooks capability is unused — do
			// not pay a persistence cost for every file mutation fleet-wide.
			if ($this->eventService->hasActiveSubscriptions() === false) {
				return;
			}

			$node = $event->getNode();
			$owner = $node->getOwner();
			$ownerUid = null;
			if ($owner !== null) {
				$ownerUid = $owner->getUID();
			}

			$this->eventService->handleNextcloudEvent(
				type: $type,
				payload: [
					'source' => self::SOURCE,
					'subject' => (string)$node->getId(),
					'data' => [
						'path' => $node->getPath(),
						'fileid' => $node->getId(),
						'mimetype' => $node->getMimetype(),
						'owner' => $ownerUid,
					],
					'userId' => $ownerUid,
				]
			);
		} catch (\Throwable $e) {
			// Broad catch is deliberate: this listener runs synchronously
			// inside the file operation that triggered it. A failure here
			// must never unwind into — and 500 — that unrelated operation.
			$this->logger->error(
				'Failed to process Nextcloud file event: ' . $e->getMessage(),
				[
					'exception' => $e,
					'event' => get_class($event),
				]
			);
		}//end try

	}//end handle()
}//end class

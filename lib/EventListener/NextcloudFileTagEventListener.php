<?php

/**
 * Integriq Nextcloud File Tag EventListener.
 *
 * Normalizes NC core system-tag assignment/removal events
 * (`OCP\SystemTag\MapperEvent`) — filtered to file objects — into the
 * CloudEvents `event` envelope, distinctly typed from file create/update/
 * delete.
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
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\MapperEvent;
use Psr\Log\LoggerInterface;

/**
 * Listener that normalizes file-object `OCP\SystemTag\MapperEvent`
 * assign/unassign events into CloudEvents.
 *
 * `MapperEvent` covers every taggable object type (files, but also e.g.
 * calendar resources on some installs); this listener filters to
 * `objectType === 'files'` only — non-file tag changes are ignored, they
 * are not part of the `nextcloud-event-triggers` `files` family (REQ-001).
 *
 * Registered unconditionally — `OCP\SystemTag\MapperEvent` is stable public
 * `OCP` API since NC 9.0.0.
 *
 * @spec openspec/specs/nextcloud-event-triggers/spec.md#requirement-file-events-must-be-normalized-to-cloudevents-req-001
 */
class NextcloudFileTagEventListener implements IEventListener {

	/**
	 * The CloudEvents `source` this producer stamps on every event.
	 *
	 * @var string
	 */
	private const SOURCE = '/nextcloud/files';

	/**
	 * The `MapperEvent::getObjectType()` value for file tag assignments.
	 *
	 * @var string
	 */
	private const OBJECT_TYPE_FILES = 'files';

	/**
	 * Constructor.
	 *
	 * @param EventService $eventService Service for managing CloudEvents.
	 * @param ISystemTagManager $systemTagManager Resolves tag ids to human-readable names.
	 * @param LoggerInterface $logger Logger instance.
	 */
	public function __construct(
		private readonly EventService $eventService,
		private readonly ISystemTagManager $systemTagManager,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle a fired system-tag mapper event by normalizing and forwarding it
	 * when it targets a file object.
	 *
	 * @param Event $event The incoming event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/nextcloud-event-triggers/spec.md#requirement-file-events-must-be-normalized-to-cloudevents-req-001
	 */
	public function handle(Event $event): void {
		if ($event instanceof MapperEvent === false) {
			return;
		}

		if ($event->getObjectType() !== self::OBJECT_TYPE_FILES) {
			return;
		}

		$mapperEventType = $event->getEvent();
		if ($mapperEventType !== MapperEvent::EVENT_ASSIGN
			&& $mapperEventType !== MapperEvent::EVENT_UNASSIGN
		) {
			return;
		}

		try {
			// Firehose gate: no configured subscriptions anywhere on this
			// instance means the outbound-webhooks capability is unused — do
			// not pay a persistence cost for every tag mutation fleet-wide.
			if ($this->eventService->hasActiveSubscriptions() === false) {
				return;
			}

			$tagIds = $event->getTags();
			$tagNames = $this->resolveTagNames(tagIds: $tagIds);
			$action = 'unassigned';
			if ($mapperEventType === MapperEvent::EVENT_ASSIGN) {
				$action = 'assigned';
			}

			$this->eventService->handleNextcloudEvent(
				type: 'com.nextcloud.files.node.tagged',
				payload: [
					'source' => self::SOURCE,
					'subject' => $event->getObjectId(),
					'data' => [
						'fileid' => $event->getObjectId(),
						'action' => $action,
						'tagIds' => $tagIds,
						'tagNames' => $tagNames,
					],
				]
			);
		} catch (\Throwable $e) {
			// Broad catch is deliberate: this listener runs synchronously
			// inside the tagging operation that triggered it.
			$this->logger->error(
				'Failed to process Nextcloud file tag event: ' . $e->getMessage(),
				[
					'exception' => $e,
					'event' => get_class($event),
				]
			);
		}//end try

	}//end handle()

	/**
	 * Resolve tag ids to their human-readable names, defensively — a tag may
	 * already be deleted by the time this listener runs, in which case the
	 * id-only fallback is used rather than throwing.
	 *
	 * @param array $tagIds The system tag ids from the fired event.
	 *
	 * @return array<int, string> Tag names (or the raw id, stringified, when resolution fails).
	 *
	 * @spec openspec/specs/nextcloud-event-triggers/spec.md#requirement-file-events-must-be-normalized-to-cloudevents-req-001
	 */
	private function resolveTagNames(array $tagIds): array {
		if (empty($tagIds) === true) {
			return [];
		}

		try {
			$tags = $this->systemTagManager->getTagsByIds($tagIds);
		} catch (\Throwable $e) {
			return array_map(static fn ($id) => (string)$id, $tagIds);
		}

		$names = [];
		foreach ($tags as $tag) {
			$names[] = $tag->getName();
		}

		return $names;
	}//end resolveTagNames()
}//end class

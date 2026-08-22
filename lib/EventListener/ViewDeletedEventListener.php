<?php

/**
 * Integriq ViewDeleted EventListener.
 *
 * Listens for OpenRegister ObjectDeletedEvent on view objects in the
 * Software Catalog application and removes the matching extended view
 * objects.
 *
 * @category EventListener
 * @package  OCA\Integriq\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * @todo Remove this temporary listener once it lives in the software catalog application.
 */

namespace OCA\Integriq\EventListener;

use OCA\Integriq\Cron\DeferredViewCascadeJob;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Queues removal of the extended views belonging to a deleted view.
 *
 * ADR-078: `ObjectDeletedEvent` is a POST event — the view is already gone by
 * the time this runs, and nothing this listener does can change that. It
 * therefore does no work on the request: it resolves the deleted view's
 * `identifier` (which is only readable from the event payload) and hands it to
 * {@see DeferredViewCascadeJob} through OpenRegister's
 * {@see ListenerDeferralService}, which carries the acting user into the job.
 *
 * What used to happen here instead: an UNBOUNDED `findAll()` over the
 * `extendview` schema followed by one `delete()` per matching row, charged to
 * the latency of the user's delete request.
 *
 * The identifier is captured here rather than re-resolved in the job on
 * purpose. OpenRegister's `DeferredEntryObjectResolver` treats a soft-deleted
 * object as a stale no-op and returns null, so a delete cascade that re-fetched
 * its own subject would find nothing and report success.
 */
class ViewDeletedEventListener implements IEventListener {

	/**
	 * Register slug this listener reacts to.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'vng-gemma';

	/**
	 * Schema slug of the deleted object this listener reacts to.
	 *
	 * @var string
	 */
	private const VIEW_SCHEMA_SLUG = 'view';

	/**
	 * Schema slug of the dependent objects the cascade removes.
	 *
	 * @var string
	 */
	private const EXTEND_VIEW_SCHEMA_SLUG = 'extendview';

	/**
	 * Constructor.
	 *
	 * @param SchemaMapper            $schemaMapper   Schema mapper used to resolve view + extendview schemas.
	 * @param RegisterMapper          $registerMapper Register mapper used to resolve the vng-gemma register.
	 * @param ListenerDeferralService $deferral       Actor-forwarding deferral service.
	 * @param LoggerInterface         $logger         The logger.
	 */
	public function __construct(
		private readonly SchemaMapper $schemaMapper,
		private readonly RegisterMapper $registerMapper,
		private readonly ListenerDeferralService $deferral,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle a fired event.
	 *
	 * @param Event $event Event payload to handle.
	 *
	 * @return void
	 */
	public function handle(Event $event): void {
		// Filter out all events that are not an ObjectDeletedEvent.
		if ($event instanceof ObjectDeletedEvent === false) {
			return;
		}

		// Make sure that we have the proper register and schema.
		$object = $event->getObject();
		try {
			$register = $this->registerMapper->find($object->getRegister());
			if ($register->getSlug() !== self::REGISTER_SLUG
				|| $this->schemaMapper->find($object->getSchema())->getSlug() !== self::VIEW_SCHEMA_SLUG
			) {
				return;
			}

			$extendViewSchema = $this->schemaMapper->find(self::EXTEND_VIEW_SCHEMA_SLUG);
		} catch (\Throwable $e) {
			// A register/schema this instance does not have is the normal case
			// on any deployment that is not the Software Catalog — it must never
			// break the delete that triggered us.
			$this->logger->debug(
				'Integriq: view-delete cascade skipped, register or schema not resolvable',
				['exception' => $e->getMessage()]
			);
			return;
		}//end try

		$identifier = ($object->jsonSerialize()['identifier'] ?? null);
		if (is_string($identifier) === false || $identifier === '') {
			return;
		}

		$this->deferral->defer(
			jobClass: DeferredViewCascadeJob::class,
			entry: [
				'identifier' => $identifier,
				'register'   => $register->getId(),
				'schema'     => $extendViewSchema->getId(),
			],
			dedupeKey: $register->getId() . '|' . $extendViewSchema->getId() . '|' . $identifier
		);
	}//end handle()
}//end class

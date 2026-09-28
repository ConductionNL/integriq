<?php

/**
 * Integriq SourceOwnedDeleteGuardListener.
 *
 * Refuses an ordinary delete of a record a source owns, on OpenRegister's own
 * delete path, so the refusal holds whichever page or app deletes it.
 *
 * @category EventListener
 * @package  OCA\Integriq\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/records-owned-by-an-external-source/specs/source-owned-records/spec.md#requirement-a-local-delete-of-a-source-owned-record-is-refused-unless-somebody-says-why-req-sor-005
 */

declare(strict_types=1);

namespace OCA\Integriq\EventListener;

use InvalidArgumentException;
use OCA\Integriq\Service\Ownership\LocalDeleteGuard;
use OCA\Integriq\Service\Ownership\RecordOwnershipService;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stops OpenRegister deleting a source-owned record without a stated reason.
 *
 * LocalDeleteGuard used to run only inside `DELETE /api/ownership/{id}`, so the
 * delete button on any index or detail page, which goes straight to
 * OpenRegister, removed a BRP person or a KVK company without a word. The
 * guard now answers OpenRegister's stoppable ObjectDeletingEvent, which every
 * delete passes through. Two deletes go ahead: one that carries the override
 * OwnershipController wrote onto the object (a reason was given), and the
 * synchronisation engine's own delete, because the source removing its record
 * is the owner acting, not somebody local.
 *
 * @spec openspec/changes/records-owned-by-an-external-source/specs/source-owned-records/spec.md#requirement-a-local-delete-of-a-source-owned-record-is-refused-unless-somebody-says-why-req-sor-005
 *
 * @template-implements IEventListener<Event>
 */
class SourceOwnedDeleteGuardListener implements IEventListener {

	/**
	 * How many engine deletes are in progress in this request.
	 *
	 * @var int
	 */
	private static int $engineDeletes = 0;

	/**
	 * Constructor.
	 *
	 * @param RecordOwnershipService $ownership Answers who owns the record.
	 * @param LocalDeleteGuard $guard Decides, and words the refusal.
	 * @param LoggerInterface $logger Names a guard that failed.
	 */
	public function __construct(
		private readonly RecordOwnershipService $ownership,
		private readonly LocalDeleteGuard $guard,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Run a delete the synchronisation engine makes, past the guard.
	 *
	 * @param callable $delete The delete.
	 *
	 * @return mixed What the delete returned.
	 *
	 * @spec openspec/changes/records-owned-by-an-external-source/specs/source-owned-records/spec.md#requirement-a-local-delete-of-a-source-owned-record-is-refused-unless-somebody-says-why-req-sor-005
	 */
	public static function whileTheEngineDeletes(callable $delete): mixed {
		self::$engineDeletes++;
		try {
			return $delete();
		} finally {
			self::$engineDeletes--;
		}

	}//end whileTheEngineDeletes()

	/**
	 * Refuse the delete of a source-owned record that carries no override.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/records-owned-by-an-external-source/specs/source-owned-records/spec.md#requirement-a-local-delete-of-a-source-owned-record-is-refused-unless-somebody-says-why-req-sor-005
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectDeletingEvent) === false || self::$engineDeletes > 0) {
			return;
		}

		try {
			$entity = $event->getObject();
			$data = $entity->getObject();
			$override = ($data[LocalDeleteGuard::OVERRIDE_KEY] ?? null);
			if (is_array($override) === true && trim((string)($override['reason'] ?? '')) !== '') {
				return;
			}

			$uuid = (string)$entity->getUuid();
			if ($uuid === '') {
				return;
			}

			$ownership = $this->ownership->forObject($uuid);
			$this->guard->guard($ownership);
		} catch (InvalidArgumentException $refusal) {
			$event->setErrors(
				[
					'code' => 'source_owned',
					'message' => $refusal->getMessage(),
					'status' => 409,
				]
			);
			$event->stopPropagation();
		} catch (Throwable $failure) {
			// A guard that cannot read the contracts must not become the
			// reason nothing on the instance can be deleted.
			$this->logger->warning(
				'[integriq] the source-owned delete guard failed, allowing the delete: ' . $failure->getMessage(),
				['exception' => $failure]
			);
		}//end try

	}//end handle()

}//end class

<?php

/**
 * Integriq SyncDeadLetterController.
 *
 * Controller for listing/inspecting/replaying/discarding dead-lettered
 * synchronization items (`sync_item_dead_letter`). Mirrors EventsController's
 * dead-letter method shapes (see openconnector-dead-letter-replay).
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-sync-item-dead-letter-listing-with-filters-and-pagination-req-dlr-007
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Service\SyncItemDeadLetterServiceInterface;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Admin-only, CSRF-protected REST surface for sync-item dead letters.
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 *
 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-sync-item-dead-letter-listing-with-filters-and-pagination-req-dlr-007
 */
class SyncDeadLetterController extends Controller {
	/**
	 * Constructor for the SyncDeadLetterController.
	 *
	 * @param string $appName The name of the app.
	 * @param IRequest $request The request object.
	 * @param OrObjectService $orObjectService The OR object service.
	 * @param SyncItemDeadLetterServiceInterface $syncItemDeadLetterService The dead-letter capture/replay/discard service.
	 * @param IL10N $l The localization service.
	 * @param IUserSession $userSession The user session.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly OrObjectService $orObjectService,
		private readonly SyncItemDeadLetterServiceInterface $syncItemDeadLetterService,
		private readonly IL10N $l,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * List `sync_item_dead_letter` entries with filters and pagination
	 * (REQ-DLR-007). Default status filter is `failed`.
	 *
	 * @return JSONResponse The filtered listing.
	 *
	 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-sync-item-dead-letter-listing-with-filters-and-pagination-req-dlr-007
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function index(): JSONResponse {
		$statusParam = (string)$this->request->getParam('status', '');
		if ($statusParam !== '') {
			$statuses = array_values(array_filter(array_map('trim', explode(',', $statusParam))));
		} else {
			$statuses = ['failed'];
		}

		$filters = [
			'register' => 'integriq',
			'schema' => 'sync_item_dead_letter',
			'status' => $statuses,
		];

		$synchronizationId = $this->request->getParam('synchronizationId');
		if ($synchronizationId !== null && $synchronizationId !== '') {
			$filters['synchronization'] = (string)$synchronizationId;
		}

		$matches = $this->orObjectService->findAll(
			config: [
				'filters' => $filters,
				'limit' => (int)$this->request->getParam('limit', 50),
				'offset' => (int)$this->request->getParam('offset', 0),
			]
		);
		$entries = ($matches['results'] ?? $matches);

		$from = $this->request->getParam('from');
		$to = $this->request->getParam('to');
		$rows = [];
		foreach ($entries as $entry) {
			$data = $entry->getObject();
			if (in_array(($data['status'] ?? ''), $statuses, true) === false) {
				continue;
			}

			if ($this->withinWindow(created: ($data['created'] ?? null), from: $from, to: $to) === false) {
				continue;
			}

			$data['errorPreview'] = $this->truncate(value: (string)($data['error'] ?? ''), length: 200);
			$rows[] = $data;
		}

		return new JSONResponse(['results' => $rows, 'total' => count($rows)]);
	}//end index()

	/**
	 * Whether a `created` timestamp falls within an optional [from, to] window.
	 *
	 * @param string|null $created The entry's created timestamp.
	 * @param string|null $from Lower bound (ISO 8601), or null.
	 * @param string|null $to Upper bound (ISO 8601), or null.
	 *
	 * @return boolean
	 */
	private function withinWindow(?string $created, ?string $from, ?string $to): bool {
		return (new TimeWindow(from: $from, to: $to))->contains(timestamp: $created);
	}//end withinWindow()

	/**
	 * Truncate a string to a preview length.
	 *
	 * @param string $value The value to truncate.
	 * @param integer $length Maximum length.
	 *
	 * @return string The truncated value.
	 */
	private function truncate(string $value, int $length): string {
		if (strlen($value) <= $length) {
			return $value;
		}

		return (substr($value, 0, $length) . '…');
	}//end truncate()

	/**
	 * Show full detail for one dead-lettered sync item, including the
	 * resolved synchronization context (REQ-DLR-008).
	 *
	 * @param string $id The dead-letter entry UUID.
	 *
	 * @return JSONResponse The entry detail, or 404 when it does not exist.
	 *
	 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-sync-item-dead-letter-inspection-req-dlr-008
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function show(string $id): JSONResponse {
		try {
			$entry = $this->orObjectService->find(
				id: $id,
				register: 'integriq',
				schema: 'sync_item_dead_letter',
				_rbac: false
			);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => $this->l->t('Entry not found')], 404);
		}

		$data = $entry->getObject();
		$synchronizationCtx = null;
		$synchronizationId = ($data['synchronization'] ?? null);
		if ($synchronizationId !== null && $synchronizationId !== '') {
			try {
				$synchronization = $this->orObjectService->find(
					id: (string)$synchronizationId,
					register: 'integriq',
					schema: 'synchronization',
					_rbac: false
				);
				$syncData = $synchronization->getObject();
				$synchronizationCtx = [
					'name' => ($syncData['name'] ?? null),
					'sourceId' => ($syncData['sourceId'] ?? null),
					'targetId' => ($syncData['targetId'] ?? null),
				];
			} catch (DoesNotExistException $e) {
				$synchronizationCtx = null;
			}
		}

		return new JSONResponse(
			[
				'entry' => $data,
				'synchronization' => $synchronizationCtx,
			]
		);

	}//end show()

	/**
	 * Replay a single dead-lettered sync item (REQ-DLR-009).
	 *
	 * @param string $id The dead-letter entry UUID.
	 *
	 * @return JSONResponse The updated entry, or 404/409 on error.
	 *
	 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-audited-manual-replay-of-a-dead-lettered-sync-item-req-dlr-009
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function replay(string $id): JSONResponse {
		return $this->deadLetters()->single(verb: 'replay', id: $id);
	}//end replay()

	/**
	 * Discard a single dead-lettered sync item (REQ-DLR-010).
	 *
	 * @param string $id The dead-letter entry UUID.
	 *
	 * @return JSONResponse The updated entry, or 404/409 on error.
	 *
	 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-audited-discard-of-a-dead-lettered-sync-item-req-dlr-010
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function discard(string $id): JSONResponse {
		return $this->deadLetters()->single(verb: 'discard', id: $id);
	}//end discard()

	/**
	 * Bulk replay up to 100 dead-lettered sync items, reporting per-id
	 * outcomes (REQ-DLR-011).
	 *
	 * @return JSONResponse A per-id result map, or 400 when the id cap is exceeded.
	 *
	 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-bulk-replay-and-discard-for-sync-item-dead-letters-req-dlr-011
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function bulkReplay(): JSONResponse {
		return $this->deadLetters()->bulk(verb: 'replay', ids: $this->request->getParam('ids'));
	}//end bulkReplay()

	/**
	 * Bulk discard up to 100 dead-lettered sync items, reporting per-id
	 * outcomes (REQ-DLR-011).
	 *
	 * @return JSONResponse A per-id result map, or 400 when the id cap is exceeded.
	 *
	 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-bulk-replay-and-discard-for-sync-item-dead-letters-req-dlr-011
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function bulkDiscard(): JSONResponse {
		return $this->deadLetters()->bulk(verb: 'discard', ids: $this->request->getParam('ids'));
	}//end bulkDiscard()

	/**
	 * The dead-letter actions of this controller.
	 *
	 * @return DeadLetterActions
	 */
	private function deadLetters(): DeadLetterActions {
		return new DeadLetterActions(
			entries: $this->syncItemDeadLetterService,
			userSession: $this->userSession,
			l: $this->l,
			wording: new DeadLetterWording(
				notFound: $this->l->t('Entry not found'),
				idsNotAList: $this->l->t('ids must be an array of entry UUIDs')
			)
		);
	}//end deadLetters()
}//end class

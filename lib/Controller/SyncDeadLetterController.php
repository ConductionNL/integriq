<?php
/**
 * OpenConnector SyncDeadLetterController.
 *
 * Controller for listing/inspecting/replaying/discarding dead-lettered
 * synchronization items (`sync_item_dead_letter`). Mirrors EventsController's
 * dead-letter method shapes (see openconnector-dead-letter-replay).
 *
 * @category Controller
 * @package  OCA\OpenConnector\Controller
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
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-sync-item-dead-letter-listing-with-filters-and-pagination-req-dlr-007
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Controller;

use Exception;
use OCA\OpenConnector\Exception\InvalidMessageStateException;
use OCA\OpenConnector\Service\SyncItemDeadLetterService;
use OCA\OpenConnector\Settings\OpenConnectorAdmin;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
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
class SyncDeadLetterController extends Controller
{
    /**
     * Constructor for the SyncDeadLetterController.
     *
     * @param string                    $appName                   The name of the app.
     * @param IRequest                  $request                   The request object.
     * @param OrObjectService           $orObjectService           The OR object service.
     * @param SyncItemDeadLetterService $syncItemDeadLetterService The dead-letter capture/replay/discard service.
     * @param IL10N                     $l                         The localization service.
     * @param IUserSession              $userSession               The user session.
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly OrObjectService $orObjectService,
        private readonly SyncItemDeadLetterService $syncItemDeadLetterService,
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
    #[AuthorizedAdminSetting(OpenConnectorAdmin::class)]
    public function index(): JSONResponse
    {
        $statusParam = (string) $this->request->getParam('status', '');
        if ($statusParam !== '') {
            $statuses = array_values(array_filter(array_map('trim', explode(',', $statusParam))));
        } else {
            $statuses = ['failed'];
        }

        $filters = [
            'register' => 'openconnector',
            'schema'   => 'sync_item_dead_letter',
            'status'   => $statuses,
        ];

        $synchronizationId = $this->request->getParam('synchronizationId');
        if ($synchronizationId !== null && $synchronizationId !== '') {
            $filters['synchronization'] = (string) $synchronizationId;
        }

        $matches = $this->orObjectService->findAll(
                config: [
                    'filters' => $filters,
                    'limit'   => (int) $this->request->getParam('limit', 50),
                    'offset'  => (int) $this->request->getParam('offset', 0),
                ]
                );
        $entries = ($matches['results'] ?? $matches);

        $from = $this->request->getParam('from');
        $to   = $this->request->getParam('to');
        $rows = [];
        foreach ($entries as $entry) {
            $data = $entry->getObject();
            if (in_array(($data['status'] ?? ''), $statuses, true) === false) {
                continue;
            }

            if ($this->withinWindow(created: ($data['created'] ?? null), from: $from, to: $to) === false) {
                continue;
            }

            $data['errorPreview'] = $this->truncate(value: (string) ($data['error'] ?? ''), length: 200);
            $rows[] = $data;
        }

        return new JSONResponse(['results' => $rows, 'total' => count($rows)]);

    }//end index()

    /**
     * Whether a `created` timestamp falls within an optional [from, to] window.
     *
     * @param string|null $created The entry's created timestamp.
     * @param string|null $from    Lower bound (ISO 8601), or null.
     * @param string|null $to      Upper bound (ISO 8601), or null.
     *
     * @return boolean
     */
    private function withinWindow(?string $created, ?string $from, ?string $to): bool
    {
        if (($from === null || $from === '') && ($to === null || $to === '')) {
            return true;
        }

        if ($created === null || $created === '') {
            return false;
        }

        $ts = strtotime($created);
        if ($ts === false) {
            return false;
        }

        if ($from !== null && $from !== '' && $ts < strtotime($from)) {
            return false;
        }

        if ($to !== null && $to !== '' && $ts > strtotime($to)) {
            return false;
        }

        return true;

    }//end withinWindow()

    /**
     * Truncate a string to a preview length.
     *
     * @param string  $value  The value to truncate.
     * @param integer $length Maximum length.
     *
     * @return string The truncated value.
     */
    private function truncate(string $value, int $length): string
    {
        if (strlen($value) <= $length) {
            return $value;
        }

        return (substr($value, 0, $length).'…');

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
    #[AuthorizedAdminSetting(OpenConnectorAdmin::class)]
    public function show(string $id): JSONResponse
    {
        try {
            $entry = $this->orObjectService->find(
                id: $id,
                register: 'openconnector',
                schema: 'sync_item_dead_letter',
                _rbac: false,
                _multitenancy: false
            );
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => $this->l->t('Entry not found')], 404);
        }

        $data = $entry->getObject();
        $synchronizationCtx = null;
        $synchronizationId  = ($data['synchronization'] ?? null);
        if ($synchronizationId !== null && $synchronizationId !== '') {
            try {
                $synchronization    = $this->orObjectService->find(
                    id: (string) $synchronizationId,
                    register: 'openconnector',
                    schema: 'synchronization',
                    _rbac: false,
                    _multitenancy: false
                );
                $syncData           = $synchronization->getObject();
                $synchronizationCtx = [
                    'name'     => ($syncData['name'] ?? null),
                    'sourceId' => ($syncData['sourceId'] ?? null),
                    'targetId' => ($syncData['targetId'] ?? null),
                ];
            } catch (DoesNotExistException $e) {
                $synchronizationCtx = null;
            }
        }

        return new JSONResponse(
                [
                    'entry'           => $data,
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
    #[AuthorizedAdminSetting(OpenConnectorAdmin::class)]
    public function replay(string $id): JSONResponse
    {
        $actor = $this->currentUid();

        try {
            $updated = $this->syncItemDeadLetterService->replayMessage(id: $id, actorUid: $actor);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => $this->l->t('Entry not found')], 404);
        } catch (InvalidMessageStateException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
        }

        return new JSONResponse($updated->getObject());

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
    #[AuthorizedAdminSetting(OpenConnectorAdmin::class)]
    public function discard(string $id): JSONResponse
    {
        $actor = $this->currentUid();

        try {
            $updated = $this->syncItemDeadLetterService->discardMessage(id: $id, actorUid: $actor);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => $this->l->t('Entry not found')], 404);
        } catch (InvalidMessageStateException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
        }

        return new JSONResponse($updated->getObject());

    }//end discard()

    /**
     * Bulk replay up to 100 dead-lettered sync items, reporting per-id
     * outcomes (REQ-DLR-011).
     *
     * @return JSONResponse A per-id result map, or 400 when the id cap is exceeded.
     *
     * @spec openspec/specs/dead-letter-replay/spec.md#requirement-bulk-replay-and-discard-for-sync-item-dead-letters-req-dlr-011
     */
    #[AuthorizedAdminSetting(OpenConnectorAdmin::class)]
    public function bulkReplay(): JSONResponse
    {
        return $this->bulkApply(verb: 'replay');

    }//end bulkReplay()

    /**
     * Bulk discard up to 100 dead-lettered sync items, reporting per-id
     * outcomes (REQ-DLR-011).
     *
     * @return JSONResponse A per-id result map, or 400 when the id cap is exceeded.
     *
     * @spec openspec/specs/dead-letter-replay/spec.md#requirement-bulk-replay-and-discard-for-sync-item-dead-letters-req-dlr-011
     */
    #[AuthorizedAdminSetting(OpenConnectorAdmin::class)]
    public function bulkDiscard(): JSONResponse
    {
        return $this->bulkApply(verb: 'discard');

    }//end bulkDiscard()

    /**
     * Apply a dead-letter verb to an explicit, capped set of entry UUIDs.
     *
     * Partial failures never abort the batch; each id reports its own
     * outcome (`ok`, `not-found`, `invalid-state`, or `error`).
     *
     * @param string $verb Either `replay` or `discard`.
     *
     * @return JSONResponse Per-id result map, or 400 on cap/shape violations.
     *
     * @spec openspec/specs/dead-letter-replay/spec.md#requirement-bulk-replay-and-discard-for-sync-item-dead-letters-req-dlr-011
     */
    private function bulkApply(string $verb): JSONResponse
    {
        $ids = $this->request->getParam('ids');
        if (is_array($ids) === false) {
            return new JSONResponse(['error' => $this->l->t('ids must be an array of entry UUIDs')], 400);
        }

        if (count($ids) > 100) {
            return new JSONResponse(['error' => $this->l->t('A maximum of 100 ids may be processed per call')], 400);
        }

        $actor   = $this->currentUid();
        $results = [];
        foreach ($ids as $id) {
            $id = (string) $id;
            try {
                if ($verb === 'replay') {
                    $this->syncItemDeadLetterService->replayMessage(id: $id, actorUid: $actor);
                } else {
                    $this->syncItemDeadLetterService->discardMessage(id: $id, actorUid: $actor);
                }

                $results[$id] = 'ok';
            } catch (DoesNotExistException $e) {
                $results[$id] = 'not-found';
            } catch (InvalidMessageStateException $e) {
                $results[$id] = 'invalid-state';
            } catch (Exception $e) {
                $results[$id] = 'error';
            }//end try
        }//end foreach

        return new JSONResponse(['results' => $results]);

    }//end bulkApply()

    /**
     * Resolve the acting operator's user id for audit stamping.
     *
     * @return string The current user's uid, or 'unknown' when unavailable.
     */
    private function currentUid(): string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return 'unknown';
        }

        return $user->getUID();

    }//end currentUid()
}//end class

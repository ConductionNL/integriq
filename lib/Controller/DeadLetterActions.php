<?php

/**
 * Integriq DeadLetterActions.
 *
 * The replay, discard and bulk routes the CloudEvents and the sync-item
 * dead-letter controllers share: a single action answers the updated object,
 * 404 for an unknown id or 409 for an invalid state; a bulk action takes an
 * explicit list of at most 100 ids and reports each id's own outcome. Every
 * action is audited as the session user, or `unknown`, read once per request.
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
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-bulk-replay-and-discard-for-sync-item-dead-letters-req-dlr-011
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use Exception;
use OCA\Integriq\Exception\InvalidMessageStateException;
use OCA\Integriq\Service\EventServiceInterface;
use OCA\Integriq\Service\SyncItemDeadLetterServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IUserSession;

/**
 * Replays and discards dead-lettered entries for one controller.
 *
 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-bulk-replay-and-discard-for-sync-item-dead-letters-req-dlr-011
 */
final class DeadLetterActions {

	/**
	 * The acting operator's uid, once read.
	 *
	 * @var string|null
	 */
	private ?string $actor = null;

	/**
	 * Constructor.
	 *
	 * @param EventServiceInterface|SyncItemDeadLetterServiceInterface $entries     Replays and discards the entries.
	 * @param IUserSession                                             $userSession The acting operator.
	 * @param IL10N                                                    $l           The shared 400 sentence.
	 * @param DeadLetterWording                                        $wording     The controller's own sentences.
	 */
	public function __construct(
		private readonly EventServiceInterface|SyncItemDeadLetterServiceInterface $entries,
		private readonly IUserSession $userSession,
		private readonly IL10N $l,
		private readonly DeadLetterWording $wording,
	) {
	}//end __construct()

	/**
	 * Replay or discard one entry.
	 *
	 * @param string $verb Either `replay` or `discard`.
	 * @param string $id   The entry UUID.
	 *
	 * @return JSONResponse The updated entry, or 404/409.
	 *
	 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-audited-manual-replay-of-a-dead-lettered-sync-item-req-dlr-009
	 */
	public function single(string $verb, string $id): JSONResponse {
		$this->actor();

		try {
			$updated = $this->apply(verb: $verb, id: $id);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => $this->wording->notFound], 404);
		} catch (InvalidMessageStateException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
		}

		return new JSONResponse($updated->getObject());
	}//end single()

	/**
	 * Apply a verb to an explicit, capped set of entry UUIDs.
	 *
	 * Partial failures never abort the batch; each id reports its own outcome
	 * (`ok`, `not-found`, `invalid-state`, or `error`). A filter-predicate form
	 * is deliberately NOT accepted — only an explicit `ids[]` array.
	 *
	 * @param string $verb Either `replay` or `discard`.
	 * @param mixed  $ids  The request's `ids` parameter.
	 *
	 * @return JSONResponse Per-id result map, or 400 on cap/shape violations.
	 *
	 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-bulk-replay-and-discard-for-sync-item-dead-letters-req-dlr-011
	 */
	public function bulk(string $verb, mixed $ids): JSONResponse {
		if (is_array($ids) === false) {
			return new JSONResponse(['error' => $this->wording->idsNotAList], 400);
		}

		if (count($ids) > 100) {
			return new JSONResponse(['error' => $this->l->t('A maximum of 100 ids may be processed per call')], 400);
		}

		$this->actor();
		$results = [];
		foreach ($ids as $id) {
			$id = (string)$id;
			$results[$id] = $this->outcome(verb: $verb, id: $id);
		}

		return new JSONResponse(['results' => $results]);
	}//end bulk()

	/**
	 * Apply a verb to one id of a batch and name its outcome.
	 *
	 * @param string $verb Either `replay` or `discard`.
	 * @param string $id   The entry UUID.
	 *
	 * @return string `ok`, `not-found`, `invalid-state` or `error`.
	 */
	private function outcome(string $verb, string $id): string {
		try {
			$this->apply(verb: $verb, id: $id);
			return 'ok';
		} catch (DoesNotExistException $e) {
			return 'not-found';
		} catch (InvalidMessageStateException $e) {
			return 'invalid-state';
		} catch (Exception $e) {
			return 'error';
		}
	}//end outcome()

	/**
	 * Replay or discard one entry as the acting operator.
	 *
	 * @param string $verb Either `replay` or `discard`.
	 * @param string $id   The entry UUID.
	 *
	 * @return ObjectEntity The updated entry.
	 */
	private function apply(string $verb, string $id): ObjectEntity {
		if ($verb === 'replay') {
			return $this->entries->replayMessage(id: $id, actorUid: $this->actor());
		}

		return $this->entries->discardMessage(id: $id, actorUid: $this->actor());
	}//end apply()

	/**
	 * The acting operator's user id for audit stamping, read once.
	 *
	 * @return string The current user's uid, or 'unknown' when unavailable.
	 */
	private function actor(): string {
		if ($this->actor !== null) {
			return $this->actor;
		}

		$this->actor = 'unknown';
		$user = $this->userSession->getUser();
		if ($user !== null) {
			$this->actor = $user->getUID();
		}

		return $this->actor;
	}//end actor()
}//end class

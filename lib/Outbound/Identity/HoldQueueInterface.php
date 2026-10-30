<?php

/**
 * Contract of the HoldQueue.
 *
 * @category Contract
 * @package  OCA\Integriq\Outbound\Identity
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Outbound\Identity;

use OCA\OpenRegister\Db\ObjectEntity;
use RuntimeException;

/**
 * Public operations of {@see HoldQueue} that other components call.
 */
interface HoldQueueInterface {
	/**
	 * Put a message on hold for its identity's window.
	 *
	 * @param string $uuid The message record uuid.
	 * @param array<string,mixed> $identity The identity it leaves under.
	 *
	 * @return array{status:string,releaseAt:string|null} Whether it waits, and until when.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on HoldQueue::hold()
	 */
	public function hold(string $uuid, array $identity): array;

	/**
	 * Whether a held message may still be withdrawn now.
	 *
	 * @param string $uuid The message record uuid.
	 *
	 * @return bool True while the window is still open.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on HoldQueue::isWithdrawable()
	 */
	public function isWithdrawable(string $uuid): bool;

	/**
	 * Take a message back before it leaves.
	 *
	 * @param string $uuid The message record uuid.
	 * @param string $actorUid Who withdrew it.
	 *
	 * @return ObjectEntity The record, now withdrawn.
	 *
	 * @throws RuntimeException When the window has passed, which says so rather than pretending
	 *                          to recall something that has already left.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on HoldQueue::withdraw()
	 */
	public function withdraw(string $uuid, string $actorUid): ObjectEntity;

	/**
	 * Mark a held message due, once its window has passed.
	 *
	 * @param string $uuid The message record uuid.
	 *
	 * @return bool True when it is now due, false while it still waits or was withdrawn.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on HoldQueue::release()
	 */
	public function release(string $uuid): bool;
}

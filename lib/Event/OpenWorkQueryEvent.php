<?php

/**
 * Integriq Open Work Query Event.
 *
 * Dispatched once per account whose access a directory run is about to end, so
 * every app that holds work for that account can say how much, without integriq
 * ever reading another app's store.
 *
 * A listener that cannot answer stays silent. Silence is recorded as `unknown`,
 * never as zero: an app that never looked and an app that looked and found
 * nothing are different answers, and only one of them is safe to act on.
 *
 * @category Event
 * @package  OCA\Integriq\Event
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
 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

use OCP\EventDispatcher\Event;

/**
 * Asks every registered consumer what one account still holds.
 *
 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-leavers-open-work-is-reported-never-silently-dropped-req-ds-004
 */
class OpenWorkQueryEvent extends Event {

	/**
	 * The answers, keyed by consumer id.
	 *
	 * @var array<string,integer>
	 */
	private array $answers = [];

	/**
	 * Constructor.
	 *
	 * @param string $userId The Nextcloud account id being asked about.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-leavers-open-work-is-reported-never-silently-dropped-req-ds-004
	 */
	public function __construct(
		private readonly string $userId,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * The Nextcloud account id being asked about.
	 *
	 * @return string The account id.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-leavers-open-work-is-reported-never-silently-dropped-req-ds-004
	 */
	public function getUserId(): string {
		return $this->userId;

	}//end getUserId()

	/**
	 * Answer for one consumer.
	 *
	 * Only call this when the count was actually established. A consumer that
	 * could not look MUST NOT answer, so the report can tell the two apart.
	 *
	 * @param string $consumerId The app id answering.
	 * @param integer $count How much open work the account still holds.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-leavers-open-work-is-reported-never-silently-dropped-req-ds-004
	 */
	public function answer(string $consumerId, int $count): void {
		$this->answers[$consumerId] = $count;

	}//end answer()

	/**
	 * Every answer given, keyed by consumer id.
	 *
	 * @return array<string,integer> The answers.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-leavers-open-work-is-reported-never-silently-dropped-req-ds-004
	 */
	public function getAnswers(): array {
		return $this->answers;

	}//end getAnswers()
}//end class

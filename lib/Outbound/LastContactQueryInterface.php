<?php

/**
 * Contract of the LastContactQuery.
 *
 * @category Contract
 * @package  OCA\Integriq\Outbound
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

namespace OCA\Integriq\Outbound;

/**
 * Public operations of {@see LastContactQuery} that other components call.
 */
interface LastContactQueryInterface {
	/**
	 * When this recipient was last told anything about this subject.
	 *
	 * "Told" means a message that reached at least the transport step for this
	 * recipient. A message that failed before it left told nobody anything.
	 *
	 * @param string $subjectRef The subject reference.
	 * @param string $recipient The recipient's address.
	 *
	 * @return array{contacted:bool,at:string|null,message:string|null} The answer. `contacted`
	 *         false means never, which is not the same as "now" and never borrows the case's
	 *         own dates.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on LastContactQuery::lastContact()
	 */
	public function lastContact(string $subjectRef, string $recipient): array;
}

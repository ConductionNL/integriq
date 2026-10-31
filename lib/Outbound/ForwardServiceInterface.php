<?php

/**
 * Contract of the ForwardService.
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

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Public operations of {@see ForwardService} that other components call.
 */
interface ForwardServiceInterface {
	/**
	 * Forward one recorded message.
	 *
	 * @param string $uuid The original record's uuid.
	 * @param array<int,array<string,mixed>> $recipients Who it goes to now.
	 * @param string $actorUid Who forwarded it.
	 * @param string $note An optional covering note, prepended to the body.
	 * @param string|null $channel The channel to forward on, or null to keep the original's.
	 *
	 * @return ObjectEntity The forward record.
	 *
	 * @throws InvalidArgumentException When no recipient is named, which would forward to nobody
	 *                                  while recording that a forward happened.
	 *
	 * @spec openspec/changes/outbound-communication-log/specs/outbound-message-log/spec.md#requirement-a-message-is-forwarded-onward-and-the-forwarding-is-a-record-req-ocl-004
	 */
	public function forward(
		string $uuid,
		array $recipients,
		string $actorUid,
		string $note = '',
		?string $channel = null,
	): ObjectEntity;
}

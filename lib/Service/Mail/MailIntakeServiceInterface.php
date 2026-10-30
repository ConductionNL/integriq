<?php

/**
 * Contract of the MailIntakeService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Mail
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

namespace OCA\Integriq\Service\Mail;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Public operations of {@see MailIntakeService} that other components call.
 */
interface MailIntakeServiceInterface {
	/**
	 * The OpenRegister register holding integriq's objects.
	 *
	 * @var string
	 */
	public const REGISTER = 'integriq';

	/**
	 * The `source.type` a mailbox is.
	 *
	 * @var string
	 */
	public const SOURCE_TYPE = 'mailbox';

	/**
	 * Take one parsed message in.
	 *
	 * A message whose `messageId` is already stored for this source is
	 * returned untouched, so a re-poll and a re-upload are both idempotent.
	 *
	 * @param string $sourceId The mailbox source id.
	 * @param ParsedMessage $message The parsed message.
	 * @param string|null $casePattern The source's `casePattern`, or null for the default.
	 *
	 * @return ObjectEntity The stored `message` object.
	 *
	 * @spec openspec/specs/mail-intake/spec.md
	 */
	public function intake(string $sourceId, ParsedMessage $message, ?string $casePattern = null): ObjectEntity;

	/**
	 * The stored message with this id on this source, when there is one.
	 *
	 * @param string $sourceId The mailbox source id.
	 * @param string $messageId The per-source message id.
	 *
	 * @return ObjectEntity|null The stored message, or null.
	 *
	 * @spec openspec/specs/mail-intake/spec.md
	 */
	public function findByMessageId(string $sourceId, string $messageId): ?ObjectEntity;
}

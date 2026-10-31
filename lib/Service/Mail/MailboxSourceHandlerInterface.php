<?php

/**
 * Contract of the MailboxSourceHandler.
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

use OCA\Integriq\Exception\MailboxTransportException;
use OCA\Integriq\Service\Mail\Transport\MailboxTransportInterface;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Public operations of {@see MailboxSourceHandler} that other components call.
 */
interface MailboxSourceHandlerInterface {
	/**
	 * Poll one mailbox source.
	 *
	 * @param ObjectEntity $source The mailbox source object.
	 *
	 * @return array{created:int,skipped:int,cursor:string|null} What the poll did.
	 *
	 * @throws MailboxTransportException When the source is not a usable mailbox.
	 *
	 * @spec openspec/specs/mail-intake/spec.md
	 */
	public function poll(ObjectEntity $source): array;

	/**
	 * Poll every enabled mailbox source once.
	 *
	 * The scheduled half of REQ-MAIL-001: MailboxPollJob calls this. Sources
	 * are read in system context, because `source` is admin-only and cron has
	 * no user. One mailbox that fails (an unknown protocol, a server that does
	 * not answer) is logged and skipped; the others are still polled.
	 *
	 * @return array{polled:int,failed:int,created:int} What the sweep did.
	 *
	 * @spec openspec/specs/mail-intake/spec.md#requirement-a-mailbox-is-a-source-and-a-message-is-an-object-req-mail-001
	 */
	public function pollAll(): array;

	/**
	 * Pick the binding a mailbox configuration asks for.
	 *
	 * Mock mode wins over the protocol, because a source in mock mode must
	 * never reach a real mail server.
	 *
	 * @param array<string,mixed> $configuration The mailbox source's configuration.
	 *
	 * @return MailboxTransportInterface The binding.
	 *
	 * @throws MailboxTransportException When the protocol is unknown or unusable here.
	 *
	 * @spec openspec/specs/mail-intake/spec.md
	 */
	public function resolveTransport(array $configuration): MailboxTransportInterface;
}

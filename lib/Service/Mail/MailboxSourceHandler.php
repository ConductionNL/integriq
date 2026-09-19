<?php

/**
 * Integriq MailboxSourceHandler.
 *
 * Polls one mailbox source: picks the protocol binding, reads what arrived
 * since the stored cursor, feeds every message through intake, and moves the
 * cursor forward. A message already stored for this source is counted as
 * skipped, so polling twice leaves one object per message.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Mail
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/mail-intake-creates-cases/specs/mail-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Mail;

use OCA\Integriq\Exception\MailboxTransportException;
use OCA\Integriq\Service\Mail\Transport\GraphMailboxTransport;
use OCA\Integriq\Service\Mail\Transport\ImapMailboxTransport;
use OCA\Integriq\Service\Mail\Transport\MailboxTransportInterface;
use OCA\Integriq\Service\Mail\Transport\MockMailboxTransport;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use Psr\Log\LoggerInterface;

/**
 * Runs one mailbox source's synchronization.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/mail-intake-creates-cases/specs/mail-intake/spec.md#requirement-a-mailbox-is-a-source-and-a-message-is-an-object-req-mail-001
 */
class MailboxSourceHandler {

	/**
	 * Constructor.
	 *
	 * @param ORObjectService $objectService Reads and writes the source object.
	 * @param MailIntakeService $intakeService Takes each message in.
	 * @param MockMailboxTransport $mockTransport The fixture binding.
	 * @param ImapMailboxTransport $imapTransport The IMAP binding.
	 * @param GraphMailboxTransport $graphTransport The Microsoft Graph binding.
	 * @param LoggerInterface $logger Records refusals, never credentials.
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
		private readonly MailIntakeService $intakeService,
		private readonly MockMailboxTransport $mockTransport,
		private readonly ImapMailboxTransport $imapTransport,
		private readonly GraphMailboxTransport $graphTransport,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Poll one mailbox source.
	 *
	 * @param ObjectEntity $source The mailbox source object.
	 *
	 * @return array{created:int,skipped:int,cursor:string|null} What the poll did.
	 *
	 * @throws MailboxTransportException When the source is not a usable mailbox.
	 */
	public function poll(ObjectEntity $source): array {
		$object = $source->getObject();
		$sourceId = (string)$source->getUuid();
		$configuration = ($object['configuration'] ?? []);
		if (is_array($configuration) === false) {
			$configuration = [];
		}

		$transport = $this->resolveTransport(configuration: $configuration);
		$cursor = ($configuration['sinceCursor'] ?? null);

		// The stored cursor is read three times below and is nullable, so it is
		// narrowed once here rather than at each use.
		$cursorText = null;
		if ($cursor !== null) {
			$cursorText = (string)$cursor;
		}

		$messages = $transport->fetch($configuration, $cursorText);

		$created = 0;
		$skipped = 0;
		$latest = $cursorText;

		$pattern = ($configuration['casePattern'] ?? null);
		$patternText = null;
		if ($pattern !== null) {
			$patternText = (string)$pattern;
		}

		foreach ($messages as $message) {
			if ($this->intakeService->findByMessageId($sourceId, $message->getMessageId()) !== null) {
				$skipped++;
				continue;
			}

			$this->intakeService->intake(
				$sourceId,
				$message,
				$patternText
			);
			$created++;
			$latest = $this->later(current: $latest, candidate: $message->getReceivedAt());
		}

		if ($latest !== $cursorText) {
			$this->storeCursor(source: $source, object: $object, configuration: $configuration, cursor: $latest);
		}

		return [
			'created' => $created,
			'skipped' => $skipped,
			'cursor' => $latest,
		];

	}//end poll()

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
	 */
	public function resolveTransport(array $configuration): MailboxTransportInterface {
		if (($configuration['mock'] ?? false) === true) {
			return $this->mockTransport;
		}

		$protocol = strtolower(trim((string)($configuration['protocol'] ?? '')));
		$transport = match ($protocol) {
			'imap' => $this->imapTransport,
			'graph' => $this->graphTransport,
			default => null,
		};

		if ($transport === null) {
			throw new MailboxTransportException(
				message: 'Unknown mailbox protocol "' . $protocol . '": integriq speaks imap and graph.'
			);
		}

		if ($transport->isUsable() === false) {
			throw new MailboxTransportException(
				message: 'The ' . $protocol . ' binding cannot run on this host, so the mailbox was not polled.'
			);
		}

		return $transport;

	}//end resolveTransport()

	/**
	 * The later of two timestamps.
	 *
	 * @param string|null $current The timestamp held so far.
	 * @param string|null $candidate The candidate timestamp.
	 *
	 * @return string|null The later timestamp.
	 */
	private function later(?string $current, ?string $candidate): ?string {
		if ($candidate === null || trim($candidate) === '') {
			return $current;
		}

		if ($current === null || trim($current) === '') {
			return $candidate;
		}

		if (strtotime($candidate) > strtotime($current)) {
			return $candidate;
		}

		return $current;

	}//end later()

	/**
	 * Write the advanced cursor back onto the source.
	 *
	 * @param ObjectEntity $source The source object.
	 * @param array<string,mixed> $object The source payload.
	 * @param array<string,mixed> $configuration The source configuration.
	 * @param string|null $cursor The new cursor.
	 *
	 * @return void
	 */
	private function storeCursor(ObjectEntity $source, array $object, array $configuration, ?string $cursor): void {
		$configuration['sinceCursor'] = (string)$cursor;
		$object['configuration'] = $configuration;

		$this->objectService->saveObject(
			object: $object,
			register: MailIntakeService::REGISTER,
			schema: 'source',
			uuid: (string)$source->getUuid(),
		);

		$this->logger->debug(
			'Integriq mail intake: mailbox cursor advanced.',
			['source' => (string)$source->getUuid()]
		);

	}//end storeCursor()

}//end class

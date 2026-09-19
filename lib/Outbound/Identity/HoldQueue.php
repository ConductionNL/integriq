<?php

/**
 * Integriq HoldQueue.
 *
 * Undo is a hold, not a recall. A message from an identity with a hold window
 * waits in the queue for that window before the send is attempted, and can be
 * withdrawn while it waits. Once it has left, nothing is recalled, because
 * nothing can be: a product that offers to unsend a besluit is lying about
 * what it can do.
 *
 * A zero window, which is the default, means the message is due immediately.
 *
 * @category Outbound
 * @package  OCA\Integriq\Outbound\Identity
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
 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Outbound\Identity;

use DateTimeImmutable;
use OCA\Integriq\Outbound\MessageRecorder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use RuntimeException;

/**
 * Holds a message for its identity's window, and lets it be withdrawn.
 *
 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md
 */
class HoldQueue {

	/**
	 * The message is waiting out its hold window.
	 *
	 * @var string
	 */
	public const STATUS_HELD = 'held';

	/**
	 * The window passed and the send may be attempted.
	 *
	 * @var string
	 */
	public const STATUS_DUE = 'due';

	/**
	 * The sender took it back in time.
	 *
	 * @var string
	 */
	public const STATUS_WITHDRAWN = 'withdrawn';

	/**
	 * Constructor.
	 *
	 * @param ORObjectService $objectService Writes the hold state onto the message record.
	 * @param MessageRecorder $recorder Reads the record.
	 * @param ITimeFactory $time The clock, injected so a test can stand on both sides of a window.
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
		private readonly MessageRecorder $recorder,
		private readonly ITimeFactory $time,
	) {

	}//end __construct()

	/**
	 * Put a message on hold for its identity's window.
	 *
	 * @param string $uuid The message record uuid.
	 * @param array<string,mixed> $identity The identity it leaves under.
	 *
	 * @return array{status:string,releaseAt:string|null} Whether it waits, and until when.
	 */
	public function hold(string $uuid, array $identity): array {
		$window = (int)($identity['holdWindowSeconds'] ?? 0);
		$record = $this->recorder->read($uuid);

		if ($window <= 0) {
			$record['hold'] = ['status' => self::STATUS_DUE, 'windowSeconds' => 0, 'releaseAt' => null];
			$this->write(uuid: $uuid, record: $record);
			return ['status' => self::STATUS_DUE, 'releaseAt' => null];
		}

		$releaseAt = $this->now()->modify('+' . $window . ' seconds')->format('c');
		$record['hold'] = [
			'status' => self::STATUS_HELD,
			'windowSeconds' => $window,
			'releaseAt' => $releaseAt,
		];
		$this->write(uuid: $uuid, record: $record);

		return ['status' => self::STATUS_HELD, 'releaseAt' => $releaseAt];

	}//end hold()

	/**
	 * Whether a held message may still be withdrawn now.
	 *
	 * @param string $uuid The message record uuid.
	 *
	 * @return bool True while the window is still open.
	 */
	public function isWithdrawable(string $uuid): bool {
		$hold = ($this->recorder->read($uuid)['hold'] ?? []);
		if (is_array($hold) === false || (string)($hold['status'] ?? '') !== self::STATUS_HELD) {
			return false;
		}

		$releaseAt = (string)($hold['releaseAt'] ?? '');
		if ($releaseAt === '') {
			return false;
		}

		return ($this->now()->getTimestamp() < strtotime($releaseAt));

	}//end isWithdrawable()

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
	 */
	public function withdraw(string $uuid, string $actorUid): ObjectEntity {
		if ($this->isWithdrawable(uuid: $uuid) === false) {
			throw new RuntimeException(
				'This message has left; nothing can be recalled after the hold window.'
			);
		}

		$record = $this->recorder->read($uuid);
		$record['hold']['status'] = self::STATUS_WITHDRAWN;
		$record['hold']['withdrawnBy'] = $actorUid;
		$record['hold']['withdrawnAt'] = $this->now()->format('c');
		$record['status'] = MessageRecorder::STATUS_FAILED;
		$record['steps'][] = [
			'step' => 'withdrawn',
			'outcome' => MessageRecorder::OUTCOME_FAILED,
			'at' => $this->now()->format('c'),
			'detail' => 'Withdrawn by ' . $actorUid . ' before the hold window closed.',
		];

		foreach ($record['recipients'] as $index => $recipient) {
			$record['recipients'][$index]['status'] = 'failed';
			$record['recipients'][$index]['failedStep'] = 'withdrawn';
			$record['recipients'][$index]['reason'] = 'Withdrawn before it was sent.';
		}

		return $this->write(uuid: $uuid, record: $record);

	}//end withdraw()

	/**
	 * Mark a held message due, once its window has passed.
	 *
	 * @param string $uuid The message record uuid.
	 *
	 * @return bool True when it is now due, false while it still waits or was withdrawn.
	 */
	public function release(string $uuid): bool {
		$record = $this->recorder->read($uuid);
		$hold = ($record['hold'] ?? []);
		if (is_array($hold) === false || (string)($hold['status'] ?? '') !== self::STATUS_HELD) {
			return false;
		}

		$releaseAt = (string)($hold['releaseAt'] ?? '');
		if ($releaseAt !== '' && $this->now()->getTimestamp() < strtotime($releaseAt)) {
			return false;
		}

		$record['hold']['status'] = self::STATUS_DUE;
		$this->write(uuid: $uuid, record: $record);

		return true;

	}//end release()

	/**
	 * Write a record back.
	 *
	 * @param string $uuid The record uuid.
	 * @param array<string,mixed> $record The payload.
	 *
	 * @return ObjectEntity The saved record.
	 */
	private function write(string $uuid, array $record): ObjectEntity {
		return $this->objectService->saveObject(
			object: $record,
			register: MessageRecorder::REGISTER,
			schema: MessageRecorder::SCHEMA,
			uuid: $uuid,
		);

	}//end write()

	/**
	 * Now, according to the injected clock.
	 *
	 * @return DateTimeImmutable The current time.
	 */
	private function now(): DateTimeImmutable {
		return DateTimeImmutable::createFromInterface($this->time->getDateTime());

	}//end now()

}//end class

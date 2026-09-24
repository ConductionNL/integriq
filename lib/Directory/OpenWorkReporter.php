<?php

/**
 * Integriq Open Work Reporter.
 *
 * Says what a leaver still holds, by asking the apps that hold it. Integriq
 * reassigns nothing: reassignment is a decision with a policy behind it, and
 * the policy belongs to the app that owns the work.
 *
 * Two ways to answer are supported on purpose. An app inside this process
 * registers an {@see IOpenWorkConsumer}; an app that would rather not share a
 * class listens for {@see OpenWorkQueryEvent}. Either way the report names the
 * consumer that answered, and a consumer that is expected but silent reads
 * `unknown`.
 *
 * @category Directory
 * @package  OCA\Integriq\Directory
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

namespace OCA\Integriq\Directory;

use OCA\Integriq\Event\OpenWorkQueryEvent;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reports what an account still holds, per consumer.
 *
 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-leavers-open-work-is-reported-never-silently-dropped-req-ds-004
 */
class OpenWorkReporter {

	/**
	 * The answer recorded for a consumer that did not answer.
	 *
	 * @var string
	 */
	public const UNKNOWN = 'unknown';

	/**
	 * In-process consumers, keyed by consumer id.
	 *
	 * @var array<string,IOpenWorkConsumer>
	 */
	private array $consumers = [];

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $eventDispatcher Dispatcher for the out-of-process query.
	 * @param LoggerInterface $logger Logger for a consumer that throws.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-leavers-open-work-is-reported-never-silently-dropped-req-ds-004
	 */
	public function __construct(
		private readonly IEventDispatcher $eventDispatcher,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Register an in-process consumer.
	 *
	 * @param IOpenWorkConsumer $consumer The consumer.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-leavers-open-work-is-reported-never-silently-dropped-req-ds-004
	 */
	public function registerConsumer(IOpenWorkConsumer $consumer): void {
		$this->consumers[$consumer->getConsumerId()] = $consumer;

	}//end registerConsumer()

	/**
	 * What one account still holds, per consumer.
	 *
	 * @param string $userId The Nextcloud account id.
	 * @param array<int,string> $expectedConsumers Consumer ids the connection expects an answer from.
	 *
	 * @return array<string,integer|string> Counts per consumer id, or `unknown`.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-leavers-open-work-is-reported-never-silently-dropped-req-ds-004
	 */
	public function report(string $userId, array $expectedConsumers = []): array {
		$report = [];

		foreach ($this->consumers as $consumerId => $consumer) {
			$count = $this->ask(consumer: $consumer, userId: $userId);
			if ($count !== null) {
				$report[$consumerId] = $count;
			}
		}

		$event = new OpenWorkQueryEvent(userId: $userId);
		$this->eventDispatcher->dispatchTyped($event);

		foreach ($event->getAnswers() as $consumerId => $count) {
			$report[$consumerId] = $count;
		}

		// An expected consumer that stayed silent is UNKNOWN, never zero. The
		// two are different answers and only one of them is safe to act on.
		foreach ($expectedConsumers as $consumerId) {
			if (array_key_exists($consumerId, $report) === false) {
				$report[$consumerId] = self::UNKNOWN;
			}
		}

		return $report;

	}//end report()

	/**
	 * Ask one in-process consumer, treating a throw as no answer.
	 *
	 * @param IOpenWorkConsumer $consumer The consumer.
	 * @param string $userId The Nextcloud account id.
	 *
	 * @return integer|null The count, or null when the consumer could not answer.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-leavers-open-work-is-reported-never-silently-dropped-req-ds-004
	 */
	private function ask(IOpenWorkConsumer $consumer, string $userId): ?int {
		try {
			return $consumer->countOpenWork($userId);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[OpenWorkReporter] consumer ' . $consumer->getConsumerId() . ' could not answer: ' . $exception->getMessage(),
				['exception' => $exception]
			);

			// A consumer that threw did not answer, so it reads unknown rather
			// than zero once the expected list is applied.
			return null;
		}

	}//end ask()
}//end class

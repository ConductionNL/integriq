<?php

/**
 * Integriq Exchange Acknowledgement Listener.
 *
 * Reports an authority's acknowledgement against the exchange job and record
 * it answers.
 *
 * @category EventListener
 * @package  OCA\Integriq\EventListener
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
 * @spec openspec/changes/connectors-data-exchange-dispatch/specs/exchange-jobs/spec.md#requirement-req-013-the-authority-acknowledgement-for-a-record-is-reported-against-its-job
 */

declare(strict_types=1);

namespace OCA\Integriq\EventListener;

use OCA\Integriq\Event\ExchangeJobAcknowledgedEvent;
use OCA\Integriq\Event\OsoAcknowledgementReceivedEvent;
use OCA\Integriq\Event\RodAcknowledgementReceivedEvent;
use OCA\Integriq\Event\UwlrEduVAcknowledgementReceivedEvent;
use OCA\Integriq\Event\VerzuimloketAcknowledgementReceivedEvent;
use OCA\Integriq\Service\Exchange\ExchangeJobService;
use OCA\Integriq\Service\Exchange\ExchangeRejectionService;
use OCA\Integriq\Service\Exchange\ExchangeTargetCatalogue;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The ROD, Verzuimloket, OSO and UWLR retours, matched to exchange jobs.
 *
 * The exchange dispatcher sends each record with the kenmerk
 * `<jobId>:<recordId>`, so the job row is the record of what was sent: no
 * second ledger. A retour whose kenmerk names no job, a job another adapter
 * carries, or a job no app owns is left alone; the adapter's own event has
 * already fired for anyone else listening. Never throws into the adapter that
 * received the retour.
 *
 * @spec openspec/changes/connectors-data-exchange-dispatch/specs/exchange-jobs/spec.md#requirement-req-013-the-authority-acknowledgement-for-a-record-is-reported-against-its-job
 *
 * @template-implements IEventListener<Event>
 */
class ExchangeAcknowledgementListener implements IEventListener {

	/**
	 * The adapter each acknowledgement event belongs to, as
	 * ExchangeTargetCatalogue names it.
	 *
	 * @var array<class-string, string>
	 */
	public const ADAPTER_OF = [
		RodAcknowledgementReceivedEvent::class => 'rod',
		VerzuimloketAcknowledgementReceivedEvent::class => 'verzuimloket',
		OsoAcknowledgementReceivedEvent::class => 'oso',
		UwlrEduVAcknowledgementReceivedEvent::class => 'uwlr-eduv',
	];

	/**
	 * Constructor.
	 *
	 * @param ExchangeJobService       $jobs       Finds the job a kenmerk names.
	 * @param ExchangeRejectionService $rejections Stores a rejecting retour.
	 * @param ExchangeTargetCatalogue  $targets    Says which adapter carries a target.
	 * @param IEventDispatcher         $dispatcher Tells the owning app.
	 * @param LoggerInterface          $logger     Logger.
	 */
	public function __construct(
		private readonly ExchangeJobService $jobs,
		private readonly ExchangeRejectionService $rejections,
		private readonly ExchangeTargetCatalogue $targets,
		private readonly IEventDispatcher $dispatcher,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle one acknowledgement.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connectors-data-exchange-dispatch/specs/exchange-jobs/spec.md#requirement-req-013-the-authority-acknowledgement-for-a-record-is-reported-against-its-job
	 */
	public function handle(Event $event): void {
		if (($event instanceof RodAcknowledgementReceivedEvent
			|| $event instanceof VerzuimloketAcknowledgementReceivedEvent
			|| $event instanceof OsoAcknowledgementReceivedEvent
			|| $event instanceof UwlrEduVAcknowledgementReceivedEvent) === false
		) {
			return;
		}

		$adapter = self::ADAPTER_OF[$event::class];
		$parts = explode(':', $event->getKenmerk(), 2);
		$jobId = $parts[0];
		$recordId = ($parts[1] ?? '');
		if ($recordId === '') {
			return;
		}

		try {
			$this->report(
				adapter: $adapter,
				jobId: $jobId,
				recordId: $recordId,
				accepted: $event->isAccepted(),
				signaalcode: $event->getSignaalcode(),
				description: (string)$event->getSignaalOmschrijving()
			);
		} catch (Throwable $exception) {
			$this->logger->error(
				'[integriq] an acknowledgement for exchange job {job} could not be recorded: {error}',
				['job' => $jobId, 'error' => $exception->getMessage()]
			);
		}

	}//end handle()

	/**
	 * Report one acknowledgement against its job, when it answers one.
	 *
	 * @param string $adapter     The adapter that received it.
	 * @param string $jobId       The job the kenmerk names.
	 * @param string $recordId    The record the kenmerk names.
	 * @param bool   $accepted    Whether the authority accepted the record.
	 * @param string $signaalcode The authority's code.
	 * @param string $description The authority's description, empty when none.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connectors-data-exchange-dispatch/specs/exchange-jobs/spec.md#requirement-req-013-the-authority-acknowledgement-for-a-record-is-reported-against-its-job
	 */
	private function report(
		string $adapter,
		string $jobId,
		string $recordId,
		bool $accepted,
		string $signaalcode,
		string $description
	): void {
		$job = $this->jobs->findJob(jobId: $jobId);
		if ($job === null) {
			return;
		}

		$data = $job->getObject();
		$target = (string)($data['exchangeTarget'] ?? '');
		$ownerApp = (string)($data['ownerApp'] ?? '');
		if ($ownerApp === '' || $this->adapterFor(target: $target) !== $adapter) {
			return;
		}

		if ($accepted === false) {
			$this->rejections->record(
				jobId: $jobId,
				target: $target,
				rejection: ['recordId' => $recordId, 'errorCode' => $signaalcode],
				ownerApp: $ownerApp
			);
		}

		$this->dispatcher->dispatchTyped(
			new ExchangeJobAcknowledgedEvent(
				ownerApp: $ownerApp,
				jobId: $jobId,
				recordId: $recordId,
				target: $target,
				accepted: $accepted,
				signaalcode: $signaalcode,
				description: $description,
				receivedAt: date(DATE_ATOM)
			)
		);

	}//end report()

	/**
	 * The adapter that carries a target, empty for an unknown one.
	 *
	 * @param string $target The target id.
	 *
	 * @return string The adapter id.
	 */
	private function adapterFor(string $target): string {
		foreach ($this->targets->all() as $entry) {
			if ($entry['id'] === $target) {
				return (string)$entry['adapter'];
			}
		}

		return '';

	}//end adapterFor()
}//end class

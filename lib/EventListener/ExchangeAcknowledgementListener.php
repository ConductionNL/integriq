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
use OCA\Integriq\Service\Exchange\ExchangeJobServiceInterface;
use OCA\Integriq\Service\Exchange\ExchangeRejectionServiceInterface;
use OCA\Integriq\Service\Exchange\ExchangeTargetCatalogueInterface;
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
	 * @param ExchangeJobServiceInterface       $jobs       Finds the job a kenmerk names.
	 * @param ExchangeRejectionServiceInterface $rejections Stores a rejecting retour.
	 * @param ExchangeTargetCatalogueInterface  $targets    Says which adapter carries a target.
	 * @param IEventDispatcher         $dispatcher Tells the owning app.
	 * @param LoggerInterface          $logger     Logger.
	 */
	public function __construct(
		private readonly ExchangeJobServiceInterface $jobs,
		private readonly ExchangeRejectionServiceInterface $rejections,
		private readonly ExchangeTargetCatalogueInterface $targets,
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

		$record = AcknowledgedRecord::fromKenmerk(kenmerk: $event->getKenmerk());
		if ($record->recordId === '') {
			return;
		}

		$record->accepted = $event->isAccepted();
		$record->signaalcode = $event->getSignaalcode();
		$record->description = (string)$event->getSignaalOmschrijving();

		try {
			$this->report(record: $record, adapter: self::ADAPTER_OF[$event::class]);
		} catch (Throwable $exception) {
			$this->logger->error(
				'[integriq] an acknowledgement for exchange job {job} could not be recorded: {error}',
				['job' => $record->jobId, 'error' => $exception->getMessage()]
			);
		}

	}//end handle()

	/**
	 * Report one acknowledgement against its job, when it answers one.
	 *
	 * @param AcknowledgedRecord $record  The job and record the kenmerk names, and what the authority answered.
	 * @param string             $adapter The adapter that received it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connectors-data-exchange-dispatch/specs/exchange-jobs/spec.md#requirement-req-013-the-authority-acknowledgement-for-a-record-is-reported-against-its-job
	 */
	private function report(AcknowledgedRecord $record, string $adapter): void {
		if ($this->answersOwnedJob(record: $record, adapter: $adapter) === false) {
			return;
		}

		if ($record->accepted === false) {
			$this->rejections->record(
				jobId: $record->jobId,
				target: $record->target,
				rejection: ['recordId' => $record->recordId, 'errorCode' => $record->signaalcode],
				ownerApp: $record->ownerApp
			);
		}

		$this->dispatcher->dispatchTyped(
			new ExchangeJobAcknowledgedEvent(
				ownerApp: $record->ownerApp,
				jobId: $record->jobId,
				recordId: $record->recordId,
				target: $record->target,
				accepted: $record->accepted,
				signaalcode: $record->signaalcode,
				description: $record->description,
				receivedAt: date(DATE_ATOM)
			)
		);

	}//end report()

	/**
	 * Whether the kenmerk names a job an app owns whose target the receiving
	 * adapter carries; fills in the record's target and owning app.
	 *
	 * @param AcknowledgedRecord $record  The job and record the kenmerk names.
	 * @param string             $adapter The adapter that received the acknowledgement.
	 *
	 * @return bool True when the acknowledgement answers that job.
	 *
	 * @spec openspec/changes/connectors-data-exchange-dispatch/specs/exchange-jobs/spec.md#requirement-req-013-the-authority-acknowledgement-for-a-record-is-reported-against-its-job
	 */
	private function answersOwnedJob(AcknowledgedRecord $record, string $adapter): bool {
		$job = $this->jobs->findJob(jobId: $record->jobId);
		if ($job === null) {
			return false;
		}

		$data = $job->getObject();
		$record->target = (string)($data['exchangeTarget'] ?? '');
		$record->ownerApp = (string)($data['ownerApp'] ?? '');

		return $record->ownerApp !== '' && $this->adapterFor(target: $record->target) === $adapter;
	}//end answersOwnedJob()

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

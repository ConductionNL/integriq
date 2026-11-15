<?php

/**
 * Integriq exchange target dispatcher.
 *
 * Hands the mapped records of an exchange job to the adapter behind its
 * target, one record at a time.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Exchange
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
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Exchange;

use OCA\Integriq\Event\ExchangeRecordsReceivedEvent;
use OCA\Integriq\Exception\OsoTranslationException;
use OCA\Integriq\Exception\RodTranslationException;
use OCA\Integriq\Exception\UwlrEduVTranslationException;
use OCA\Integriq\Exception\VerzuimloketTranslationException;
use OCA\Integriq\Service\OsoService;
use OCA\Integriq\Service\RodService;
use OCA\Integriq\Service\UwlrEduVService;
use OCA\Integriq\Service\VerzuimloketService;
use OCA\Integriq\Sources\Swv\DossierHandoffAdapterInterface;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Target to adapter routing for exchange jobs (design D5).
 *
 * One private method per handled target; the adapter services are injected
 * so every call is visible to static analysis. A record the adapter refuses
 * becomes a rejection and the others continue. The kenmerk is
 * `<jobId>:<recordId>`, so a later acknowledgement names both the job and the
 * record. This is the routing `connectors-data-exchange-dispatch` D3 sketches,
 * driven by an integriq-native job instead of a learniq one (decision D7).
 *
 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-005-export-handlers-hand-records-to-the-existing-adapters
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ExchangeTargetDispatcher {

	public const CODE_TRANSLATION = 'translation-failed';

	public const CODE_SEND = 'send-failed';

	public const CODE_SOURCE_MISSING = 'source-missing';

	/**
	 * An import handed its records over and the owning app did not answer.
	 *
	 * @var string
	 */
	public const CODE_NO_OWNER_ANSWER = 'no-owner-answer';

	/**
	 * Import jobs whose received records land in the owning app through
	 * {@see ExchangeRecordsReceivedEvent}, target to directions.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const LANDED = [
		'lvs-results' => ['import'],
		'oso' => ['import'],
		'migration-import' => ['import'],
	];

	/**
	 * Handled target and direction pairs.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const HANDLED = [
		'bron-rod' => ['export'],
		'leerplicht' => ['export'],
		'oso' => ['export'],
		'swv' => ['export'],
		'uwlr' => ['export'],
		'edu-v' => ['export'],
		'basispoort' => ['sync'],
		'entree-content' => ['sync'],
	];

	/**
	 * SWV receiver acknowledgements that count as accepted.
	 *
	 * @var array<int, string>
	 */
	private const SWV_ACCEPTED = ['received', 'accepted'];

	/**
	 * Constructor.
	 *
	 * @param RodService              $rodService          DUO ROD.
	 * @param VerzuimloketService     $verzuimloketService DUO Verzuimloket.
	 * @param OsoService              $osoService          OSO.
	 * @param UwlrEduVService         $uwlrEduVService     UWLR, Edu-V, Basispoort, Entree.
	 * @param DossierHandoffAdapterInterface $swvAdapter          SWV hand-off.
	 * @param IEventDispatcher        $events              Hands import records to the owning app.
	 * @param LoggerInterface         $logger              Logger.
	 */
	public function __construct(
		private readonly RodService $rodService,
		private readonly VerzuimloketService $verzuimloketService,
		private readonly OsoService $osoService,
		private readonly UwlrEduVService $uwlrEduVService,
		private readonly DossierHandoffAdapterInterface $swvAdapter,
		private readonly IEventDispatcher $events,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Whether a target and direction have a handler.
	 *
	 * @param string $target    The target id.
	 * @param string $direction The direction.
	 *
	 * @return bool True when handled.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-005-export-handlers-hand-records-to-the-existing-adapters
	 */
	public function supports(string $target, string $direction): bool {
		return in_array($direction, $this->handledDirections(target: $target), true);

	}//end supports()

	/**
	 * Which directions a target has handlers for.
	 *
	 * @param string $target The target id.
	 *
	 * @return array<int, string> The handled directions.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-008-apps-read-their-own-jobs-through-the-read-model
	 */
	public function handledDirections(string $target): array {
		return array_values(array_unique(array_merge((self::HANDLED[$target] ?? []), (self::LANDED[$target] ?? []))));

	}//end handledDirections()

	/**
	 * Hand records to the target's adapter.
	 *
	 * @param ExchangeHandOff $handOff The job's records, target, direction and scope.
	 *
	 * @return array{accepted: array<int, string>, rejected: array<int, array<string, mixed>>, refusal: string|null, acceptedCount?: int}
	 *     Accepted record ids, rejections, and a job-wide refusal code when the job cannot run at all.
	 *     A landed import reports `acceptedCount` instead of ids: the owning app answers with a count.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-005-export-handlers-hand-records-to-the-existing-adapters
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-010-an-import-job-hands-its-records-to-the-owning-app
	 */
	public function dispatch(ExchangeHandOff $handOff): array {
		if ($this->supports(target: $handOff->target, direction: $handOff->direction) === false) {
			return $this->refusal(code: 'no-handler');
		}

		if (in_array($handOff->direction, (self::LANDED[$handOff->target] ?? []), true) === true) {
			return $this->land(handOff: $handOff);
		}

		if ($handOff->target === 'swv' && (string)($handOff->scope['receiverId'] ?? '') === '') {
			return $this->refusal(code: self::CODE_SOURCE_MISSING);
		}

		return $this->sendRecords(handOff: $handOff);

	}//end dispatch()

	/**
	 * A job-wide refusal: nothing accepted, nothing rejected.
	 *
	 * @param string $code The refusal code.
	 *
	 * @return array{accepted: array<int, string>, rejected: array<int, array<string, mixed>>, refusal: string}
	 */
	private function refusal(string $code): array {
		return ['accepted' => [], 'rejected' => [], 'refusal' => $code];

	}//end refusal()

	/**
	 * Send every record to the target's adapter, one at a time; a refused record does not stop the others.
	 *
	 * @param ExchangeHandOff $handOff The job.
	 *
	 * @return array{accepted: array<int, string>, rejected: array<int, array<string, mixed>>, refusal: null}
	 */
	private function sendRecords(ExchangeHandOff $handOff): array {
		$outcome = ['accepted' => [], 'rejected' => [], 'refusal' => null];
		foreach ($handOff->records as $record) {
			$rejection = $this->sendRecord(handOff: $handOff, record: $record);
			if ($rejection === null) {
				$outcome['accepted'][] = (string)($record['recordId'] ?? '');
				continue;
			}

			$outcome['rejected'][] = $rejection;
		}

		return $outcome;

	}//end sendRecords()

	/**
	 * Send one record; its rejection when the adapter threw or did not take it.
	 *
	 * @param ExchangeHandOff     $handOff The job.
	 * @param array<string,mixed> $record  The record, `{recordId, sourceKind, data}`.
	 *
	 * @return array<string,mixed>|null The rejection, or null when the adapter took the record.
	 */
	private function sendRecord(ExchangeHandOff $handOff, array $record): ?array {
		$outbound = new OutboundRecord(
			kenmerk: $handOff->jobId . ':' . (string)($record['recordId'] ?? ''),
			data: $this->recordData(record: $record)
		);

		try {
			$accepted = $this->sendOne(handOff: $handOff, record: $outbound);
		} catch (Throwable $exception) {
			return $this->rejection(record: $record, exception: $exception);
		}

		if ($accepted === true) {
			return null;
		}

		return $this->sendFailure(record: $record);

	}//end sendRecord()

	/**
	 * The record's mapped data; anything but an array counts as empty.
	 *
	 * @param array<string,mixed> $record The record.
	 *
	 * @return array<string,mixed> The data.
	 */
	private function recordData(array $record): array {
		$data = $record['data'] ?? [];
		if (is_array($data) === false) {
			return [];
		}

		return $data;

	}//end recordData()

	/**
	 * The rejection of a record the adapter did not take.
	 *
	 * @param array<string,mixed> $record The record.
	 *
	 * @return array<string,mixed> The rejection.
	 */
	private function sendFailure(array $record): array {
		return [
			'recordId' => (string)($record['recordId'] ?? ''),
			'sourceKind' => (string)($record['sourceKind'] ?? ''),
			'errorCode' => self::CODE_SEND,
			'offendingFields' => [],
		];

	}//end sendFailure()

	/**
	 * Hand an import job's received records to the owning app and read its answer.
	 *
	 * The owning app answers through {@see ExchangeRecordsReceivedEvent::accept()}.
	 * No answer, or a listener that throws, is `no-owner-answer`.
	 *
	 * @param ExchangeHandOff $handOff The job, with its owning app and reference.
	 *
	 * @return array{accepted: array<int, string>, rejected: array<int, array<string, mixed>>, refusal: string|null, acceptedCount?: int}
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-011-the-owning-apps-answer-ends-the-job
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-012-an-unanswered-import-ends-with-no-owner-answer
	 */
	private function land(ExchangeHandOff $handOff): array {
		$event = $this->receivedEvent(handOff: $handOff);

		try {
			$this->events->dispatchTyped($event);
		} catch (Throwable $exception) {
			// The exception class only: a listener's message can quote a record.
			$this->logger->warning(
				'[ExchangeTargetDispatcher] the owning app failed on the records of job '.$handOff->jobId.': '.get_class($exception)
			);
			return $this->refusal(code: self::CODE_NO_OWNER_ANSWER);
		}

		if ($event->isAnswered() === false) {
			return $this->refusal(code: self::CODE_NO_OWNER_ANSWER);
		}

		return [
			'accepted' => [],
			'rejected' => $event->getRejected(),
			'refusal' => null,
			'acceptedCount' => $event->getAcceptedCount(),
		];

	}//end land()

	/**
	 * The event that carries an import job's records to the owning app.
	 *
	 * @param ExchangeHandOff $handOff The job.
	 *
	 * @return ExchangeRecordsReceivedEvent The event.
	 */
	private function receivedEvent(ExchangeHandOff $handOff): ExchangeRecordsReceivedEvent {
		return new ExchangeRecordsReceivedEvent(
			jobId: $handOff->jobId,
			ownerApp: $handOff->ownerApp,
			target: $handOff->target,
			direction: $handOff->direction,
			ownerRef: $handOff->ownerRef,
			scope: $handOff->scope,
			records: array_values($handOff->records)
		);

	}//end receivedEvent()

	/**
	 * Send one record to the target's adapter.
	 *
	 * @param ExchangeHandOff $handOff The job: its target and scope.
	 * @param OutboundRecord  $record  The record and its correlation id.
	 *
	 * @return bool True when the adapter took the record.
	 */
	private function sendOne(ExchangeHandOff $handOff, OutboundRecord $record): bool {
		switch ($handOff->target) {
			case 'bron-rod':
				return $this->sendToRod(handOff: $handOff, record: $record);
			case 'leerplicht':
				return $this->sendToLeerplicht(handOff: $handOff, record: $record);
			case 'oso':
				$this->osoService->sendExport(kenmerk: $record->kenmerk, payload: $record->data);
				return true;
			default:
				return $this->sendToEducationTargetOrSwv(handOff: $handOff, record: $record);
		}//end switch

	}//end sendOne()

	/**
	 * Send one record to the UWLR/Edu-V adapter, or hand it to the SWV receiver.
	 *
	 * @param ExchangeHandOff $handOff The job: its target and scope.
	 * @param OutboundRecord  $record  The record and its correlation id.
	 *
	 * @return bool True when the adapter took the record.
	 */
	private function sendToEducationTargetOrSwv(ExchangeHandOff $handOff, OutboundRecord $record): bool {
		switch ($handOff->target) {
			case 'uwlr':
				return $this->sendToUwlr(handOff: $handOff, record: $record);
			case 'edu-v':
				return $this->sendToEduV(handOff: $handOff, record: $record);
			case 'basispoort':
				$this->uwlrEduVService->syncBasispoort(kenmerk: $record->kenmerk, payload: $record->data);
				return true;
			case 'entree-content':
				$this->uwlrEduVService->syncEntreeContent(kenmerk: $record->kenmerk, payload: $record->data);
				return true;
			default:
				return $this->handOffSwv(data: $record->data, scope: $handOff->scope);
		}//end switch

	}//end sendToEducationTargetOrSwv()

	/**
	 * Send one bericht to BRON/ROD.
	 *
	 * @param ExchangeHandOff $handOff The job: its scope.
	 * @param OutboundRecord  $record  The record and its correlation id.
	 *
	 * @return bool Always true: the adapter throws when it refuses.
	 */
	private function sendToRod(ExchangeHandOff $handOff, OutboundRecord $record): bool {
		$this->rodService->sendBericht(
			berichtsoort: $this->parameter(name: 'berichtsoort', data: $record->data, scope: $handOff->scope, default: 'inschrijving'),
			kenmerk: $record->kenmerk,
			payload: $record->data
		);

		return true;

	}//end sendToRod()

	/**
	 * Send one melding to the Verzuimloket.
	 *
	 * @param ExchangeHandOff $handOff The job: its scope.
	 * @param OutboundRecord  $record  The record and its correlation id.
	 *
	 * @return bool Always true: the adapter throws when it refuses.
	 */
	private function sendToLeerplicht(ExchangeHandOff $handOff, OutboundRecord $record): bool {
		$this->verzuimloketService->sendMelding(
			meldingType: $this->parameter(name: 'meldingType', data: $record->data, scope: $handOff->scope, default: 'eerste-melding'),
			kenmerk: $record->kenmerk,
			payload: $record->data
		);

		return true;

	}//end sendToLeerplicht()

	/**
	 * Send one UWLR export.
	 *
	 * @param ExchangeHandOff $handOff The job: its scope.
	 * @param OutboundRecord  $record  The record and its correlation id.
	 *
	 * @return bool Always true: the adapter throws when it refuses.
	 */
	private function sendToUwlr(ExchangeHandOff $handOff, OutboundRecord $record): bool {
		$this->uwlrEduVService->sendUwlrExport(
			kenmerk: $record->kenmerk,
			subtype: $this->parameter(name: 'subtype', data: $record->data, scope: $handOff->scope, default: 'pupil'),
			payload: $record->data
		);

		return true;

	}//end sendToUwlr()

	/**
	 * Send one Edu-V export.
	 *
	 * @param ExchangeHandOff $handOff The job: its scope.
	 * @param OutboundRecord  $record  The record and its correlation id.
	 *
	 * @return bool Always true: the adapter throws when it refuses.
	 */
	private function sendToEduV(ExchangeHandOff $handOff, OutboundRecord $record): bool {
		$this->uwlrEduVService->sendEduVExport(
			kenmerk: $record->kenmerk,
			dataService: $this->parameter(name: 'dataService', data: $record->data, scope: $handOff->scope, default: 'onderwijsdeelnemers'),
			payload: $record->data
		);

		return true;

	}//end sendToEduV()

	/**
	 * Hand one SWV dossier to its receiver.
	 *
	 * @param array<string,mixed> $data  The mapped dossier.
	 * @param array<string,mixed> $scope The job's scope, carrying `receiverId`.
	 *
	 * @return bool True when the receiver acknowledged it.
	 */
	private function handOffSwv(array $data, array $scope): bool {
		$answer = $this->swvAdapter->handOffDossier(receiverId: (string)$scope['receiverId'], dossier: $data);

		return in_array((string)($answer['acceptedStatus'] ?? ''), self::SWV_ACCEPTED, true);

	}//end handOffSwv()

	/**
	 * A target parameter: the record's own value first, the job's scope second.
	 *
	 * @param string              $name    The parameter name.
	 * @param array<string,mixed> $data    The record.
	 * @param array<string,mixed> $scope   The job's scope.
	 * @param string              $default The fallback.
	 *
	 * @return string The value.
	 */
	private function parameter(string $name, array $data, array $scope, string $default): string {
		$value = (string)($data[$name] ?? '');
		if ($value === '') {
			$value = (string)($scope[$name] ?? '');
		}

		if ($value === '') {
			return $default;
		}

		return $value;

	}//end parameter()

	/**
	 * Turn an adapter's exception into a rejection.
	 *
	 * @param array<string,mixed> $record    The record.
	 * @param Throwable           $exception What the adapter threw.
	 *
	 * @return array<string,mixed> The rejection.
	 */
	private function rejection(array $record, Throwable $exception): array {
		$translation = ($exception instanceof RodTranslationException
			|| $exception instanceof VerzuimloketTranslationException
			|| $exception instanceof OsoTranslationException
			|| $exception instanceof UwlrEduVTranslationException);

		$code = self::CODE_SEND;
		$fields = [];
		if ($translation === true) {
			$code = self::CODE_TRANSLATION;
			$fields = $this->namedFields(message: $exception->getMessage());
		}

		return [
			'recordId' => (string)($record['recordId'] ?? ''),
			'sourceKind' => (string)($record['sourceKind'] ?? ''),
			'errorCode' => $code,
			'offendingFields' => $fields,
		];

	}//end rejection()

	/**
	 * The field names a translator's message quotes, such as `"bsn"`.
	 *
	 * @param string $message The message.
	 *
	 * @return array<int, string> The quoted names.
	 */
	private function namedFields(string $message): array {
		if (preg_match_all('/"([A-Za-z][A-Za-z0-9_.]*)"/', $message, $matches) === false) {
			return [];
		}

		return array_values(array_unique($matches[1]));

	}//end namedFields()
}//end class

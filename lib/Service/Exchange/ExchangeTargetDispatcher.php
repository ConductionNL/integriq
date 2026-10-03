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
use OCA\Integriq\Sources\Swv\SwvHandoffSourceAdapter;
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
	 * @param SwvHandoffSourceAdapter $swvAdapter          SWV hand-off.
	 * @param IEventDispatcher        $events              Hands import records to the owning app.
	 * @param LoggerInterface         $logger              Logger.
	 */
	public function __construct(
		private readonly RodService $rodService,
		private readonly VerzuimloketService $verzuimloketService,
		private readonly OsoService $osoService,
		private readonly UwlrEduVService $uwlrEduVService,
		private readonly SwvHandoffSourceAdapter $swvAdapter,
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
	 * @param string                           $jobId     The job's uuid.
	 * @param string                           $target    The target id.
	 * @param string                           $direction The direction.
	 * @param array<string,mixed>              $scope     The job's scope.
	 * @param array<int, array<string, mixed>> $records   `{recordId, sourceKind, data}`, data already mapped.
	 * @param string                           $ownerApp  The owning app, for an import that lands there.
	 * @param string                           $ownerRef  The owner's reference, for an import that lands there.
	 *
	 * @return array{accepted: array<int, string>, rejected: array<int, array<string, mixed>>, refusal: string|null, acceptedCount?: int}
	 *     Accepted record ids, rejections, and a job-wide refusal code when the job cannot run at all.
	 *     A landed import reports `acceptedCount` instead of ids: the owning app answers with a count.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-005-export-handlers-hand-records-to-the-existing-adapters
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-010-an-import-job-hands-its-records-to-the-owning-app
	 */
	public function dispatch(
		string $jobId,
		string $target,
		string $direction,
		array $scope,
		array $records,
		string $ownerApp='',
		string $ownerRef=''
	): array {
		$outcome = ['accepted' => [], 'rejected' => [], 'refusal' => null];
		if ($this->supports(target: $target, direction: $direction) === false) {
			$outcome['refusal'] = 'no-handler';
			return $outcome;
		}

		if (in_array($direction, (self::LANDED[$target] ?? []), true) === true) {
			return $this->land(
				context: [
					'jobId' => $jobId,
					'ownerApp' => $ownerApp,
					'target' => $target,
					'direction' => $direction,
					'ownerRef' => $ownerRef,
				],
				scope: $scope,
				records: $records
			);
		}

		if ($target === 'swv' && (string)($scope['receiverId'] ?? '') === '') {
			$outcome['refusal'] = self::CODE_SOURCE_MISSING;
			return $outcome;
		}

		foreach ($records as $record) {
			$recordId = (string)($record['recordId'] ?? '');
			$data = $record['data'] ?? [];
			if (is_array($data) === false) {
				$data = [];
			}

			try {
				$accepted = $this->sendOne(
					target: $target,
					kenmerk: $jobId . ':' . $recordId,
					data: $data,
					scope: $scope
				);
			} catch (Throwable $exception) {
				$outcome['rejected'][] = $this->rejection(record: $record, exception: $exception);
				continue;
			}

			if ($accepted === true) {
				$outcome['accepted'][] = $recordId;
				continue;
			}

			$outcome['rejected'][] = [
				'recordId' => $recordId,
				'sourceKind' => (string)($record['sourceKind'] ?? ''),
				'errorCode' => self::CODE_SEND,
				'offendingFields' => [],
			];
		}//end foreach

		return $outcome;

	}//end dispatch()

	/**
	 * Hand an import job's received records to the owning app and read its answer.
	 *
	 * The owning app answers through {@see ExchangeRecordsReceivedEvent::accept()}.
	 * No answer, or a listener that throws, is `no-owner-answer`.
	 *
	 * @param array{jobId: string, ownerApp: string, target: string, direction: string, ownerRef: string} $context The job.
	 * @param array<string,mixed>                                                                         $scope   The job's scope.
	 * @param array<int, array<string, mixed>>                                                            $records The mapped records.
	 *
	 * @return array{accepted: array<int, string>, rejected: array<int, array<string, mixed>>, refusal: string|null, acceptedCount?: int}
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-011-the-owning-apps-answer-ends-the-job
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-012-an-unanswered-import-ends-with-no-owner-answer
	 */
	private function land(array $context, array $scope, array $records): array {
		$event = new ExchangeRecordsReceivedEvent(
			jobId: $context['jobId'],
			ownerApp: $context['ownerApp'],
			target: $context['target'],
			direction: $context['direction'],
			ownerRef: $context['ownerRef'],
			scope: $scope,
			records: array_values($records)
		);

		try {
			$this->events->dispatchTyped($event);
		} catch (Throwable $exception) {
			// The exception class only: a listener's message can quote a record.
			$this->logger->warning(
				'[ExchangeTargetDispatcher] the owning app failed on the records of job '.$context['jobId'].': '.get_class($exception)
			);
			return ['accepted' => [], 'rejected' => [], 'refusal' => self::CODE_NO_OWNER_ANSWER];
		}

		if ($event->isAnswered() === false) {
			return ['accepted' => [], 'rejected' => [], 'refusal' => self::CODE_NO_OWNER_ANSWER];
		}

		return [
			'accepted' => [],
			'rejected' => $event->getRejected(),
			'refusal' => null,
			'acceptedCount' => $event->getAcceptedCount(),
		];

	}//end land()

	/**
	 * Send one record to the target's adapter.
	 *
	 * @param string              $target  The target id.
	 * @param string              $kenmerk The correlation id.
	 * @param array<string,mixed> $data    The mapped record.
	 * @param array<string,mixed> $scope   The job's scope.
	 *
	 * @return bool True when the adapter took the record.
	 */
	private function sendOne(string $target, string $kenmerk, array $data, array $scope): bool {
		switch ($target) {
			case 'bron-rod':
				$this->rodService->sendBericht(
					berichtsoort: $this->parameter(name: 'berichtsoort', data: $data, scope: $scope, default: 'inschrijving'),
					kenmerk: $kenmerk,
					payload: $data
				);
				return true;
			case 'leerplicht':
				$this->verzuimloketService->sendMelding(
					meldingType: $this->parameter(name: 'meldingType', data: $data, scope: $scope, default: 'eerste-melding'),
					kenmerk: $kenmerk,
					payload: $data
				);
				return true;
			case 'oso':
				$this->osoService->sendExport(kenmerk: $kenmerk, payload: $data);
				return true;
			case 'uwlr':
				$this->uwlrEduVService->sendUwlrExport(
					kenmerk: $kenmerk,
					subtype: $this->parameter(name: 'subtype', data: $data, scope: $scope, default: 'pupil'),
					payload: $data
				);
				return true;
			case 'edu-v':
				$this->uwlrEduVService->sendEduVExport(
					kenmerk: $kenmerk,
					dataService: $this->parameter(name: 'dataService', data: $data, scope: $scope, default: 'onderwijsdeelnemers'),
					payload: $data
				);
				return true;
			case 'basispoort':
				$this->uwlrEduVService->syncBasispoort(kenmerk: $kenmerk, payload: $data);
				return true;
			case 'entree-content':
				$this->uwlrEduVService->syncEntreeContent(kenmerk: $kenmerk, payload: $data);
				return true;
			default:
				return $this->handOffSwv(data: $data, scope: $scope);
		}//end switch

	}//end sendOne()

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

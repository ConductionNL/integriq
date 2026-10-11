<?php

/**
 * Integriq StUF-ZDS Outbound.
 *
 * Everything that happens to a ZDS case message after it is built: audit it,
 * send it to the ZDS service its operation belongs to, classify the answer,
 * drive the circuit breaker, retry a transient failure after 5 s, 30 s, 2 min
 * and 10 min with the same envelope, and announce how a kennisgeving ended.
 *
 * Ported from dossiq's StufOutboundTransport. {@see StufZdsCaseClient}
 * decides what to send; this class decides what the answer means.
 *
 * @category Service
 * @package  OCA\Integriq\Service\StufZkn
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
 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-circuit-breaker-and-retry
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\StufZkn;

use OCA\Integriq\BackgroundJob\StufZdsRetryJob;
use OCA\Integriq\Event\StufZdsCaseConcludedEvent;
use OCA\Integriq\Exception\StufZknProviderException;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends StUF-ZDS case messages and classifies the answers.
 *
 * @psalm-type ZdsResult = array{success:bool,retrying:bool,referentienummer:string,messageId:string,caseIdentification:string|null,fout:array<string,string>|null}
 *
 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-circuit-breaker-and-retry
 */
class StufZdsOutbound {

	/**
	 * Seconds to wait before each retry of a transient failure.
	 *
	 * @var int[]
	 */
	public const RETRY_BACKOFF_SECONDS = [5, 30, 120, 600];

	/**
	 * Per operation: the stuf_message berichttype, the berichtcode and the ZDS service URL key.
	 *
	 * @var array<string,array{berichttype:string,code:string,urlKey:string}>
	 */
	private const OPERATIONS = [
		'creeerZaak' => ['berichttype' => 'zakLk01', 'code' => 'Lk01', 'urlKey' => 'ontvangAsynchroonUrl'],
		'actualiseerZaak' => ['berichttype' => 'zakLk02', 'code' => 'Lk02', 'urlKey' => 'ontvangAsynchroonUrl'],
		'geefZaakdetails' => ['berichttype' => 'zakLv01', 'code' => 'Lv01', 'urlKey' => 'beantwoordVraagUrl'],
		'genereerZaakIdentificatie' => ['berichttype' => 'genereerZaakIdentificatie_Di02', 'code' => 'Di02', 'urlKey' => 'vrijeBerichtenUrl'],
		'vrijBericht' => ['berichttype' => 'vrijBericht_Du01', 'code' => 'Du01', 'urlKey' => 'vrijeBerichtenUrl'],
	];

	/**
	 * Constructor.
	 *
	 * @param StufZknClient         $transport  Sends envelopes (token, mTLS or WS-Security).
	 * @param StufZdsResponseReader $reader     Reads the answers.
	 * @param StufZdsCircuitBreaker $breaker    The per-source circuit breaker.
	 * @param StufZdsMessageLog     $log        Audit rows and source reads.
	 * @param IJobList              $jobList    Schedules retries.
	 * @param IEventDispatcher      $dispatcher Announces conclusions.
	 * @param LoggerInterface       $logger     The logger.
	 */
	public function __construct(
		private readonly StufZknClient $transport,
		private readonly StufZdsResponseReader $reader,
		private readonly StufZdsCircuitBreaker $breaker,
		private readonly StufZdsMessageLog $log,
		private readonly IJobList $jobList,
		private readonly IEventDispatcher $dispatcher,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Send a kennisgeving (creeerZaak, actualiseerZaak, vrijBericht) and classify the answer.
	 *
	 * @param array                                     $source    The source, `id` set.
	 * @param string                                    $operation The operation.
	 * @param array{referentienummer:string,xml:string} $message   The built message.
	 * @param array                                     $context   correlationId, sourceApp, caseIdentification.
	 *
	 * @return array The result (ZdsResult).
	 *
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-circuit-breaker-and-retry
	 */
	public function kennisgeving(array $source, string $operation, array $message, array $context): array {
		$row      = $this->openRow(source: $source, operation: $operation, message: $message, context: $context, keepEnvelope: true);
		$response = $this->transmit(source: $source, operation: $operation, envelope: $message['xml']);

		return $this->classify(source: $source, row: $row, response: $response, attempt: 1);
	}//end kennisgeving()

	/**
	 * Send a question (geefZaakdetails, genereerZaakIdentificatie) and conclude its row at once.
	 *
	 * A question is answered in the request, so it is not retried in the background.
	 *
	 * @param array                                     $source    The source, `id` set.
	 * @param string                                    $operation The operation.
	 * @param array{referentienummer:string,xml:string} $message   The built message.
	 * @param array                                     $context   correlationId, sourceApp, caseIdentification.
	 *
	 * @return array The result (ZdsResult) plus `answer`, the answer XML.
	 *
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-case-operations
	 */
	public function question(array $source, string $operation, array $message, array $context): array {
		$row      = $this->openRow(source: $source, operation: $operation, message: $message, context: $context, keepEnvelope: false);
		$response = $this->transmit(source: $source, operation: $operation, envelope: $message['xml']);

		$fout   = null;
		$caseId = (string)($context['caseIdentification'] ?? '');
		if ($this->isSuccess(response: $response) === false) {
			$fout = $this->failureOf(response: $response);
			$this->breaker->recordFailure(sourceId: $source['id']);
		}

		if ($fout === null) {
			$this->breaker->reset(sourceId: $source['id']);
			if ($operation === 'genereerZaakIdentificatie') {
				$caseId = (string)$this->reader->confirmation(xml: $response['responseXml'])['caseIdentification'];
			}
		}

		$row = $this->log->conclude(row: $row, sent: ($fout === null), attempt: 1, transport: $response, fout: $fout, caseIdentification: $caseId);

		return (
			$this->result(row: $row, success: ($fout === null), retrying: false, caseId: $caseId, fout: $fout) + ['answer' => $response['responseXml']]
		);
	}//end question()

	/**
	 * Re-send a kennisgeving whose last attempt failed transiently (called by the retry job).
	 *
	 * @param string $messageId The stuf_message uuid.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-circuit-breaker-and-retry
	 */
	public function retry(string $messageId): void {
		$row = $this->log->find(messageId: $messageId);
		if ($row === null || ($row['status'] ?? '') !== 'retrying' || (string)($row['envelope'] ?? '') === '') {
			$this->logger->info('StUF-ZDS retry: message {id} is not waiting for a retry', ['id' => $messageId]);
			return;
		}

		$attempt = ((int)($row['attempts'] ?? 0) + 1);
		try {
			$source = $this->log->source(sourceId: (string)($row['sourceId'] ?? ''));
		} catch (StufZknProviderException $exception) {
			$fout = ['code' => 'SOURCE_GONE', 'omschrijving' => $exception->getMessage(), 'details' => '', 'kind' => 'permanent'];
			$this->conclude(row: $row, sent: false, attempt: $attempt, transport: [], fout: $fout, caseId: null);
			return;
		}

		if (($source['configuration']['provider'] ?? 'log') !== 'rest') {
			$fout = ['code' => 'SOURCE_NOT_REST', 'omschrijving' => 'The source left rest mode; nothing was sent', 'details' => '', 'kind' => 'permanent'];
			$this->conclude(row: $row, sent: false, attempt: $attempt, transport: [], fout: $fout, caseId: null);
			return;
		}

		if ($this->breaker->allows(sourceId: $source['id']) === false) {
			// Not an attempt: wait out the cooldown and try again.
			$this->schedule(messageId: $messageId, delay: StufZdsCircuitBreaker::COOLDOWN_SECONDS);
			return;
		}

		$response = $this->transmit(source: $source, operation: (string)($row['operation'] ?? ''), envelope: (string)$row['envelope']);
		$this->classify(source: $source, row: $row, response: $response, attempt: $attempt);
	}//end retry()

	/**
	 * Classify the answer to a kennisgeving attempt: sent, retry later, or failed.
	 *
	 * @param array $source   The source.
	 * @param array $row      The stuf_message row.
	 * @param array $response The transport result.
	 * @param int   $attempt  The attempt (1-indexed).
	 *
	 * @return array The result (ZdsResult).
	 */
	private function classify(array $source, array $row, array $response, int $attempt): array {
		if ($this->isSuccess(response: $response) === true) {
			$caseId = $this->reader->confirmation(xml: $response['responseXml'])['caseIdentification'];
			$this->breaker->reset(sourceId: $source['id']);
			return $this->conclude(row: $row, sent: true, attempt: $attempt, transport: $response, fout: null, caseId: $caseId);
		}

		$fout = $this->failureOf(response: $response);
		$this->breaker->recordFailure(sourceId: $source['id']);
		if ($this->isTransient(response: $response, fout: $fout) === false || $attempt > count(self::RETRY_BACKOFF_SECONDS)) {
			return $this->conclude(row: $row, sent: false, attempt: $attempt, transport: $response, fout: $fout, caseId: null);
		}

		$delay = self::RETRY_BACKOFF_SECONDS[($attempt - 1)];
		$row   = $this->log->retrying(row: $row, attempt: $attempt, transport: $response, fout: $fout, retryAfter: $delay);
		$this->schedule(messageId: (string)$row['id'], delay: $delay);

		return $this->result(row: $row, success: false, retrying: true, caseId: null, fout: $fout);
	}//end classify()

	/**
	 * Whether a failed attempt is worth retrying.
	 *
	 * A StUF fout in the body decides: SOAP 1.1 answers every fault with HTTP
	 * 500, so the status alone would retry a permanent refusal four times.
	 * Without one, the transport's verdict or the HTTP status decides.
	 *
	 * @param array $response The transport result.
	 * @param array $fout     The classified fout.
	 *
	 * @return bool True when transient.
	 */
	private function isTransient(array $response, array $fout): bool {
		$status = (int)$response['httpStatus'];
		$stufFault = ($fout['code'] !== '' && (string)$response['responseXml'] !== '');
		if ($stufFault === true || $status === 0) {
			return ($fout['kind'] === 'transient');
		}

		return ($status === 408 || $status === 429 || ($status >= 500 && $status < 600));
	}//end isTransient()

	/**
	 * Close a kennisgeving's row, announce the conclusion, and report it.
	 *
	 * @param array       $row       The row.
	 * @param bool        $sent      Whether the case system confirmed it.
	 * @param int         $attempt   The concluding attempt.
	 * @param array       $transport The transport result.
	 * @param array|null  $fout      The fout on failure.
	 * @param string|null $caseId    The zaak identificatie the case system named.
	 *
	 * @return array The result (ZdsResult).
	 */
	private function conclude(array $row, bool $sent, int $attempt, array $transport, ?array $fout, ?string $caseId): array {
		$known  = ($caseId ?? (string)($row['caseIdentification'] ?? ''));
		$row    = $this->log->conclude(row: $row, sent: $sent, attempt: $attempt, transport: $transport, fout: $fout, caseIdentification: $known);
		$result = $this->result(row: $row, success: $sent, retrying: false, caseId: $known, fout: $fout);

		try {
			$this->dispatcher->dispatchTyped(
				new StufZdsCaseConcludedEvent(
					sourceApp: (string)($row['sourceApp'] ?? ''),
					correlationId: (string)($row['correlationId'] ?? ''),
					operation: (string)($row['operation'] ?? ''),
					attempts: $attempt,
					result: $result
				)
			);
		} catch (Throwable $exception) {
			$this->logger->warning('StUF-ZDS conclusion listener failed: {error}', ['error' => $exception->getMessage()]);
		}

		return $result;
	}//end conclude()

	/**
	 * The result array of a request.
	 *
	 * @param array       $row      The row.
	 * @param bool        $success  Whether the case system confirmed it.
	 * @param bool        $retrying Whether a retry is scheduled.
	 * @param string|null $caseId   The zaak identificatie, if known.
	 * @param array|null  $fout     The fout, if any.
	 *
	 * @return array The result (ZdsResult).
	 */
	private function result(array $row, bool $success, bool $retrying, ?string $caseId, ?array $fout): array {
		if ($caseId === '') {
			$caseId = null;
		}

		return [
			'success' => $success,
			'retrying' => $retrying,
			'referentienummer' => (string)($row['referentienummer'] ?? ''),
			'messageId' => (string)($row['id'] ?? ''),
			'caseIdentification' => $caseId,
			'fout' => $fout,
		];
	}//end result()

	/**
	 * Open the audit row of a message.
	 *
	 * @param array  $source       The source.
	 * @param string $operation    The operation.
	 * @param array  $message      The built message.
	 * @param array  $context      correlationId, sourceApp, caseIdentification.
	 * @param bool   $keepEnvelope Keep the envelope for a retry (kennisgevingen only).
	 *
	 * @return array The row.
	 */
	private function openRow(array $source, string $operation, array $message, array $context, bool $keepEnvelope): array {
		$envelope = null;
		if ($keepEnvelope === true) {
			$envelope = $message['xml'];
		}

		return $this->log->open(
			fields: [
				'berichttype' => self::OPERATIONS[$operation]['berichttype'],
				'operation' => $operation,
				'entiteittype' => 'ZAK',
				'referentienummer' => $message['referentienummer'],
				'sourceId' => $source['id'],
				'sourceApp' => (string)($context['sourceApp'] ?? ''),
				'correlationId' => (string)($context['correlationId'] ?? ''),
				'caseIdentification' => (string)($context['caseIdentification'] ?? ''),
				'envelope' => $envelope,
			]
		);
	}//end openRow()

	/**
	 * Send an envelope to the ZDS service the operation belongs to.
	 *
	 * The SOAPAction is `<zkn namespace>/<functie>_<berichtcode>`, the functie
	 * read from the envelope itself (a vrij bericht is named by its template).
	 *
	 * @param array  $source    The source.
	 * @param string $operation The operation.
	 * @param string $envelope  The envelope.
	 *
	 * @return array{httpStatus:int,responseXml:string,durationMs:int,fout:array<string,string>|null}
	 */
	private function transmit(array $source, string $operation, string $envelope): array {
		$spec          = (self::OPERATIONS[$operation] ?? self::OPERATIONS['vrijBericht']);
		$configuration = (array)($source['configuration'] ?? []);
		$functie       = $operation;
		$matches       = [];
		if (preg_match('#<stuf:functie>([^<]+)</stuf:functie>#', $envelope, $matches) === 1) {
			$functie = html_entity_decode($matches[1], (ENT_XML1 | ENT_QUOTES), 'UTF-8');
		}

		return $this->transport->transmit(
			sourceConfiguration: $configuration,
			soapAction: '"' . StufZknNamespaces::ZKN . '/' . $functie . '_' . $spec['code'] . '"',
			envelopeXml: $envelope,
			url: (string)($configuration[$spec['urlKey']] ?? '')
		);
	}//end transmit()

	/**
	 * Whether an attempt got a 2xx answer.
	 *
	 * @param array $response The transport result.
	 *
	 * @return bool True on 2xx.
	 */
	private function isSuccess(array $response): bool {
		$status = (int)$response['httpStatus'];
		return ($status >= 200 && $status < 300);
	}//end isSuccess()

	/**
	 * The fout of an unsuccessful attempt: the transport's, else the StUF fout in the body.
	 *
	 * @param array $response The transport result.
	 *
	 * @return array{code:string,omschrijving:string,details:string,kind:string}
	 */
	private function failureOf(array $response): array {
		if (is_array($response['fout'] ?? null) === true) {
			return array_merge(['code' => '', 'omschrijving' => '', 'details' => '', 'kind' => 'transient'], $response['fout']);
		}

		if ((string)$response['responseXml'] === '') {
			return [
				'code' => 'HTTP_' . (int)$response['httpStatus'],
				'omschrijving' => 'The case system answered without a body',
				'details' => '',
				'kind' => 'permanent',
			];
		}

		return $this->reader->fault(xml: (string)$response['responseXml']);
	}//end failureOf()

	/**
	 * Schedule the retry job for a message.
	 *
	 * @param string $messageId The row uuid.
	 * @param int    $delay     Seconds from now.
	 *
	 * @return void
	 */
	private function schedule(string $messageId, int $delay): void {
		try {
			$this->jobList->scheduleAfter(StufZdsRetryJob::class, (time() + $delay), ['messageId' => $messageId]);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'StUF-ZDS retry could not be scheduled for {id}: {error}',
				['id' => $messageId, 'error' => $exception->getMessage()]
			);
		}
	}//end schedule()
}//end class

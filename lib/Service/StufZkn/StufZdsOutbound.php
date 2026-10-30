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
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The send path is one orchestration: transport, answer reader,
 *     circuit breaker, audit log, retry job, conclusion event and, since the credentials moved out of the persisted
 *     envelope, the message builder and credential resolver that add the UsernameToken at send time.
 *
 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-circuit-breaker-and-retry
 */
class StufZdsOutbound implements StufZdsOutboundInterface {

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
	 * @param StufZdsMessageBuilder $builder    Adds the UsernameToken to an envelope at send time.
	 * @param StufZdsCredentialResolver $credentials Decrypts the UsernameToken at send time.
	 */
	public function __construct(
		private readonly StufZknClient $transport,
		private readonly StufZdsResponseReader $reader,
		private readonly StufZdsCircuitBreaker $breaker,
		private readonly StufZdsMessageLog $log,
		private readonly IJobList $jobList,
		private readonly IEventDispatcher $dispatcher,
		private readonly LoggerInterface $logger,
		private readonly StufZdsMessageBuilder $builder,
		private readonly StufZdsCredentialResolver $credentials,
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
			$this->breakerCall(sourceId: $source['id'], call: fn (string $sourceId) => $this->breaker->recordFailure(sourceId: $sourceId));
		}

		if ($fout === null) {
			$this->breakerCall(sourceId: $source['id'], call: fn (string $sourceId) => $this->breaker->reset(sourceId: $sourceId));
			if ($operation === 'genereerZaakIdentificatie') {
				$caseId = (string)$this->reader->confirmation(xml: $response['responseXml'])['caseIdentification'];
			}
		}

		$row = $this->concludeRow(row: $row, sent: ($fout === null), attempt: 1, transport: $response, fout: $fout, caseIdentification: $caseId);

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

		// Fails open: a breaker that cannot be read must not strand the row with its job consumed.
		$allowed = $this->breakerCall(sourceId: $source['id'], call: fn (string $sourceId) => $this->breaker->allows(sourceId: $sourceId), fallback: true);
		if ($allowed === false) {
			// Not an attempt: wait out the cooldown and try again.
			if ($this->schedule(messageId: $messageId, delay: StufZdsCircuitBreaker::COOLDOWN_SECONDS) === false) {
				$last = ['code' => (string)($row['faultCode'] ?? '')];
				$this->conclude(row: $row, sent: false, attempt: ($attempt - 1), transport: [], fout: $this->unscheduledFout(previous: $last), caseId: null);
			}

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
			$this->breakerCall(sourceId: $source['id'], call: fn (string $sourceId) => $this->breaker->reset(sourceId: $sourceId));
			return $this->conclude(row: $row, sent: true, attempt: $attempt, transport: $response, fout: null, caseId: $caseId);
		}

		$fout = $this->failureOf(response: $response);
		$this->breakerCall(sourceId: $source['id'], call: fn (string $sourceId) => $this->breaker->recordFailure(sourceId: $sourceId));
		if ($this->isTransient(response: $response, fout: $fout) === false || $attempt > count(self::RETRY_BACKOFF_SECONDS)) {
			return $this->conclude(row: $row, sent: false, attempt: $attempt, transport: $response, fout: $fout, caseId: null);
		}

		// A row that cannot be marked retrying, or a retry that cannot be
		// scheduled, would leave the message `retrying` with no job behind it and
		// the owning app waiting for a conclusion that never comes: conclude it as
		// failed instead.
		$delay = self::RETRY_BACKOFF_SECONDS[($attempt - 1)];
		try {
			$row = $this->log->retrying(row: $row, attempt: $attempt, transport: $response, fout: $fout, retryAfter: $delay);
		} catch (Throwable $exception) {
			$this->logger->error(
				'StUF-ZDS message {id} could not be marked for a retry: {error}',
				['id' => (string)($row['id'] ?? ''), 'error' => $exception->getMessage()]
			);
			return $this->conclude(row: $row, sent: false, attempt: $attempt, transport: $response, fout: $fout, caseId: null);
		}

		if ($this->schedule(messageId: (string)$row['id'], delay: $delay) === false) {
			$unscheduled = $this->unscheduledFout(previous: $fout);
			return $this->conclude(row: $row, sent: false, attempt: $attempt, transport: $response, fout: $unscheduled, caseId: null);
		}

		return $this->result(row: $row, success: false, retrying: true, caseId: null, fout: $fout);
	}//end classify()

	/**
	 * Whether a failed attempt is worth retrying.
	 *
	 * A StUF fout in the body decides: SOAP 1.1 answers every fault with HTTP
	 * 500, so the status alone would retry a permanent refusal four times.
	 * Without one, the transport's verdict or the HTTP status decides. A body
	 * that did not parse (`PARSE_ERROR`: a load balancer's HTML 503 page, a
	 * plain-text 502) carries no fout, so the status decides for it too.
	 *
	 * @param array $response The transport result.
	 * @param array $fout     The classified fout.
	 *
	 * @return bool True when transient.
	 */
	private function isTransient(array $response, array $fout): bool {
		$status = (int)$response['httpStatus'];
		$stufFault = ($fout['code'] !== '' && $fout['code'] !== 'PARSE_ERROR' && (string)$response['responseXml'] !== '');
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
		$row    = $this->concludeRow(row: $row, sent: $sent, attempt: $attempt, transport: $transport, fout: $fout, caseIdentification: $known);
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
	 * Write a message's conclusion to its row, without letting a failed write undo the send.
	 *
	 * Once the case system answered, that answer is the outcome: a row that
	 * cannot be saved must not turn a created zaak into a refusal, which the
	 * caller would retry into a second zaak. The failure is logged and the
	 * unsaved row is returned.
	 *
	 * @param array       $row                The row.
	 * @param bool        $sent               Whether the case system confirmed it.
	 * @param int         $attempt            The concluding attempt.
	 * @param array       $transport          The transport result.
	 * @param array|null  $fout               The fout on failure.
	 * @param string|null $caseIdentification The zaak identificatie, if known.
	 *
	 * @return array The saved row, or the row as it was when the save failed.
	 */
	private function concludeRow(array $row, bool $sent, int $attempt, array $transport, ?array $fout, ?string $caseIdentification): array {
		try {
			return $this->log->conclude(
				row: $row,
				sent: $sent,
				attempt: $attempt,
				transport: $transport,
				fout: $fout,
				caseIdentification: $caseIdentification
			);
		} catch (Throwable $exception) {
			$this->logger->error(
				'StUF-ZDS message {id} was concluded (sent: {sent}) but its row could not be saved: {error}',
				['id' => (string)($row['id'] ?? ''), 'sent' => json_encode($sent), 'error' => $exception->getMessage()]
			);
			return $row;
		}
	}//end concludeRow()

	/**
	 * Call the circuit breaker without letting its bookkeeping change a message's outcome.
	 *
	 * The breaker reads and writes app config. After the case system answered,
	 * a failed call must not reach the listener, which would refuse a zaak that
	 * now exists; around a retry it must not leave the row `retrying` with no
	 * job behind it. The failure is logged and the fallback is returned.
	 *
	 * @param string   $sourceId The source.
	 * @param callable $call     The breaker call (allows, recordFailure or reset), given the source id.
	 * @param mixed    $fallback What to return when the call throws.
	 *
	 * @return mixed What the call returned, or the fallback.
	 */
	private function breakerCall(string $sourceId, callable $call, $fallback=null) {
		try {
			return $call($sourceId);
		} catch (Throwable $exception) {
			$this->logger->error(
				'StUF-ZDS circuit breaker for source {source} could not be used: {error}',
				['source' => $sourceId, 'error' => $exception->getMessage()]
			);
			return $fallback;
		}
	}//end breakerCall()

	/**
	 * The fout of a message whose retry could not be scheduled.
	 *
	 * @param array|null $previous The fout of the last attempt; its code goes into `details`.
	 *
	 * @return array{code:string,omschrijving:string,details:string,kind:string}
	 */
	private function unscheduledFout(?array $previous): array {
		return [
			'code' => 'RETRY_NOT_SCHEDULED',
			'omschrijving' => 'The retry could not be scheduled, so the message is concluded as failed',
			'details' => (string)($previous['code'] ?? ''),
			'kind' => 'permanent',
		];
	}//end unscheduledFout()

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
	 * The WS-Security UsernameToken is added to the envelope here, never before:
	 * what is persisted on the row for a retry holds no credentials.
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

		// The persisted envelope carries no credentials; the token is added here, for the first
		// attempt and for every retry alike, and lives only in this request.
		try {
			$envelope = $this->builder->withUsernameToken(
				envelopeXml: $envelope,
				wsse: $this->credentials->usernameToken(sourceConfiguration: $configuration)
			);
		} catch (StufZknProviderException $exception) {
			return [
				'httpStatus' => 0,
				'responseXml' => '',
				'durationMs' => 0,
				'fout' => ['code' => 'CREDENTIALS', 'omschrijving' => $exception->getMessage(), 'details' => '', 'kind' => 'permanent'],
			];
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
	 * @return bool False when the job could not be scheduled.
	 */
	private function schedule(string $messageId, int $delay): bool {
		try {
			$this->jobList->scheduleAfter(StufZdsRetryJob::class, (time() + $delay), ['messageId' => $messageId]);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'StUF-ZDS retry could not be scheduled for {id}: {error}',
				['id' => $messageId, 'error' => $exception->getMessage()]
			);
			return false;
		}

		return true;
	}//end schedule()
}//end class

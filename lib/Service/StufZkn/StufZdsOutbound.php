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
 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-circuit-breaker-and-retry
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\StufZkn;

use OCA\Integriq\BackgroundJob\MessageRetrySchedulerInterface;
use OCA\Integriq\Event\StufZdsCaseConcludedEvent;
use OCA\Integriq\Exception\StufZknProviderException;
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
 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-circuit-breaker-and-retry
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
	 * @param MessageRetrySchedulerInterface $retries Schedules retries.
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
		private readonly MessageRetrySchedulerInterface $retries,
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
	 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-circuit-breaker-and-retry
	 */
	public function kennisgeving(array $source, string $operation, array $message, array $context): array {
		$request = new ZdsRequest(source: $source, operation: $operation, message: $message, context: $context);
		$attempt = $this->firstAttempt(request: $request, keepEnvelope: true);
		$attempt->response = $this->transmit(attempt: $attempt);

		return $this->classify(attempt: $attempt);
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
	 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-case-operations
	 */
	public function question(array $source, string $operation, array $message, array $context): array {
		$request = new ZdsRequest(source: $source, operation: $operation, message: $message, context: $context);
		$attempt = $this->firstAttempt(request: $request, keepEnvelope: false);
		$attempt->response = $this->transmit(attempt: $attempt);

		$conclusion = $this->answerConclusion(attempt: $attempt, caseId: (string)($context['caseIdentification'] ?? ''));
		$row        = $this->concludeRow(row: $attempt->row, conclusion: $conclusion);

		return ($this->result(row: $row, conclusion: $conclusion) + ['answer' => $attempt->response['responseXml']]);
	}//end question()

	/**
	 * Open a message's row and start its first attempt.
	 *
	 * @param ZdsRequest $request      The source, operation, built message and context.
	 * @param bool       $keepEnvelope Keep the envelope for a retry (kennisgevingen only).
	 *
	 * @return ZdsAttempt The first attempt, not yet transmitted.
	 */
	private function firstAttempt(ZdsRequest $request, bool $keepEnvelope): ZdsAttempt {
		$attempt = new ZdsAttempt(row: $this->openRow(request: $request, keepEnvelope: $keepEnvelope), number: 1);
		$attempt->source    = $request->source;
		$attempt->operation = $request->operation;
		$attempt->envelope  = $request->message['xml'];

		return $attempt;
	}//end firstAttempt()

	/**
	 * How an answered question ended, recorded on the circuit breaker.
	 *
	 * @param ZdsAttempt $attempt The transmitted question.
	 * @param string     $caseId  The zaak identificatie the caller named, or ''.
	 *
	 * @return ZdsConclusion The conclusion; a generated identificatie replaces the caller's.
	 */
	private function answerConclusion(ZdsAttempt $attempt, string $caseId): ZdsConclusion {
		$fout = null;
		if ($this->isSuccess(response: $attempt->response) === false) {
			$fout = $this->failureOf(response: $attempt->response);
			$this->breakerCall(sourceId: $attempt->source['id'], call: fn (string $sourceId) => $this->breaker->recordFailure(sourceId: $sourceId));
		}

		if ($fout === null) {
			$this->breakerCall(sourceId: $attempt->source['id'], call: fn (string $sourceId) => $this->breaker->reset(sourceId: $sourceId));
			if ($attempt->operation === 'genereerZaakIdentificatie') {
				$caseId = (string)$this->reader->confirmation(xml: $attempt->response['responseXml'])['caseIdentification'];
			}
		}

		return new ZdsConclusion(sent: ($fout === null), attempt: 1, transport: $attempt->response, fout: $fout, caseId: $caseId);
	}//end answerConclusion()

	/**
	 * Re-send a kennisgeving whose last attempt failed transiently (called by the retry job).
	 *
	 * @param string $messageId The stuf_message uuid.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-circuit-breaker-and-retry
	 */
	public function retry(string $messageId): void {
		$row = $this->log->find(messageId: $messageId);
		if ($this->isWaitingForRetry(row: $row) === false) {
			$this->logger->info('StUF-ZDS retry: message {id} is not waiting for a retry', ['id' => $messageId]);
			return;
		}

		$attempt = $this->retryAttempt(row: $row);
		if ($attempt === null || $this->breakerAllowsRetry(messageId: $messageId, attempt: $attempt) === false) {
			return;
		}

		$attempt->response = $this->transmit(attempt: $attempt);
		$this->classify(attempt: $attempt);
	}//end retry()

	/**
	 * Whether a row is a kennisgeving waiting for a retry, with its envelope kept.
	 *
	 * @param array|null $row The stuf_message row, null when there is none.
	 *
	 * @return bool True when it can be retried.
	 */
	private function isWaitingForRetry(?array $row): bool {
		return ($row !== null && ($row['status'] ?? '') === 'retrying' && (string)($row['envelope'] ?? '') !== '');
	}//end isWaitingForRetry()

	/**
	 * Start the next attempt of a waiting row, or conclude it when its source cannot take one.
	 *
	 * @param array $row The stuf_message row.
	 *
	 * @return ZdsAttempt|null The attempt, null when the row was concluded instead.
	 */
	private function retryAttempt(array $row): ?ZdsAttempt {
		$attempt = new ZdsAttempt(row: $row, number: ((int)($row['attempts'] ?? 0) + 1));
		$attempt->operation = (string)($row['operation'] ?? '');
		$attempt->envelope  = (string)$row['envelope'];
		if ($this->resolveRetrySource(attempt: $attempt) === false) {
			return null;
		}

		return $attempt;
	}//end retryAttempt()

	/**
	 * Find the rest-mode source of a retry, concluding the row as failed when it is gone or left rest mode.
	 *
	 * @param ZdsAttempt $attempt The retry.
	 *
	 * @return bool True when the source can take the retry.
	 */
	private function resolveRetrySource(ZdsAttempt $attempt): bool {
		try {
			$attempt->source = $this->log->source(sourceId: (string)($attempt->row['sourceId'] ?? ''));
		} catch (StufZknProviderException $exception) {
			$fout = ['code' => 'SOURCE_GONE', 'omschrijving' => $exception->getMessage(), 'details' => '', 'kind' => 'permanent'];
			$this->refuseRetry(attempt: $attempt, fout: $fout);
			return false;
		}

		if (($attempt->source['configuration']['provider'] ?? 'log') !== 'rest') {
			$fout = ['code' => 'SOURCE_NOT_REST', 'omschrijving' => 'The source left rest mode; nothing was sent', 'details' => '', 'kind' => 'permanent'];
			$this->refuseRetry(attempt: $attempt, fout: $fout);
			return false;
		}

		return true;
	}//end resolveRetrySource()

	/**
	 * Conclude a retry that was not sent as failed.
	 *
	 * @param ZdsAttempt $attempt The retry.
	 * @param array      $fout    Why it was not sent.
	 *
	 * @return void
	 */
	private function refuseRetry(ZdsAttempt $attempt, array $fout): void {
		$refused = new ZdsConclusion(sent: false, attempt: $attempt->number, transport: [], fout: $fout, caseId: null);
		$this->conclude(row: $attempt->row, conclusion: $refused);
	}//end refuseRetry()

	/**
	 * Whether the circuit breaker lets the retry through; when it is open, wait out its cooldown.
	 *
	 * @param string     $messageId The stuf_message uuid.
	 * @param ZdsAttempt $attempt   The retry.
	 *
	 * @return bool True when the retry may be sent now.
	 */
	private function breakerAllowsRetry(string $messageId, ZdsAttempt $attempt): bool {
		// Fails open: a breaker that cannot be read must not strand the row with its job consumed.
		$allowed = $this->breakerCall(
			sourceId: $attempt->source['id'],
			call: fn (string $sourceId) => $this->breaker->allows(sourceId: $sourceId),
			fallback: true
		);
		if ($allowed === false) {
			$this->waitOutCooldown(messageId: $messageId, row: $attempt->row);
			return false;
		}

		return true;
	}//end breakerAllowsRetry()

	/**
	 * Not an attempt: wait out the open breaker's cooldown and try again, or conclude the row when that cannot be scheduled.
	 *
	 * @param string $messageId The stuf_message uuid.
	 * @param array  $row       The stuf_message row.
	 *
	 * @return void
	 */
	private function waitOutCooldown(string $messageId, array $row): void {
		if ($this->schedule(messageId: $messageId, delay: StufZdsCircuitBreaker::COOLDOWN_SECONDS) === true) {
			return;
		}

		$fout        = $this->unscheduledFout(previous: ['code' => (string)($row['faultCode'] ?? '')]);
		$unscheduled = new ZdsConclusion(sent: false, attempt: (int)($row['attempts'] ?? 0), transport: [], fout: $fout, caseId: null);
		$this->conclude(row: $row, conclusion: $unscheduled);
	}//end waitOutCooldown()

	/**
	 * Classify the answer to a kennisgeving attempt: sent, retry later, or failed.
	 *
	 * @param ZdsAttempt $attempt The transmitted attempt.
	 *
	 * @return array The result (ZdsResult).
	 */
	private function classify(ZdsAttempt $attempt): array {
		if ($this->isSuccess(response: $attempt->response) === true) {
			return $this->concludeSent(attempt: $attempt);
		}

		$attempt->fout = $this->failureOf(response: $attempt->response);
		$this->breakerCall(sourceId: $attempt->source['id'], call: fn (string $sourceId) => $this->breaker->recordFailure(sourceId: $sourceId));
		if ($this->isTransient(response: $attempt->response, fout: $attempt->fout) === false || $attempt->number > count(self::RETRY_BACKOFF_SECONDS)) {
			return $this->concludeFailed(attempt: $attempt);
		}

		return $this->scheduleRetry(attempt: $attempt);
	}//end classify()

	/**
	 * Conclude a confirmed kennisgeving with the zaak identificatie the case system named.
	 *
	 * @param ZdsAttempt $attempt The confirmed attempt.
	 *
	 * @return array The result (ZdsResult).
	 */
	private function concludeSent(ZdsAttempt $attempt): array {
		$caseId = $this->reader->confirmation(xml: $attempt->response['responseXml'])['caseIdentification'];
		$this->breakerCall(sourceId: $attempt->source['id'], call: fn (string $sourceId) => $this->breaker->reset(sourceId: $sourceId));
		$sent = new ZdsConclusion(sent: true, attempt: $attempt->number, transport: $attempt->response, fout: null, caseId: $caseId);
		return $this->conclude(row: $attempt->row, conclusion: $sent);
	}//end concludeSent()

	/**
	 * Conclude a failed kennisgeving attempt with its fout.
	 *
	 * @param ZdsAttempt $attempt The failed attempt.
	 *
	 * @return array The result (ZdsResult).
	 */
	private function concludeFailed(ZdsAttempt $attempt): array {
		$failed = new ZdsConclusion(sent: false, attempt: $attempt->number, transport: $attempt->response, fout: $attempt->fout, caseId: null);
		return $this->conclude(row: $attempt->row, conclusion: $failed);
	}//end concludeFailed()

	/**
	 * Mark a transiently failed attempt for a retry and schedule it.
	 *
	 * A row that cannot be marked retrying, or a retry that cannot be
	 * scheduled, would leave the message `retrying` with no job behind it and
	 * the owning app waiting for a conclusion that never comes: it is
	 * concluded as failed instead.
	 *
	 * @param ZdsAttempt $attempt The failed attempt, its fout set.
	 *
	 * @return array The result (ZdsResult).
	 */
	private function scheduleRetry(ZdsAttempt $attempt): array {
		$delay = self::RETRY_BACKOFF_SECONDS[($attempt->number - 1)];
		if ($this->markRetrying(attempt: $attempt, delay: $delay) === false) {
			return $this->concludeFailed(attempt: $attempt);
		}

		if ($this->schedule(messageId: (string)$attempt->row['id'], delay: $delay) === false) {
			return $this->concludeUnscheduled(attempt: $attempt);
		}

		return $this->retryingResult(attempt: $attempt);
	}//end scheduleRetry()

	/**
	 * Conclude a kennisgeving whose retry could not be scheduled as failed.
	 *
	 * @param ZdsAttempt $attempt The failed attempt, its fout set.
	 *
	 * @return array The result (ZdsResult).
	 */
	private function concludeUnscheduled(ZdsAttempt $attempt): array {
		$unscheduled = $this->unscheduledFout(previous: $attempt->fout);
		$failed      = new ZdsConclusion(sent: false, attempt: $attempt->number, transport: $attempt->response, fout: $unscheduled, caseId: null);
		return $this->conclude(row: $attempt->row, conclusion: $failed);
	}//end concludeUnscheduled()

	/**
	 * The result of a kennisgeving whose retry is scheduled.
	 *
	 * @param ZdsAttempt $attempt The failed attempt, its row marked retrying.
	 *
	 * @return array The result (ZdsResult).
	 */
	private function retryingResult(ZdsAttempt $attempt): array {
		$retrying = new ZdsConclusion(
			sent: false,
			attempt: $attempt->number,
			transport: $attempt->response,
			fout: $attempt->fout,
			caseId: null,
			retrying: true
		);
		return $this->result(row: $attempt->row, conclusion: $retrying);
	}//end retryingResult()

	/**
	 * Save the attempt's row as retrying, logging a save that failed.
	 *
	 * @param ZdsAttempt $attempt The failed attempt; its row is replaced by the saved one.
	 * @param int        $delay   Seconds until the retry.
	 *
	 * @return bool True when the row was saved.
	 */
	private function markRetrying(ZdsAttempt $attempt, int $delay): bool {
		try {
			$attempt->row = $this->log->retrying(
				row: $attempt->row,
				attempt: $attempt->number,
				transport: $attempt->response,
				fout: $attempt->fout,
				retryAfter: $delay
			);
			return true;
		} catch (Throwable $exception) {
			$this->logUnmarkedRetry(row: $attempt->row, exception: $exception);
			return false;
		}
	}//end markRetrying()

	/**
	 * Log a row that could not be marked for a retry.
	 *
	 * @param array     $row       The row as it was.
	 * @param Throwable $exception Why the save failed.
	 *
	 * @return void
	 */
	private function logUnmarkedRetry(array $row, Throwable $exception): void {
		$this->logger->error(
			'StUF-ZDS message {id} could not be marked for a retry: {error}',
			['id' => (string)($row['id'] ?? ''), 'error' => $exception->getMessage()]
		);
	}//end logUnmarkedRetry()

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
	 * @param array         $row        The row.
	 * @param ZdsConclusion $conclusion How the attempt ended; a missing zaak identificatie falls back to the row's.
	 *
	 * @return array The result (ZdsResult).
	 */
	private function conclude(array $row, ZdsConclusion $conclusion): array {
		$known  = $this->withKnownCase(row: $row, conclusion: $conclusion);
		$row    = $this->concludeRow(row: $row, conclusion: $known);
		$result = $this->result(row: $row, conclusion: $known);

		try {
			$this->dispatcher->dispatchTyped(
				new StufZdsCaseConcludedEvent(
					sourceApp: (string)($row['sourceApp'] ?? ''),
					correlationId: (string)($row['correlationId'] ?? ''),
					operation: (string)($row['operation'] ?? ''),
					attempts: $known->attempt,
					result: $result
				)
			);
		} catch (Throwable $exception) {
			$this->logger->warning('StUF-ZDS conclusion listener failed: {error}', ['error' => $exception->getMessage()]);
		}

		return $result;
	}//end conclude()

	/**
	 * The conclusion with the zaak identificatie the case system named, else the one the row already holds.
	 *
	 * @param array         $row        The row.
	 * @param ZdsConclusion $conclusion How the attempt ended.
	 *
	 * @return ZdsConclusion The conclusion with its known zaak identificatie.
	 */
	private function withKnownCase(array $row, ZdsConclusion $conclusion): ZdsConclusion {
		return new ZdsConclusion(
			sent: $conclusion->sent,
			attempt: $conclusion->attempt,
			transport: $conclusion->transport,
			fout: $conclusion->fout,
			caseId: ($conclusion->caseId ?? (string)($row['caseIdentification'] ?? ''))
		);

	}//end withKnownCase()

	/**
	 * Write a message's conclusion to its row, without letting a failed write undo the send.
	 *
	 * Once the case system answered, that answer is the outcome: a row that
	 * cannot be saved must not turn a created zaak into a refusal, which the
	 * caller would retry into a second zaak. The failure is logged and the
	 * unsaved row is returned.
	 *
	 * @param array         $row        The row.
	 * @param ZdsConclusion $conclusion How the attempt ended.
	 *
	 * @return array The saved row, or the row as it was when the save failed.
	 */
	private function concludeRow(array $row, ZdsConclusion $conclusion): array {
		try {
			return $this->log->conclude(
				row: $row,
				sent: $conclusion->sent,
				attempt: $conclusion->attempt,
				transport: $conclusion->transport,
				fout: $conclusion->fout,
				caseIdentification: $conclusion->caseId
			);
		} catch (Throwable $exception) {
			$this->logger->error(
				'StUF-ZDS message {id} was concluded (sent: {sent}) but its row could not be saved: {error}',
				['id' => (string)($row['id'] ?? ''), 'sent' => json_encode($conclusion->sent), 'error' => $exception->getMessage()]
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
	 * @param array         $row        The row.
	 * @param ZdsConclusion $conclusion How the attempt ended, and whether a retry is scheduled.
	 *
	 * @return array The result (ZdsResult).
	 */
	private function result(array $row, ZdsConclusion $conclusion): array {
		$caseId = $conclusion->caseId;
		if ($caseId === '') {
			$caseId = null;
		}

		return [
			'success' => $conclusion->sent,
			'retrying' => $conclusion->retrying,
			'referentienummer' => (string)($row['referentienummer'] ?? ''),
			'messageId' => (string)($row['id'] ?? ''),
			'caseIdentification' => $caseId,
			'fout' => $conclusion->fout,
		];
	}//end result()

	/**
	 * Open the audit row of a message.
	 *
	 * @param ZdsRequest $request      The source, operation, built message and context.
	 * @param bool       $keepEnvelope Keep the envelope for a retry (kennisgevingen only).
	 *
	 * @return array The row.
	 */
	private function openRow(ZdsRequest $request, bool $keepEnvelope): array {
		$envelope = null;
		if ($keepEnvelope === true) {
			$envelope = $request->message['xml'];
		}

		return $this->log->open(
			fields: [
				'berichttype' => self::OPERATIONS[$request->operation]['berichttype'],
				'operation' => $request->operation,
				'entiteittype' => 'ZAK',
				'referentienummer' => $request->message['referentienummer'],
				'sourceId' => $request->source['id'],
				'sourceApp' => (string)($request->context['sourceApp'] ?? ''),
				'correlationId' => (string)($request->context['correlationId'] ?? ''),
				'caseIdentification' => (string)($request->context['caseIdentification'] ?? ''),
				'envelope' => $envelope,
			]
		);
	}//end openRow()

	/**
	 * Send an attempt's envelope to the ZDS service its operation belongs to.
	 *
	 * The WS-Security UsernameToken is added to the envelope here, never before:
	 * what is persisted on the row for a retry holds no credentials.
	 *
	 * @param ZdsAttempt $attempt The attempt: its source, operation and envelope.
	 *
	 * @return array{httpStatus:int,responseXml:string,durationMs:int,fout:array<string,string>|null}
	 */
	private function transmit(ZdsAttempt $attempt): array {
		$spec          = (self::OPERATIONS[$attempt->operation] ?? self::OPERATIONS['vrijBericht']);
		$configuration = (array)($attempt->source['configuration'] ?? []);

		// The persisted envelope carries no credentials; the token is added here, for the first
		// attempt and for every retry alike, and lives only in this request.
		try {
			$envelope = $this->signedEnvelope(envelope: $attempt->envelope, configuration: $configuration);
		} catch (StufZknProviderException $exception) {
			return $this->credentialsRefusal(exception: $exception);
		}

		return $this->transport->transmit(
			sourceConfiguration: $configuration,
			soapAction: $this->soapAction(attempt: $attempt, code: $spec['code']),
			envelopeXml: $envelope,
			url: (string)($configuration[$spec['urlKey']] ?? '')
		);
	}//end transmit()

	/**
	 * The SOAPAction of an attempt: `<zkn namespace>/<functie>_<berichtcode>`.
	 *
	 * The functie is read from the envelope itself (a vrij bericht is named by
	 * its template), else the operation.
	 *
	 * @param ZdsAttempt $attempt The attempt.
	 * @param string     $code    The operation's berichtcode.
	 *
	 * @return string The quoted SOAPAction.
	 */
	private function soapAction(ZdsAttempt $attempt, string $code): string {
		$functie = $attempt->operation;
		$matches = [];
		if (preg_match('#<stuf:functie>([^<]+)</stuf:functie>#', $attempt->envelope, $matches) === 1) {
			$functie = html_entity_decode($matches[1], (ENT_XML1 | ENT_QUOTES), 'UTF-8');
		}

		return '"' . StufZknNamespaces::ZKN . '/' . $functie . '_' . $code . '"';
	}//end soapAction()

	/**
	 * The envelope with the source's WS-Security UsernameToken added.
	 *
	 * @param string $envelope      The envelope, without credentials.
	 * @param array  $configuration The source configuration.
	 *
	 * @return string The envelope to send.
	 *
	 * @throws StufZknProviderException When the password is missing or cannot be decrypted.
	 */
	private function signedEnvelope(string $envelope, array $configuration): string {
		return $this->builder->withUsernameToken(
			envelopeXml: $envelope,
			wsse: $this->credentials->usernameToken(sourceConfiguration: $configuration)
		);
	}//end signedEnvelope()

	/**
	 * The transport result of an attempt refused for unusable credentials: nothing was sent.
	 *
	 * @param StufZknProviderException $exception Why the credentials are unusable.
	 *
	 * @return array{httpStatus:int,responseXml:string,durationMs:int,fout:array<string,string>}
	 */
	private function credentialsRefusal(StufZknProviderException $exception): array {
		return [
			'httpStatus' => 0,
			'responseXml' => '',
			'durationMs' => 0,
			'fout' => ['code' => 'CREDENTIALS', 'omschrijving' => $exception->getMessage(), 'details' => '', 'kind' => 'permanent'],
		];
	}//end credentialsRefusal()

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
			$this->retries->scheduleRetry(messageId: $messageId, runAfter: (time() + $delay));
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

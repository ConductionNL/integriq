<?php

/**
 * Integriq StUF-ZDS Case Client.
 *
 * The ZDS client for cases: creeerZaak, actualiseerZaak, geefZaakdetails,
 * genereerZaakIdentificatie and vrijBericht against the case system a
 * StUF-ZKN source names. Each request is checked against the source's circuit
 * breaker, built, audited as a `stuf_message` row, sent, and its answer
 * classified: a bevestiging closes the row `sent`; a transient failure (no
 * answer, HTTP 5xx/408/429, or a busy StUF fout) is retried after 5 s, 30 s,
 * 2 min and 10 min with the same envelope and referentienummer; anything else
 * closes the row `failed`. A kennisgeving's conclusion is announced as a
 * {@see \OCA\Integriq\Event\StufZdsCaseConcludedEvent}, so the asking app
 * learns how a retried message ended.
 *
 * Ported from dossiq's StufAdapterService and StufOutboundTransport (decision
 * 193): integriq is the standards adapter, dossiq keeps which case type maps
 * to which zaaktype.
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
 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-case-operations
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\StufZkn;

use OCA\Integriq\BackgroundJob\StufZdsRetryJob;
use OCA\Integriq\Event\StufZdsCaseConcludedEvent;
use OCA\Integriq\Exception\StufZknProviderException;
use OCA\Integriq\Exception\StufZknTranslationException;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends StUF-ZDS case messages and classifies the answers.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) -- the ZDS client composes builder, transport,
 * reader, breaker, credentials, audit log, job list and event dispatcher: the port of two dossiq
 * classes whose split by "what to send" and "what the answer means" no longer pays once both sit
 * behind one source.
 *
 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-case-operations
 */
class StufZdsCaseClient {

	/**
	 * Seconds to wait before each retry of a transient failure.
	 *
	 * @var int[]
	 */
	public const RETRY_BACKOFF_SECONDS = [5, 30, 120, 600];

	/**
	 * Per operation: the stuf_message berichttype, the ZDS service URL key and whether it is a kennisgeving.
	 *
	 * @var array<string,array{berichttype:string,code:string,urlKey:string,kennisgeving:bool}>
	 */
	private const OPERATIONS = [
		'creeerZaak' => ['berichttype' => 'zakLk01', 'code' => 'Lk01', 'urlKey' => 'ontvangAsynchroonUrl', 'kennisgeving' => true],
		'actualiseerZaak' => ['berichttype' => 'zakLk02', 'code' => 'Lk02', 'urlKey' => 'ontvangAsynchroonUrl', 'kennisgeving' => true],
		'geefZaakdetails' => ['berichttype' => 'zakLv01', 'code' => 'Lv01', 'urlKey' => 'beantwoordVraagUrl', 'kennisgeving' => false],
		'genereerZaakIdentificatie' => ['berichttype' => 'genereerZaakIdentificatie_Di02', 'code' => 'Di02', 'urlKey' => 'vrijeBerichtenUrl', 'kennisgeving' => false],
		'vrijBericht' => ['berichttype' => 'vrijBericht_Du01', 'code' => 'Du01', 'urlKey' => 'vrijeBerichtenUrl', 'kennisgeving' => true],
	];

	/**
	 * Constructor.
	 *
	 * @param StufZdsMessageBuilder     $builder     Builds the envelopes.
	 * @param StufZknClient             $transport   Sends them (token, mTLS or WS-Security).
	 * @param StufZdsResponseReader     $reader      Reads the answers.
	 * @param StufZdsCircuitBreaker     $breaker     The per-source circuit breaker.
	 * @param StufZdsCredentialResolver $credentials Decrypts the UsernameToken.
	 * @param StufZdsMessageLog         $log         Audit rows and source reads.
	 * @param IJobList                  $jobList     Schedules retries.
	 * @param IEventDispatcher          $dispatcher  Announces conclusions.
	 * @param LoggerInterface           $logger      The logger.
	 */
	public function __construct(
		private readonly StufZdsMessageBuilder $builder,
		private readonly StufZknClient $transport,
		private readonly StufZdsResponseReader $reader,
		private readonly StufZdsCircuitBreaker $breaker,
		private readonly StufZdsCredentialResolver $credentials,
		private readonly StufZdsMessageLog $log,
		private readonly IJobList $jobList,
		private readonly IEventDispatcher $dispatcher,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Create a zaak (zakLk01). With `caseIdentificationStrategy: vooraf` on the source, an identificatie is asked first.
	 *
	 * @param string $sourceId             The StUF-ZKN source.
	 * @param array  $case                 omschrijving, startdatum, betrokkenen[], documenten[].
	 * @param string $zaaktypeOmschrijving The zaaktype the calling app maps the case type to.
	 * @param array  $context              correlationId, sourceApp, includeDocuments, payloadLimitBytes.
	 *
	 * @return array{success:bool,retrying:bool,referentienummer:string,messageId:string,caseIdentification:string|null,fout:array<string,string>|null}
	 *
	 * @throws StufZknProviderException    When the source cannot send.
	 * @throws StufZknTranslationException When the case cannot be expressed.
	 *
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-case-operations
	 */
	public function creeerZaak(string $sourceId, array $case, string $zaaktypeOmschrijving, array $context=[]): array {
		$source = $this->restSource(sourceId: $sourceId);
		if ($this->breaker->allows(sourceId: $source['id']) === false) {
			return $this->circuitOpen();
		}

		$caseId = null;
		if (($source['configuration']['caseIdentificationStrategy'] ?? '') === 'vooraf') {
			$allocated = $this->genereerZaakIdentificatie(sourceId: $sourceId, context: $context);
			if ($allocated['success'] === false) {
				return $allocated;
			}

			$caseId = $allocated['caseIdentification'];
		}

		$message = $this->builder->caseCreation(
			case: $case,
			zaaktypeOmschrijving: $zaaktypeOmschrijving,
			routing: $this->routing(source: $source),
			caseIdentification: $caseId,
			opts: $context
		);

		$result = $this->sendKennisgeving(source: $source, operation: 'creeerZaak', message: $message, context: $context);
		if ($result['caseIdentification'] === null && $result['success'] === true) {
			$result['caseIdentification'] = $caseId;
		}

		return $result;
	}//end creeerZaak()

	/**
	 * Update a zaak (zakLk02) with the fields that changed.
	 *
	 * @param string $sourceId           The StUF-ZKN source.
	 * @param string $caseIdentification The zaak identificatie in the case system.
	 * @param array  $changes            omschrijving, einddatum, resultaattoelichting.
	 * @param array  $context            correlationId, sourceApp.
	 *
	 * @return array{success:bool,retrying:bool,referentienummer:string,messageId:string,caseIdentification:string|null,fout:array<string,string>|null}
	 *
	 * @throws StufZknProviderException    When the source cannot send.
	 * @throws StufZknTranslationException When no zaak identificatie is given.
	 *
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-case-operations
	 */
	public function actualiseerZaak(string $sourceId, string $caseIdentification, array $changes, array $context=[]): array {
		if (trim($caseIdentification) === '') {
			throw new StufZknTranslationException(message: 'actualiseerZaak needs the zaak identificatie the case system knows the case by.');
		}

		$source = $this->restSource(sourceId: $sourceId);
		if ($this->breaker->allows(sourceId: $source['id']) === false) {
			return $this->circuitOpen();
		}

		$message = $this->builder->caseUpdate(caseIdentification: $caseIdentification, changes: $changes, routing: $this->routing(source: $source));
		$context['caseIdentification'] = $caseIdentification;

		return $this->sendKennisgeving(source: $source, operation: 'actualiseerZaak', message: $message, context: $context);
	}//end actualiseerZaak()

	/**
	 * Send a vrij bericht from a template the source registers under `configuration.freeMessageTemplates`.
	 *
	 * @param string $sourceId The StUF-ZKN source.
	 * @param string $name     The template name.
	 * @param array  $payload  The values.
	 * @param array  $context  correlationId, sourceApp, caseIdentification.
	 *
	 * @return array{success:bool,retrying:bool,referentienummer:string,messageId:string,caseIdentification:string|null,fout:array<string,string>|null}
	 *
	 * @throws StufZknProviderException    When the source cannot send.
	 * @throws StufZknTranslationException When the template is not registered or a required field is missing.
	 *
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-case-operations
	 */
	public function vrijBericht(string $sourceId, string $name, array $payload, array $context=[]): array {
		$source   = $this->restSource(sourceId: $sourceId);
		$template = null;
		foreach ((array)($source['configuration']['freeMessageTemplates'] ?? []) as $candidate) {
			if (is_array($candidate) === true && ($candidate['name'] ?? '') === $name) {
				$template = $candidate;
				break;
			}
		}

		if ($template === null) {
			throw new StufZknTranslationException(message: sprintf('vrijBericht "%s" is not registered on source "%s".', $name, $source['id']));
		}

		if ($this->breaker->allows(sourceId: $source['id']) === false) {
			return $this->circuitOpen();
		}

		$message = $this->builder->freeMessage(template: $template, payload: $payload, routing: $this->routing(source: $source));
		$context['caseIdentification'] = (string)($payload['caseIdentification'] ?? ($context['caseIdentification'] ?? ''));

		return $this->sendKennisgeving(source: $source, operation: 'vrijBericht', message: $message, context: $context);
	}//end vrijBericht()

	/**
	 * Ask the case system for the details of a zaak (zakLv01, answered synchronously).
	 *
	 * @param string   $sourceId           The StUF-ZKN source.
	 * @param string   $caseIdentification The zaak identificatie.
	 * @param string[] $wantedElements     The zkn elements to ask for.
	 * @param array    $context            correlationId, sourceApp.
	 *
	 * @return array{success:bool,retrying:bool,referentienummer:string,messageId:string,caseIdentification:string|null,fout:array<string,string>|null,case:array<string,mixed>|null}
	 *
	 * @throws StufZknProviderException When the source cannot send.
	 *
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-case-operations
	 */
	public function geefZaakdetails(string $sourceId, string $caseIdentification, array $wantedElements=[], array $context=[]): array {
		$source = $this->restSource(sourceId: $sourceId);
		if ($this->breaker->allows(sourceId: $source['id']) === false) {
			return ($this->circuitOpen() + ['case' => null]);
		}

		$message = $this->builder->caseDetailsQuery(caseIdentification: $caseIdentification, routing: $this->routing(source: $source), wantedElements: $wantedElements);
		$context['caseIdentification'] = $caseIdentification;
		$result = $this->ask(source: $source, operation: 'geefZaakdetails', message: $message, context: $context);

		$result['case'] = null;
		if ($result['success'] === true) {
			$result['case'] = $this->reader->caseDetails(xml: $result['answer']);
		}

		unset($result['answer']);
		return $result;
	}//end geefZaakdetails()

	/**
	 * Ask the case system for a new zaak identificatie (genereerZaakIdentificatie_Di02 → Du02).
	 *
	 * @param string $sourceId The StUF-ZKN source.
	 * @param array  $context  correlationId, sourceApp.
	 *
	 * @return array{success:bool,retrying:bool,referentienummer:string,messageId:string,caseIdentification:string|null,fout:array<string,string>|null}
	 *
	 * @throws StufZknProviderException When the source cannot send.
	 *
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-case-operations
	 */
	public function genereerZaakIdentificatie(string $sourceId, array $context=[]): array {
		$source = $this->restSource(sourceId: $sourceId);
		if ($this->breaker->allows(sourceId: $source['id']) === false) {
			return $this->circuitOpen();
		}

		$message = $this->builder->caseIdentificationRequest(routing: $this->routing(source: $source));
		$result  = $this->ask(source: $source, operation: 'genereerZaakIdentificatie', message: $message, context: $context);
		unset($result['answer']);

		if ($result['success'] === true && $result['caseIdentification'] === null) {
			$result['success'] = false;
			$result['fout']    = ['code' => 'NO_IDENTIFICATION', 'omschrijving' => 'The Du02 answer names no zaak identificatie', 'details' => '', 'kind' => 'permanent'];
		}

		return $result;
	}//end genereerZaakIdentificatie()

	/**
	 * Re-send a message whose last attempt failed transiently (called by the retry job).
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
			$source = $this->restSource(sourceId: (string)($row['sourceId'] ?? ''));
		} catch (StufZknProviderException $exception) {
			$fout = ['code' => 'SOURCE_GONE', 'omschrijving' => $exception->getMessage(), 'details' => '', 'kind' => 'permanent'];
			$this->conclude(row: $row, sent: false, attempt: $attempt, transport: [], fout: $fout, caseId: null);
			return;
		}

		if ($this->breaker->allows(sourceId: $source['id']) === false) {
			// Not an attempt: wait out the cooldown and try again.
			$this->schedule(messageId: $messageId, delay: StufZdsCircuitBreaker::COOLDOWN_SECONDS);
			return;
		}

		$operation = (string)($row['operation'] ?? '');
		$response  = $this->transmit(source: $source, operation: $operation, envelope: (string)$row['envelope']);
		$this->classify(source: $source, row: $row, response: $response, attempt: $attempt);
	}//end retry()

	/**
	 * Send a kennisgeving and classify the answer, retrying transient failures.
	 *
	 * @param array                                    $source    The source.
	 * @param string                                   $operation The operation.
	 * @param array{referentienummer:string,xml:string} $message   The built message.
	 * @param array                                    $context   correlationId, sourceApp, caseIdentification.
	 *
	 * @return array{success:bool,retrying:bool,referentienummer:string,messageId:string,caseIdentification:string|null,fout:array<string,string>|null}
	 */
	private function sendKennisgeving(array $source, string $operation, array $message, array $context): array {
		$row      = $this->openRow(source: $source, operation: $operation, message: $message, context: $context);
		$response = $this->transmit(source: $source, operation: $operation, envelope: $message['xml']);

		return $this->classify(source: $source, row: $row, response: $response, attempt: 1);
	}//end sendKennisgeving()

	/**
	 * Send a question and conclude its row at once: a question is not retried in the background.
	 *
	 * @param array                                    $source    The source.
	 * @param string                                   $operation The operation.
	 * @param array{referentienummer:string,xml:string} $message   The built message.
	 * @param array                                    $context   correlationId, sourceApp, caseIdentification.
	 *
	 * @return array{success:bool,retrying:bool,referentienummer:string,messageId:string,caseIdentification:string|null,fout:array<string,string>|null,answer:string}
	 */
	private function ask(array $source, string $operation, array $message, array $context): array {
		$row      = $this->openRow(source: $source, operation: $operation, message: $message, context: $context);
		$response = $this->transmit(source: $source, operation: $operation, envelope: $message['xml']);
		$status   = (int)$response['httpStatus'];

		$fout   = null;
		$caseId = null;
		if ($status >= 200 && $status < 300) {
			$caseId = $this->reader->confirmation(xml: $response['responseXml'])['caseIdentification'];
			$this->breaker->reset(sourceId: $source['id']);
		} else {
			$fout = $this->failureOf(response: $response);
			$this->breaker->recordFailure(sourceId: $source['id']);
		}

		if ($operation === 'geefZaakdetails') {
			$caseId = (string)($context['caseIdentification'] ?? '');
		}

		$this->log->conclude(row: $row, sent: ($fout === null), attempt: 1, transport: $response, fout: $fout, caseIdentification: $caseId);
		if ($caseId === '') {
			$caseId = null;
		}

		return [
			'success' => ($fout === null),
			'retrying' => false,
			'referentienummer' => (string)($row['referentienummer'] ?? ''),
			'messageId' => (string)($row['id'] ?? ''),
			'caseIdentification' => $caseId,
			'fout' => $fout,
			'answer' => $response['responseXml'],
		];
	}//end ask()

	/**
	 * Classify the answer to a kennisgeving attempt: sent, retry later, or failed.
	 *
	 * @param array $source   The source.
	 * @param array $row      The stuf_message row.
	 * @param array $response The transport result.
	 * @param int   $attempt  The attempt (1-indexed).
	 *
	 * @return array{success:bool,retrying:bool,referentienummer:string,messageId:string,caseIdentification:string|null,fout:array<string,string>|null}
	 */
	private function classify(array $source, array $row, array $response, int $attempt): array {
		$status = (int)$response['httpStatus'];
		if ($status >= 200 && $status < 300) {
			$caseId = $this->reader->confirmation(xml: $response['responseXml'])['caseIdentification'];
			$this->breaker->reset(sourceId: $source['id']);
			return $this->conclude(row: $row, sent: true, attempt: $attempt, transport: $response, fout: null, caseId: $caseId);
		}

		$fout = $this->failureOf(response: $response);
		// A StUF fout in the body decides: SOAP 1.1 answers every fault with
		// HTTP 500, so the status alone would retry a permanent refusal four
		// times. Without one, the status (or the transport) decides.
		$transient = ($status === 0 || $status === 408 || $status === 429 || ($status >= 500 && $status < 600));
		if (($fout['code'] !== '' && (string)$response['responseXml'] !== '') || $status === 0) {
			$transient = ($fout['kind'] === 'transient');
		}

		$this->breaker->recordFailure(sourceId: $source['id']);
		if ($transient === true && $attempt <= count(self::RETRY_BACKOFF_SECONDS)) {
			$delay = self::RETRY_BACKOFF_SECONDS[($attempt - 1)];
			$row   = $this->log->retrying(row: $row, attempt: $attempt, transport: $response, fout: $fout, retryAfter: $delay);
			$this->schedule(messageId: (string)$row['id'], delay: $delay);

			return [
				'success' => false,
				'retrying' => true,
				'referentienummer' => (string)($row['referentienummer'] ?? ''),
				'messageId' => (string)$row['id'],
				'caseIdentification' => null,
				'fout' => $fout,
			];
		}

		return $this->conclude(row: $row, sent: false, attempt: $attempt, transport: $response, fout: $fout, caseId: null);
	}//end classify()

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
	 * @return array{success:bool,retrying:bool,referentienummer:string,messageId:string,caseIdentification:string|null,fout:array<string,string>|null}
	 */
	private function conclude(array $row, bool $sent, int $attempt, array $transport, ?array $fout, ?string $caseId): array {
		$known = ($caseId ?? (string)($row['caseIdentification'] ?? ''));
		$row   = $this->log->conclude(row: $row, sent: $sent, attempt: $attempt, transport: $transport, fout: $fout, caseIdentification: $known);
		if ($known === '') {
			$known = null;
		}

		$result = [
			'success' => $sent,
			'retrying' => false,
			'referentienummer' => (string)($row['referentienummer'] ?? ''),
			'messageId' => (string)($row['id'] ?? ''),
			'caseIdentification' => $known,
			'fout' => $fout,
		];

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
	 * Open the audit row of a message.
	 *
	 * @param array  $source    The source.
	 * @param string $operation The operation.
	 * @param array  $message   The built message.
	 * @param array  $context   correlationId, sourceApp, caseIdentification.
	 *
	 * @return array The row.
	 */
	private function openRow(array $source, string $operation, array $message, array $context): array {
		$spec = self::OPERATIONS[$operation];
		$keep = null;
		if ($spec['kennisgeving'] === true) {
			// Kept only while a retry may need it; erased at conclusion.
			$keep = $message['xml'];
		}

		return $this->log->open(
			fields: [
				'berichttype' => $spec['berichttype'],
				'operation' => $operation,
				'entiteittype' => 'ZAK',
				'referentienummer' => $message['referentienummer'],
				'sourceId' => $source['id'],
				'sourceApp' => (string)($context['sourceApp'] ?? ''),
				'correlationId' => (string)($context['correlationId'] ?? ''),
				'caseIdentification' => (string)($context['caseIdentification'] ?? ''),
				'envelope' => $keep,
			]
		);
	}//end openRow()

	/**
	 * Send an envelope to the ZDS service the operation belongs to.
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
			// The envelope's own functie: a vrij bericht is named by its template.
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
			return ['code' => 'HTTP_' . (int)$response['httpStatus'], 'omschrijving' => 'The case system answered without a body', 'details' => '', 'kind' => 'permanent'];
		}

		return $this->reader->fault(xml: (string)$response['responseXml']);
	}//end failureOf()

	/**
	 * Read the source and refuse one that would send nothing.
	 *
	 * @param string $sourceId The source id.
	 *
	 * @return array The source, `id` set.
	 *
	 * @throws StufZknProviderException When the source is missing or in log mode.
	 */
	private function restSource(string $sourceId): array {
		$source = $this->log->source(sourceId: $sourceId);
		if (($source['configuration']['provider'] ?? 'log') !== 'rest') {
			throw new StufZknProviderException(message: 'The StUF-ZKN source is not in rest mode (configuration.provider); the log provider sends nothing, so no case message was sent.');
		}

		return $source;
	}//end restSource()

	/**
	 * The routing (zender, ontvanger, UsernameToken) of a source.
	 *
	 * @param array $source The source.
	 *
	 * @return array The routing.
	 *
	 * @throws StufZknProviderException When the source names no organisaties or its password cannot be read.
	 */
	private function routing(array $source): array {
		$routing = $this->builder->routing(source: $source, wsse: $this->credentials->usernameToken(sourceConfiguration: (array)($source['configuration'] ?? [])));
		if ($routing['zender']['organisatie'] === '' || $routing['ontvanger']['organisatie'] === '') {
			throw new StufZknProviderException(message: 'The StUF-ZKN source names no organisatie (configuration.organisatie) or no ontvangerOrganisatie; a StUF message needs both.');
		}

		return $routing;
	}//end routing()

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
			$this->logger->warning('StUF-ZDS retry could not be scheduled for {id}: {error}', ['id' => $messageId, 'error' => $exception->getMessage()]);
		}
	}//end schedule()

	/**
	 * The result of a request refused because the source's circuit is open.
	 *
	 * @return array{success:bool,retrying:bool,referentienummer:string,messageId:string,caseIdentification:string|null,fout:array<string,string>}
	 */
	private function circuitOpen(): array {
		return [
			'success' => false,
			'retrying' => false,
			'referentienummer' => '',
			'messageId' => '',
			'caseIdentification' => null,
			'fout' => ['code' => 'CIRCUIT_OPEN', 'omschrijving' => 'The case system failed repeatedly; requests pause for five minutes', 'details' => '', 'kind' => 'transient'],
		];
	}//end circuitOpen()
}//end class

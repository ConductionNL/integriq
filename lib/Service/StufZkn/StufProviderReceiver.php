<?php

/**
 * Integriq StUF Provider Receiver.
 *
 * Answers the StUF-ZKN and StUF-BG provider requests a case system sends
 * (zakLv01, zakLk01, npsLv01, edcLk01) on behalf of the app that owns the
 * cases (decision 199). Integriq owns the wire: WS-Security, the envelope,
 * the stuurgegevens and the faults. The owning app owns the answer, which it
 * gives in the synchronous slot of a typed event. Nobody answering is a
 * StUF055 fault, never an empty La01: an empty answer would tell the case
 * system "no such zaak" when nobody looked.
 *
 * Ported from dossiq's StufSoapRequestDispatcher, StufEnvelopeInspector and
 * StufZknMessageResponder, whose handlers were stubs that always answered empty.
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
 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-each-query-reaches-the-owning-app-as-a-typed-event
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\StufZkn;

use OCA\Integriq\Event\StufCaseMutationEvent;
use OCA\Integriq\Event\StufCaseQueryEvent;
use OCA\Integriq\Event\StufDocumentMutationEvent;
use OCA\Integriq\Event\StufPersonQueryEvent;
use OCA\Integriq\Event\StufProviderQueryEvent;
use OCA\Integriq\Exception\StufZknProviderException;
use OCA\Integriq\Service\Stuf\StufXmlParser;
use OCA\Integriq\Service\StUFXMLBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use SimpleXMLElement;
use Throwable;

/**
 * Receives provider requests and answers them through the owning app.
 *
 * @psalm-type ProviderAnswer = array{statusCode:int,body:string}
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-each-query-reaches-the-owning-app-as-a-typed-event
 */
class StufProviderReceiver {

	/**
	 * The cases route.
	 *
	 * @var string
	 */
	public const SERVICE_CASES = 'cases';

	/**
	 * The persons route.
	 *
	 * @var string
	 */
	public const SERVICE_PERSONS = 'persons';

	/**
	 * The berichttypes each route takes.
	 *
	 * @var array<string, string[]>
	 */
	public const MESSAGES = [
		self::SERVICE_CASES => ['zakLv01', 'zakLk01', 'edcLk01'],
		self::SERVICE_PERSONS => ['npsLv01'],
	];

	/**
	 * Inbound body ceiling (2 MiB): mitigates XML bombs.
	 *
	 * @var integer
	 */
	private const MAX_BODY_BYTES = 2097152;

	/**
	 * The request came in on integriq's own route.
	 *
	 * @var string
	 */
	private const ORIGIN_ROUTE = 'route';

	/**
	 * A sibling app handed the request over from its own URL.
	 *
	 * @var string
	 */
	private const ORIGIN_FORWARDED = 'forwarded';

	/**
	 * The longest refusal text sent to the external case system.
	 *
	 * @var int
	 */
	private const OUTWARD_REASON_LENGTH = 200;

	/**
	 * The HTTP status each refusal kind goes back with.
	 *
	 * None of them is rewritten on the way out: the routes sit on a plain
	 * Controller, and Nextcloud's OCSMiddleware only turns a 401 or 403 into a
	 * 200 for an OCSController (StufProviderReceiverTest reads each status after it).
	 *
	 * @var array<string, int>
	 */
	private const REFUSAL_STATUS = [
		StufProviderQueryEvent::REFUSAL_REFUSED => 400,
		StufProviderQueryEvent::REFUSAL_FORBIDDEN => 403,
		StufProviderQueryEvent::REFUSAL_NOT_FOUND => 404,
		StufProviderQueryEvent::REFUSAL_CONFLICT => 409,
	];

	/**
	 * Constructor.
	 *
	 * @param StufZdsMessageLog         $log         Source reads.
	 * @param StufZdsCredentialResolver $credentials Decrypts the source's UsernameToken.
	 * @param StUFXMLBuilder            $xml         Builds the La01, Bv01 and fault answers.
	 * @param IEventDispatcher          $dispatcher  Carries the question to the owning app.
	 * @param LoggerInterface           $logger      The logger.
	 * @param StufXmlParser             $xmlParser   The hardened XML parser.
	 * @param StufProviderRequestReader $reader      Reads the question out of the envelope.
	 */
	public function __construct(
		private readonly StufZdsMessageLog $log,
		private readonly StufZdsCredentialResolver $credentials,
		private readonly StUFXMLBuilder $xml,
		private readonly IEventDispatcher $dispatcher,
		private readonly LoggerInterface $logger,
		private readonly StufXmlParser $xmlParser = new StufXmlParser(),
		private readonly StufProviderRequestReader $reader = new StufProviderRequestReader(),
	) {

	}//end __construct()

	/**
	 * Receive one provider request.
	 *
	 * 200 with a zakLa01, npsLa01 or Bv03 when the owning app answered; 400 with
	 * a Fo02/Fo03 when the request is malformed, on the wrong route, carries a
	 * mutatiesoort this adapter does not carry out, or is refused by the app
	 * (403, 404 or 409 when the app says it is forbidden, not found or already
	 * there); 401 when the source or its credentials do not match; 413 when the
	 * body is too large; 500 with a StUF055 fault when nobody answered. A fault
	 * never travels as HTTP 200.
	 *
	 * @param string $rawXml   The raw request body.
	 * @param string $sourceId The integriq StUF-ZKN source the request is for.
	 * @param string $service  SERVICE_CASES or SERVICE_PERSONS.
	 *
	 * @return array The answer (ProviderAnswer).
	 *
	 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-provider-requests-are-authenticated-against-the-source
	 */
	public function receive(string $rawXml, string $sourceId, string $service): array {
		return $this->process(rawXml: $rawXml, sourceId: $sourceId, service: $service, origin: self::ORIGIN_ROUTE);
	}//end receive()

	/**
	 * Receive a provider request a sibling app handed over from its own URL.
	 *
	 * A query is answered as on integriq's route. A write is refused: decision
	 * 206 lets a write in on integriq's own route only.
	 *
	 * @param string $rawXml   The raw request body.
	 * @param string $sourceId The integriq StUF-ZKN source the request is for.
	 * @param string $service  SERVICE_CASES or SERVICE_PERSONS.
	 *
	 * @return array The answer (ProviderAnswer).
	 *
	 * @spec openspec/changes/stuf-provider-writes/specs/stuf-provider-receiver/spec.md#requirement-writes-arrive-on-integriqs-own-route-only
	 */
	public function receiveForwarded(string $rawXml, string $sourceId, string $service): array {
		return $this->process(rawXml: $rawXml, sourceId: $sourceId, service: $service, origin: self::ORIGIN_FORWARDED);
	}//end receiveForwarded()

	/**
	 * Authenticate, read, ask the owning app and render the answer.
	 *
	 * @param string $rawXml   The raw request body.
	 * @param string $sourceId The integriq StUF-ZKN source the request is for.
	 * @param string $service  SERVICE_CASES or SERVICE_PERSONS.
	 * @param string $origin   ORIGIN_ROUTE or ORIGIN_FORWARDED.
	 *
	 * @return array The answer (ProviderAnswer).
	 */
	private function process(string $rawXml, string $sourceId, string $service, string $origin): array {
		if (strlen($rawXml) > self::MAX_BODY_BYTES) {
			return $this->fault(statusCode: 413, berichtcode: 'Fo02', code: 'StUF001', omschrijving: 'Bericht te groot', stuurgegevens: []);
		}

		$root = $this->parse(rawXml: $rawXml);
		if ($root === null) {
			return $this->fault(statusCode: 400, berichtcode: 'Fo02', code: 'StUF001', omschrijving: 'Ongeldig bericht', stuurgegevens: []);
		}

		$message     = $this->reader->messageElement(root: $root);
		$berichttype = '';
		if ($message !== null) {
			$berichttype = $message->getName();
		}

		$faultCode = $this->faultCode(berichttype: $berichttype);
		$reply     = $this->replyStuurgegevens(root: $root, source: []);

		$source = $this->authenticatedSource(root: $root, sourceId: $sourceId);
		if ($source === null) {
			$this->logger->warning('StUF provider request refused for source {id}: not authenticated', ['id' => $sourceId]);
			return $this->fault(
				statusCode: 401,
				berichtcode: $faultCode,
				code: 'StUF063',
				omschrijving: 'Authenticatie mislukt',
				stuurgegevens: $reply
			);
		}

		$reply = $this->replyStuurgegevens(root: $root, source: $source);
		if ($message === null || in_array($berichttype, (self::MESSAGES[$service] ?? []), true) === false) {
			$this->logger->warning('StUF provider request {type} not taken on route {service}', ['type' => $berichttype, 'service' => $service]);
			return $this->fault(
				statusCode: 400,
				berichtcode: $faultCode,
				code: 'StUF001',
				omschrijving: 'Berichttype wordt niet ondersteund',
				stuurgegevens: $reply
			);
		}

		$event = $this->reader->event(message: $message, sourceId: (string)$source['id'], referentienummer: (string)($reply['crossRefnummer'] ?? ''));
		$unfit = $this->unfitWrite(event: $event, origin: $origin);
		if ($unfit !== null) {
			$this->logger->warning('StUF provider write {type} refused before it was asked: {reason}', ['type' => $berichttype, 'reason' => $unfit['reason']]);
			return $this->fault(
				statusCode: $unfit['statusCode'],
				berichtcode: $faultCode,
				code: 'StUF058',
				omschrijving: $unfit['reason'],
				stuurgegevens: $reply
			);
		}

		try {
			$this->dispatcher->dispatchTyped($event);
		} catch (Throwable $exception) {
			$this->logger->error('StUF provider listener failed for {type}: {error}', ['type' => $berichttype, 'error' => $exception->getMessage()]);
		}

		return $this->answer(event: $event, faultCode: $faultCode, reply: $reply);
	}//end process()

	/**
	 * Render the owning app's answer, its refusal, or the silence.
	 *
	 * @param StufProviderQueryEvent $event     The answered (or not) event.
	 * @param string                 $faultCode Fo03 for a kennisgeving, Fo02 for a vraag.
	 * @param array                  $reply     The reply stuurgegevens.
	 *
	 * @return array The answer (ProviderAnswer).
	 *
	 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-an-unanswered-query-is-a-fault-never-an-empty-answer
	 * @spec openspec/changes/stuf-provider-writes/specs/stuf-provider-receiver/spec.md#requirement-a-refused-write-is-a-stuf-fault-with-a-refusing-status
	 */
	private function answer(StufProviderQueryEvent $event, string $faultCode, array $reply): array {
		if ($event->isAnswered() === false) {
			$this->logger->warning('StUF provider request {ref} was not answered by any app', ['ref' => $event->getReferentienummer()]);
			return $this->fault(
				statusCode: 500,
				berichtcode: $faultCode,
				code: 'StUF055',
				omschrijving: 'Het bericht is niet beantwoord',
				stuurgegevens: $reply
			);
		}

		$refusal = $event->getRefusal();
		if ($refusal !== null) {
			$status = (self::REFUSAL_STATUS[$event->getRefusalKind()] ?? 400);
			$this->logger->warning(
				'StUF provider request {ref} refused by {app} ({kind}): {reason}',
				[
					'ref' => $event->getReferentienummer(),
					'app' => (string)$event->getAnsweredBy(),
					'kind' => $event->getRefusalKind(),
					'reason' => $refusal,
				]
			);
			return $this->fault(
				statusCode: $status,
				berichtcode: $faultCode,
				code: 'StUF058',
				omschrijving: self::outwardReason(reason: $refusal),
				stuurgegevens: $reply
			);
		}

		$payload = $event->getAnswer();
		if ($event instanceof StufCaseQueryEvent) {
			return ['statusCode' => 200, 'body' => $this->xml->buildZakLa01(cases: (array)($payload['cases'] ?? []), stuurgegevens: $reply)];
		}

		if ($event instanceof StufPersonQueryEvent) {
			return ['statusCode' => 200, 'body' => $this->xml->buildNpsLa01(persons: (array)($payload['persons'] ?? []), stuurgegevens: $reply)];
		}

		// StUF-ZDS 1.2 answers every kennisgeving with a Bv03 (or a Fo03).
		return ['statusCode' => 200, 'body' => $this->xml->buildBv03(caseIdentification: '', stuurgegevens: $reply)];
	}//end answer()

	/**
	 * Why a write message is not put to the owning app at all, or null when it is.
	 *
	 * @param StufProviderQueryEvent $event  The event the message became.
	 * @param string                 $origin ORIGIN_ROUTE or ORIGIN_FORWARDED.
	 *
	 * @return array{statusCode:int,reason:string}|null The refusal, its reason safe to return; null for a query or a write this adapter carries out.
	 *
	 * @spec openspec/changes/stuf-provider-writes/specs/stuf-provider-receiver/spec.md#requirement-only-create-update-and-document-add-are-carried-out
	 * @spec openspec/changes/stuf-provider-writes/specs/stuf-provider-receiver/spec.md#requirement-writes-arrive-on-integriqs-own-route-only
	 */
	private function unfitWrite(StufProviderQueryEvent $event, string $origin): ?array {
		if (($event instanceof StufCaseMutationEvent) === false && ($event instanceof StufDocumentMutationEvent) === false) {
			return null;
		}

		if ($origin !== self::ORIGIN_ROUTE) {
			// Decision 206: a write comes in on integriq's own route, never through a sibling app's URL.
			return ['statusCode' => 403, 'reason' => 'Schrijven kan alleen op het StUF-adres van integriq'];
		}

		$operation = $event->getOperation();
		if (($operation['action'] ?? StufProviderOperationMapper::ACTION_UNSUPPORTED) === StufProviderOperationMapper::ACTION_UNSUPPORTED) {
			return ['statusCode' => 400, 'reason' => 'Mutatiesoort wordt niet ondersteund'];
		}

		if (isset($operation['contentError']) === true) {
			return ['statusCode' => 400, 'reason' => 'De inhoud van het document is geen geldige base64'];
		}

		// A clear cannot reach the owning app yet; answering Bv03 would tell the
		// sender a value was cleared that the case still holds.
		if (($operation['clears'] ?? []) !== []) {
			return ['statusCode' => 400, 'reason' => 'Een veld leegmaken met een W-kennisgeving wordt niet ondersteund'];
		}

		return null;
	}//end unfitWrite()

	/**
	 * The source, when it accepts provider requests and the envelope's UsernameToken matches it.
	 *
	 * Unknown source, a source that did not opt in, a source without a token and
	 * a wrong password all answer null alike, so the route is not an oracle.
	 *
	 * @param SimpleXMLElement $root     The envelope.
	 * @param string           $sourceId The source id from the route.
	 *
	 * @return array|null The source, `id` set; null when refused.
	 *
	 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-provider-requests-are-authenticated-against-the-source
	 */
	private function authenticatedSource(SimpleXMLElement $root, string $sourceId): ?array {
		try {
			$source = $this->log->source(sourceId: $sourceId);
			$token  = $this->credentials->usernameToken(sourceConfiguration: (array)($source['configuration'] ?? []));
		} catch (StufZknProviderException $exception) {
			return null;
		}

		if (($source['configuration']['acceptsProviderRequests'] ?? false) !== true) {
			return null;
		}

		if ($token === null || $token['username'] === '' || $token['password'] === '') {
			return null;
		}

		$tokenPath = '//*[local-name()="Security"]/*[local-name()="UsernameToken"]/*[local-name()="';
		$username  = $this->reader->text(xml: $root, path: $tokenPath . 'Username"]');
		$password  = $this->reader->text(xml: $root, path: $tokenPath . 'Password"]');
		if (hash_equals($token['username'], $username) === false || hash_equals($token['password'], $password) === false) {
			return null;
		}

		return $source;
	}//end authenticatedSource()

	/**
	 * The reply's stuurgegevens: this side is the zender, the request's zender the ontvanger.
	 *
	 * @param SimpleXMLElement $root   The envelope.
	 * @param array            $source The authenticated source, [] before authentication.
	 *
	 * @return array The stuurgegevens for StUFXMLBuilder.
	 */
	private function replyStuurgegevens(SimpleXMLElement $root, array $source): array {
		$zender = '//*[local-name()="stuurgegevens"]/*[local-name()="zender"]/*[local-name()="';
		$reply  = [
			'zenderOrganisatie' => (string)($source['configuration']['organisatie'] ?? ''),
			'ontvangerOrganisatie' => $this->reader->text(xml: $root, path: $zender . 'organisatie"]'),
			'ontvangerApplicatie' => $this->reader->text(xml: $root, path: $zender . 'applicatie"]'),
			'crossRefnummer' => $this->reader->text(xml: $root, path: '//*[local-name()="stuurgegevens"]/*[local-name()="referentienummer"]'),
		];
		$applicatie = (string)($source['configuration']['applicatie'] ?? '');
		if ($applicatie !== '') {
			$reply['zenderApplicatie'] = $applicatie;
		}

		return $reply;
	}//end replyStuurgegevens()

	/**
	 * A fault answer.
	 *
	 * @param int    $statusCode    The HTTP status.
	 * @param string $berichtcode   Fo02 or Fo03.
	 * @param string $code          The StUF fault code.
	 * @param string $omschrijving  The description, safe to return.
	 * @param array  $stuurgegevens The reply stuurgegevens.
	 *
	 * @return array The answer (ProviderAnswer).
	 */
	private function fault(int $statusCode, string $berichtcode, string $code, string $omschrijving, array $stuurgegevens): array {
		$plek = 'client';
		if ($statusCode >= 500) {
			$plek = 'server';
		}

		$body = $this->xml->buildFo02(code: $code, omschrijving: $omschrijving, stuurgegevens: $stuurgegevens, plek: $plek);
		if ($berichtcode === 'Fo03') {
			$body = $this->xml->buildFo03(code: $code, omschrijving: $omschrijving, stuurgegevens: $stuurgegevens);
		}

		return ['statusCode' => $statusCode, 'body' => $body];
	}//end fault()

	/**
	 * Fo03 answers a kennisgeving (Lk01, as StUF-ZDS 1.2 pairs it), Fo02 a vraag (Lv01) and anything unknown.
	 *
	 * @param string $berichttype The message's local name.
	 *
	 * @return string Fo02 or Fo03.
	 */
	private function faultCode(string $berichttype): string {
		if (str_ends_with($berichttype, 'Lk01') === true) {
			return 'Fo03';
		}

		return 'Fo02';
	}//end faultCode()

	/**
	 * Parse the envelope, or null when it is empty, carries a DOCTYPE, or is unreadable.
	 *
	 * @param string $rawXml The raw request body.
	 *
	 * @return SimpleXMLElement|null The root.
	 */
	private function parse(string $rawXml): ?SimpleXMLElement {
		if (trim($rawXml) === '' || stripos($rawXml, '<!DOCTYPE') !== false) {
			return null;
		}

		return $this->xmlParser->parse(xml: $rawXml);
	}//end parse()

	/**
	 * The owning app's refusal as it may leave for the external case system.
	 *
	 * The refuse() method asks for a short reason safe to return, but nothing enforces it:
	 * an app that passes an exception message would send internal errors, with
	 * their line breaks and ids, to the remote party. One line, at most
	 * OUTWARD_REASON_LENGTH characters, goes out; the full text is logged.
	 *
	 * @param string $reason The reason the owning app gave.
	 *
	 * @return string The reason for the fault's omschrijving.
	 */
	private static function outwardReason(string $reason): string {
		$line = trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $reason));
		if ($line === '') {
			return 'Het bericht is geweigerd';
		}

		if (mb_strlen($line) > self::OUTWARD_REASON_LENGTH) {
			$line = rtrim(mb_substr($line, 0, (self::OUTWARD_REASON_LENGTH - 1))) . '…';
		}

		return $line;
	}//end outwardReason()
}//end class

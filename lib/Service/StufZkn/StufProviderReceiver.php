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
	 * Constructor.
	 *
	 * @param StufZdsMessageLog         $log         Source reads.
	 * @param StufZdsCredentialResolver $credentials Decrypts the source's UsernameToken.
	 * @param StUFXMLBuilder            $xml         Builds the La01, Bv01 and fault answers.
	 * @param IEventDispatcher          $dispatcher  Carries the question to the owning app.
	 * @param LoggerInterface           $logger      The logger.
	 * @param StufXmlParser             $xmlParser   The hardened XML parser.
	 */
	public function __construct(
		private readonly StufZdsMessageLog $log,
		private readonly StufZdsCredentialResolver $credentials,
		private readonly StUFXMLBuilder $xml,
		private readonly IEventDispatcher $dispatcher,
		private readonly LoggerInterface $logger,
		private readonly StufXmlParser $xmlParser = new StufXmlParser(),
	) {

	}//end __construct()

	/**
	 * Receive one provider request.
	 *
	 * 200 with a zakLa01, npsLa01 or Bv01 when the owning app answered; 400 with
	 * a Fo01/Fo02 when the request is malformed, on the wrong route, or refused by
	 * the app; 401 when the source or its credentials do not match; 413 when the
	 * body is too large; 500 with a StUF055 fault when nobody answered.
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
		if (strlen($rawXml) > self::MAX_BODY_BYTES) {
			return $this->fault(statusCode: 413, berichtcode: 'Fo02', code: 'StUF001', omschrijving: 'Bericht te groot', stuurgegevens: []);
		}

		$root = $this->parse(rawXml: $rawXml);
		if ($root === null) {
			return $this->fault(statusCode: 400, berichtcode: 'Fo02', code: 'StUF001', omschrijving: 'Ongeldig bericht', stuurgegevens: []);
		}

		$message     = $this->messageElement(root: $root);
		$berichttype = '';
		if ($message !== null) {
			$berichttype = $message->getName();
		}

		$faultCode = $this->faultCode(berichttype: $berichttype);
		$reply     = $this->replyStuurgegevens(root: $root, source: []);

		$source = $this->authenticatedSource(root: $root, sourceId: $sourceId);
		if ($source === null) {
			$this->logger->warning('StUF provider request refused for source {id}: not authenticated', ['id' => $sourceId]);
			return $this->fault(statusCode: 401, berichtcode: $faultCode, code: 'StUF063', omschrijving: 'Authenticatie mislukt', stuurgegevens: $reply);
		}

		$reply = $this->replyStuurgegevens(root: $root, source: $source);
		if ($message === null || in_array($berichttype, (self::MESSAGES[$service] ?? []), true) === false) {
			$this->logger->warning('StUF provider request {type} not taken on route {service}', ['type' => $berichttype, 'service' => $service]);
			return $this->fault(statusCode: 400, berichtcode: $faultCode, code: 'StUF001', omschrijving: 'Berichttype wordt niet ondersteund', stuurgegevens: $reply);
		}

		$event = $this->event(message: $message, sourceId: (string)$source['id'], referentienummer: (string)($reply['crossRefnummer'] ?? ''));
		try {
			$this->dispatcher->dispatchTyped($event);
		} catch (Throwable $exception) {
			$this->logger->error('StUF provider listener failed for {type}: {error}', ['type' => $berichttype, 'error' => $exception->getMessage()]);
		}

		return $this->answer(event: $event, faultCode: $faultCode, reply: $reply);
	}//end receive()

	/**
	 * Render the owning app's answer, its refusal, or the silence.
	 *
	 * @param StufProviderQueryEvent $event     The answered (or not) event.
	 * @param string                 $faultCode Fo01 for a kennisgeving, Fo02 for a vraag.
	 * @param array                  $reply     The reply stuurgegevens.
	 *
	 * @return array The answer (ProviderAnswer).
	 *
	 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-an-unanswered-query-is-a-fault-never-an-empty-answer
	 */
	private function answer(StufProviderQueryEvent $event, string $faultCode, array $reply): array {
		if ($event->isAnswered() === false) {
			$this->logger->warning('StUF provider request {ref} was not answered by any app', ['ref' => $event->getReferentienummer()]);
			return $this->fault(statusCode: 500, berichtcode: $faultCode, code: 'StUF055', omschrijving: 'Het bericht is niet beantwoord', stuurgegevens: $reply);
		}

		$refusal = $event->getRefusal();
		if ($refusal !== null) {
			return $this->fault(statusCode: 400, berichtcode: $faultCode, code: 'StUF058', omschrijving: $refusal, stuurgegevens: $reply);
		}

		$payload = $event->getAnswer();
		if ($event instanceof StufCaseQueryEvent) {
			return ['statusCode' => 200, 'body' => $this->xml->buildZakLa01(cases: (array)($payload['cases'] ?? []), stuurgegevens: $reply)];
		}

		if ($event instanceof StufPersonQueryEvent) {
			return ['statusCode' => 200, 'body' => $this->xml->buildNpsLa01(persons: (array)($payload['persons'] ?? []), stuurgegevens: $reply)];
		}

		return ['statusCode' => 200, 'body' => $this->xml->buildBv01(stuurgegevens: $reply)];
	}//end answer()

	/**
	 * Build the typed event for one message.
	 *
	 * @param SimpleXMLElement $message          The StUF message element.
	 * @param string           $sourceId         The source id.
	 * @param string           $referentienummer The request's referentienummer.
	 *
	 * @return StufProviderQueryEvent The event.
	 */
	private function event(SimpleXMLElement $message, string $sourceId, string $referentienummer): StufProviderQueryEvent {
		$type = $message->getName();
		if ($type === 'zakLv01') {
			return new StufCaseQueryEvent(
				sourceId: $sourceId,
				referentienummer: $referentienummer,
				criteria: $this->leaves(element: $this->first(xml: $message, path: './*[local-name()="gelijk"]'))
			);
		}

		if ($type === 'npsLv01') {
			$bsn = $this->text(xml: $message, path: './*[local-name()="gelijk"]/*[local-name()="inp.bsn" or local-name()="bsn"]');
			return new StufPersonQueryEvent(sourceId: $sourceId, referentienummer: $referentienummer, bsn: $bsn);
		}

		$object       = $this->first(xml: $message, path: './*[local-name()="object"]');
		$fields       = $this->leaves(element: $object);
		$mutatiesoort = $this->text(xml: $message, path: './*[local-name()="parameters"]/*[local-name()="mutatiesoort"]');
		if ($mutatiesoort === '' && $object !== null) {
			$mutatiesoort = $this->attribute(element: $object, name: 'verwerkingssoort');
		}

		if ($type === 'edcLk01') {
			$fields['zaakidentificatie'] = $this->text(
				xml: $message,
				path: './*[local-name()="object"]/*[local-name()="isRelevantVoor"]/*[local-name()="gerelateerde"]/*[local-name()="identificatie"]'
			);
			return new StufDocumentMutationEvent(sourceId: $sourceId, referentienummer: $referentienummer, mutatiesoort: $mutatiesoort, fields: $fields);
		}

		$zaaktype = './*[local-name()="object"]/*[local-name()="isVan"]/*[local-name()="gerelateerde"]/*[local-name()="';
		$fields['zaaktypeCode']         = $this->text(xml: $message, path: $zaaktype . 'code"]');
		$fields['zaaktypeOmschrijving'] = $this->text(xml: $message, path: $zaaktype . 'omschrijving"]');
		return new StufCaseMutationEvent(sourceId: $sourceId, referentienummer: $referentienummer, mutatiesoort: $mutatiesoort, fields: array_filter($fields, static fn(string $value): bool => $value !== ''));
	}//end event()

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
		$username  = $this->text(xml: $root, path: $tokenPath . 'Username"]');
		$password  = $this->text(xml: $root, path: $tokenPath . 'Password"]');
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
			'ontvangerOrganisatie' => $this->text(xml: $root, path: $zender . 'organisatie"]'),
			'ontvangerApplicatie' => $this->text(xml: $root, path: $zender . 'applicatie"]'),
			'crossRefnummer' => $this->text(xml: $root, path: '//*[local-name()="stuurgegevens"]/*[local-name()="referentienummer"]'),
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
	 * @param string $berichtcode   Fo01 or Fo02.
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

		if ($berichtcode === 'Fo01') {
			return ['statusCode' => $statusCode, 'body' => $this->xml->buildFo01(code: $code, omschrijving: $omschrijving, stuurgegevens: $stuurgegevens, plek: $plek)];
		}

		return ['statusCode' => $statusCode, 'body' => $this->xml->buildFo02(code: $code, omschrijving: $omschrijving, stuurgegevens: $stuurgegevens, plek: $plek)];
	}//end fault()

	/**
	 * Fo01 answers a kennisgeving (Lk01), Fo02 a vraag (Lv01) and anything unknown.
	 *
	 * @param string $berichttype The message's local name.
	 *
	 * @return string Fo01 or Fo02.
	 */
	private function faultCode(string $berichttype): string {
		if (str_ends_with($berichttype, 'Lk01') === true) {
			return 'Fo01';
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
	 * The first element inside the SOAP Body.
	 *
	 * @param SimpleXMLElement $root The envelope.
	 *
	 * @return SimpleXMLElement|null The StUF message element.
	 */
	private function messageElement(SimpleXMLElement $root): ?SimpleXMLElement {
		return $this->first(xml: $root, path: '//*[local-name()="Body"]/*[1]');
	}//end messageElement()

	/**
	 * Every leaf child of an element, by local name.
	 *
	 * @param SimpleXMLElement|null $element The parent.
	 *
	 * @return array<string, string> Local name to trimmed text.
	 */
	private function leaves(?SimpleXMLElement $element): array {
		$result = [];
		if ($element === null) {
			return $result;
		}

		foreach (($element->xpath('./*[not(*)]') ?: []) as $child) {
			$value = trim((string)$child);
			if ($value !== '') {
				$result[$child->getName()] = $value;
			}
		}

		return $result;
	}//end leaves()

	/**
	 * An attribute in any namespace, by local name.
	 *
	 * @param SimpleXMLElement $element The element.
	 * @param string           $name    The attribute's local name.
	 *
	 * @return string The value, '' when absent.
	 */
	private function attribute(SimpleXMLElement $element, string $name): string {
		$found = $element->xpath('./@*[local-name()="' . $name . '"]');
		if (is_array($found) === false || $found === []) {
			return '';
		}

		return trim((string)$found[0]);
	}//end attribute()

	/**
	 * The first node an xpath finds.
	 *
	 * @param SimpleXMLElement $xml  The context.
	 * @param string           $path The xpath.
	 *
	 * @return SimpleXMLElement|null The node.
	 */
	private function first(SimpleXMLElement $xml, string $path): ?SimpleXMLElement {
		$found = $xml->xpath($path);
		if (is_array($found) === false || $found === []) {
			return null;
		}

		return $found[0];
	}//end first()

	/**
	 * The trimmed text of the first node an xpath finds.
	 *
	 * @param SimpleXMLElement $xml  The context.
	 * @param string           $path The xpath.
	 *
	 * @return string The text, '' when none.
	 */
	private function text(SimpleXMLElement $xml, string $path): string {
		$found = $this->first(xml: $xml, path: $path);
		if ($found === null) {
			return '';
		}

		return trim((string)$found);
	}//end text()
}//end class

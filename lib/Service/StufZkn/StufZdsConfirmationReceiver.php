<?php

/**
 * Integriq StUF-ZDS Confirmation Receiver.
 *
 * Receives the asynchronous Bv01/Fo01 a case system sends after it processed a
 * ZDS kennisgeving (creeerZaak, actualiseerZaak, vrijBericht), and concludes
 * the outbound stuf_message row it cross-references.
 *
 * Ported from dossiq's StufController::inbound() and StufEnvelopeInspector.
 * The caller authenticates with the WS-Security UsernameToken of the source
 * the outbound message went to; the source is found through the row the
 * crossRefnummer names, so an unknown crossRefnummer and a wrong password
 * answer alike and a referentienummer cannot be probed. A source without
 * WS-Security credentials accepts no confirmation (fail closed).
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
 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-asynchronous-confirmations-conclude-the-message
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\StufZkn;

use OCA\Integriq\Event\StufZdsCaseConcludedEvent;
use OCA\Integriq\Exception\StufZknProviderException;
use OCA\Integriq\Service\Stuf\StufXmlParser;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use SimpleXMLElement;
use Throwable;

/**
 * Concludes outbound ZDS messages from the case system's asynchronous confirmations.
 *
 * @psalm-type ConfirmationAnswer = array{statusCode:int,body:string}
 *
 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-asynchronous-confirmations-conclude-the-message
 */
class StufZdsConfirmationReceiver {

	/**
	 * The berichtcodes this receiver accepts.
	 *
	 * @var string[]
	 */
	public const CONFIRMATION_CODES = ['Bv01', 'Bv03', 'Fo01', 'Fo02', 'Fo03'];

	/**
	 * Constructor.
	 *
	 * @param StufZdsMessageLog             $log         Audit rows and source reads.
	 * @param StufZdsCredentialResolver     $credentials Decrypts the source's UsernameToken.
	 * @param StufZknAcknowledgementBuilder $acks        Builds the Bv03/Fo03 answer.
	 * @param IEventDispatcher              $dispatcher  Announces the conclusion.
	 * @param LoggerInterface               $logger      The logger.
	 * @param StufZdsResponseReader         $reader      Reads the confirmation.
	 * @param StufXmlParser                 $xmlParser   The hardened XML parser.
	 */
	public function __construct(
		private readonly StufZdsMessageLog $log,
		private readonly StufZdsCredentialResolver $credentials,
		private readonly StufZknAcknowledgementBuilder $acks,
		private readonly IEventDispatcher $dispatcher,
		private readonly LoggerInterface $logger,
		private readonly StufZdsResponseReader $reader = new StufZdsResponseReader(),
		private readonly StufXmlParser $xmlParser = new StufXmlParser(),
	) {

	}//end __construct()

	/**
	 * Receive one confirmation envelope.
	 *
	 * 200 with a Bv03 when it was recorded (or had been already), 400 when the
	 * body is not a confirmation, 422 when the crossRefnummer is unknown or the
	 * credentials do not match, 503 when the source's password cannot be read.
	 *
	 * @param string $rawXml The raw request body.
	 *
	 * @return array The answer (ConfirmationAnswer).
	 *
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-asynchronous-confirmations-conclude-the-message
	 */
	public function receive(string $rawXml): array {
		$root = $this->parse(rawXml: $rawXml);
		if ($root === null) {
			return $this->refuse(statusCode: 400, reason: 'unrecognised_berichttype', root: null, organisation: '');
		}

		$code     = $this->text(xml: $root, path: '//*[local-name()="stuurgegevens"]/*[local-name()="berichtcode"]');
		$crossRef = $this->text(xml: $root, path: '//*[local-name()="stuurgegevens"]/*[local-name()="crossRefnummer"]');
		if (in_array($code, self::CONFIRMATION_CODES, true) === false || $crossRef === '') {
			return $this->refuse(statusCode: 400, reason: 'unrecognised_berichttype', root: $root, organisation: '');
		}

		$row = $this->log->findOutbound(referentienummer: $crossRef);
		if ($row === null) {
			$this->logger->warning('StUF-ZDS confirmation: no outbound message {ref}', ['ref' => $crossRef]);
			return $this->refuse(statusCode: 422, reason: 'validation_failed', root: $root, organisation: '');
		}

		try {
			$source = $this->log->source(sourceId: (string)($row['sourceId'] ?? ''));
			$token  = $this->credentials->usernameToken(sourceConfiguration: (array)($source['configuration'] ?? []));
		} catch (StufZknProviderException $exception) {
			$this->logger->error('StUF-ZDS confirmation: source credentials unreadable: {error}', ['error' => $exception->getMessage()]);
			return $this->refuse(statusCode: 503, reason: 'not_configured', root: $root, organisation: '');
		}

		$organisation = (string)($source['configuration']['organisatie'] ?? '');
		if ($this->authenticates(root: $root, token: $token) === false) {
			$this->logger->warning('StUF-ZDS confirmation: credentials do not match source {id}', ['id' => ($source['id'] ?? '')]);
			return $this->refuse(statusCode: 422, reason: 'validation_failed', root: $root, organisation: $organisation);
		}

		if (($row['confirmation'] ?? '') !== $code) {
			$this->record(row: $row, code: $code, rawXml: $rawXml);
		}

		return [
			'statusCode' => 200,
			'body' => $this->acks->buildBv03(
				crossRefnummer: $this->text(xml: $root, path: '//*[local-name()="stuurgegevens"]/*[local-name()="referentienummer"]'),
				zenderOrganisation: $organisation,
				ontvangerOrg: $this->senderOrganisation(root: $root)
			),
		];
	}//end receive()

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
	 * Store the confirmation on the row and announce the conclusion.
	 *
	 * @param array  $row    The outbound row.
	 * @param string $code   The berichtcode.
	 * @param string $rawXml The confirmation.
	 *
	 * @return void
	 */
	private function record(array $row, string $code, string $rawXml): void {
		$fout = null;
		if (str_starts_with($code, 'Fo') === true) {
			$fout = $this->reader->fault(xml: $rawXml);
		}

		$caseId = null;
		if ($fout === null) {
			$caseId = $this->reader->confirmation(xml: $rawXml)['caseIdentification'];
		}

		$row   = $this->log->confirm(row: $row, berichtcode: $code, fout: $fout, caseIdentification: $caseId);
		$known = (string)($row['caseIdentification'] ?? '');
		if ($known === '') {
			$known = null;
		}

		try {
			$this->dispatcher->dispatchTyped(
				new StufZdsCaseConcludedEvent(
					sourceApp: (string)($row['sourceApp'] ?? ''),
					correlationId: (string)($row['correlationId'] ?? ''),
					operation: (string)($row['operation'] ?? ''),
					attempts: (int)($row['attempts'] ?? 0),
					result: [
						'success' => ($fout === null),
						'retrying' => false,
						'referentienummer' => (string)($row['referentienummer'] ?? ''),
						'messageId' => (string)($row['id'] ?? ''),
						'caseIdentification' => $known,
						'fout' => $fout,
						'confirmation' => $code,
					]
				)
			);
		} catch (Throwable $exception) {
			$this->logger->warning('StUF-ZDS conclusion listener failed: {error}', ['error' => $exception->getMessage()]);
		}
	}//end record()

	/**
	 * Whether the envelope's UsernameToken matches the source's.
	 *
	 * @param SimpleXMLElement                            $root  The envelope.
	 * @param array{username:string,password:string}|null $token The source's token, null when it has none.
	 *
	 * @return bool True when both match.
	 */
	private function authenticates(SimpleXMLElement $root, ?array $token): bool {
		if ($token === null || $token['username'] === '' || $token['password'] === '') {
			return false;
		}

		$tokenPath = '//*[local-name()="Security"]/*[local-name()="UsernameToken"]/*[local-name()="';
		$username  = $this->text(xml: $root, path: $tokenPath . 'Username"]');
		$password  = $this->text(xml: $root, path: $tokenPath . 'Password"]');

		return (hash_equals($token['username'], $username) === true && hash_equals($token['password'], $password) === true);
	}//end authenticates()

	/**
	 * A refusal: the HTTP status and a Fo03 that leaks nothing.
	 *
	 * @param int                   $statusCode   The HTTP status.
	 * @param string                $reason       A key into the acknowledgement builder's fault catalogue.
	 * @param SimpleXMLElement|null $root         The envelope, when it parsed.
	 * @param string                $organisation This side's organisatie, when known.
	 *
	 * @return array The answer (ConfirmationAnswer).
	 */
	private function refuse(int $statusCode, string $reason, ?SimpleXMLElement $root, string $organisation): array {
		$crossRef = '';
		$sender   = '';
		if ($root !== null) {
			$crossRef = $this->text(xml: $root, path: '//*[local-name()="stuurgegevens"]/*[local-name()="referentienummer"]');
			$sender   = $this->senderOrganisation(root: $root);
		}

		return [
			'statusCode' => $statusCode,
			'body' => $this->acks->buildFo03(reason: $reason, crossRefnummer: $crossRef, zenderOrganisation: $organisation, ontvangerOrg: $sender),
		];
	}//end refuse()

	/**
	 * The organisatie of the envelope's zender.
	 *
	 * @param SimpleXMLElement $root The envelope.
	 *
	 * @return string The organisatie, '' when absent.
	 */
	private function senderOrganisation(SimpleXMLElement $root): string {
		return $this->text(xml: $root, path: '//*[local-name()="stuurgegevens"]/*[local-name()="zender"]/*[local-name()="organisatie"]');
	}//end senderOrganisation()

	/**
	 * The trimmed text of the first node an xpath finds.
	 *
	 * @param SimpleXMLElement $xml  The root.
	 * @param string           $path The xpath.
	 *
	 * @return string The text, '' when none.
	 */
	private function text(SimpleXMLElement $xml, string $path): string {
		$found = $xml->xpath($path);
		if (is_array($found) === false || $found === []) {
			return '';
		}

		return trim((string)$found[0]);
	}//end text()
}//end class

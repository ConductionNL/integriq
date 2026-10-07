<?php

/**
 * Integriq — the WUS subscription check, ValidateAbonnementen.
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\Berichtenbox
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\Berichtenbox;

use DOMDocument;
use DOMXPath;
use GuzzleHttp\Client;
use OCA\Integriq\Exception\EgressRefusedException;
use OCA\Integriq\Exception\MtlsConfigurationException;
use OCA\Integriq\Exception\MtlsTransportException;
use OCA\Integriq\Service\Mtls\MtlsConfigResolver;
use OCA\Integriq\Service\Mtls\MtlsTransportService;
use OCA\Integriq\Service\Security\EgressGuard;

/**
 * Asks Logius, synchronously, which BSNs take letters of a BerichtType from this sender.
 *
 * SOAP 1.1 over two-way TLS, exactly as the vendored WSDL describes it
 * (`BasicHttpBinding_IBerichtenboxValidatieService`). The request is not signed:
 * "Het is niet mogelijk SOAP berichten te Signen" (aansluithandleiding 6.2). The
 * client certificate comes from integriq's mTLS transport, decrypted for this
 * one call and removed afterwards.
 *
 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-the-subscription-is-checked-before-every-send-req-dpa-009
 */
class BerichtenboxValidatieClient {
	public const SOAP_ACTION = 'http://schemas.rdw.nl/GEB/BerichtenboxValidatieService/2009/01/IBerichtenboxValidatieService/ValidateAbonnementen';

	public const NS_SOAP = 'http://schemas.xmlsoap.org/soap/envelope/';

	public const NS_SERVICE = 'http://schemas.rdw.nl/GEB/BerichtenboxValidatieService/2009/01';

	public const NS_TYPES = 'http://schemas.rdw.nl/GEB/BerichtenboxValidatieService/Types/2009/01';

	public const NS_SHARED = 'http://schemas.rdw.nl/GEB/Shared/Types/2009/01';

	public const MAX_BSNS = 250;

	/**
	 * Constructor.
	 *
	 * @param Client $httpClient The HTTP client.
	 * @param MtlsConfigResolver $mtlsConfigResolver Decrypts and checks the stored certificate.
	 * @param MtlsTransportService $mtlsTransport Sends with the certificate attached.
	 * @param EgressGuard $egressGuard Refuses a URL integriq may not call.
	 */
	public function __construct(
		private readonly Client $httpClient,
		private readonly MtlsConfigResolver $mtlsConfigResolver,
		private readonly MtlsTransportService $mtlsTransport,
		private readonly EgressGuard $egressGuard,
	) {
	}//end __construct()

	/**
	 * Check the subscriptions.
	 *
	 * @param array<int,string> $bsns 1 to 250 BSNs.
	 * @param string $berichtType The BerichtType code.
	 * @param array<string,mixed> $config The source configuration: `wusEndpoint`, `senderOin`, `authentication`.
	 *
	 * @return array<string,bool> BSN => `isBerichtSturen`.
	 *
	 * @throws BerichtenboxException When the call cannot be made, or Logius answers a fault.
	 */
	public function check(array $bsns, string $berichtType, array $config): array {
		$bsns = array_values(array_unique(array_map('strval', $bsns)));
		if ($bsns === [] || count($bsns) > self::MAX_BSNS) {
			throw new BerichtenboxException('A subscription check takes 1 to 250 BSNs.', BerichtenboxException::CODE_SUBSCRIPTION_FAULT);
		}

		$endpoint = (string)($config['wusEndpoint'] ?? '');
		try {
			$this->egressGuard->assertAllowed(url: $endpoint);
			$bundle = $this->mtlsConfigResolver->resolve(authConfig: (array)($config['authentication'] ?? []));
		} catch (EgressRefusedException $e) {
			throw new BerichtenboxException(
				message: 'The subscription check endpoint may not be called: ' . $e->getMessage(),
				reason: BerichtenboxException::CODE_NOT_CONFIGURED,
				previous: $e
			);
		} catch (MtlsConfigurationException $e) {
			throw new BerichtenboxException(
				message: 'The PKIoverheid certificate cannot be used: ' . $e->getMessage(),
				reason: BerichtenboxException::CODE_NOT_CONFIGURED,
				previous: $e
			);
		}

		try {
			$response = $this->mtlsTransport->request(
				$this->httpClient,
				'POST',
				$endpoint,
				[
					'headers' => [
						'Content-Type' => 'text/xml; charset=utf-8',
						'SOAPAction' => '"' . self::SOAP_ACTION . '"',
					],
					'body' => $this->envelope(bsns: $bsns, berichtType: $berichtType, senderOin: (string)($config['senderOin'] ?? '')),
					'http_errors' => false,
					'timeout' => 30,
				],
				$bundle
			);
		} catch (MtlsTransportException $e) {
			throw new BerichtenboxException(
				message: 'The subscription check did not reach Logius: ' . $e->getMessage(),
				reason: BerichtenboxException::CODE_SUBSCRIPTION_FAULT,
				previous: $e
			);
		}

		return $this->answers(xml: (string)$response->getBody(), status: $response->getStatusCode(), asked: $bsns);
	}//end check()

	/**
	 * The SOAP request, as the WSDL's document/literal binding has it.
	 *
	 * @param array<int,string> $bsns The BSNs.
	 * @param string $berichtType The BerichtType code.
	 * @param string $senderOin The sender's OIN.
	 *
	 * @return string The envelope.
	 */
	public function envelope(array $bsns, string $berichtType, string $senderOin): string {
		$document = new DOMDocument('1.0', 'UTF-8');
		$envelope = $document->createElementNS(self::NS_SOAP, 's:Envelope');
		$document->appendChild($envelope);
		$body = $envelope->appendChild($document->createElementNS(self::NS_SOAP, 's:Body'));
		$operation = $body->appendChild($document->createElementNS(self::NS_SERVICE, 'ValidateAbonnementen'));
		$request = $operation->appendChild($document->createElementNS(self::NS_SERVICE, 'validateAbonnementenAanvraag'));
		$request->appendChild($document->createElementNS(self::NS_TYPES, 'a:berichtleverancierCode'))->appendChild($document->createTextNode($senderOin));
		$request->appendChild($document->createElementNS(self::NS_TYPES, 'a:berichtTypeCode'))->appendChild($document->createTextNode($berichtType));
		$customers = $request->appendChild($document->createElementNS(self::NS_TYPES, 'a:klanten'));
		foreach ($bsns as $bsn) {
			$customer = $customers->appendChild($document->createElementNS(self::NS_SHARED, 'k:Klant'));
			$customer->appendChild($document->createElementNS(self::NS_SHARED, 'k:Key'))->appendChild($document->createTextNode($bsn));
			$customer->appendChild($document->createElementNS(self::NS_SHARED, 'k:Rol'))->appendChild($document->createTextNode('Burger'));
		}

		return (string)$document->saveXML();
	}//end envelope()

	/**
	 * Read the answer.
	 *
	 * @param string $xml The response body.
	 * @param int $status The HTTP status.
	 * @param array<int,string> $asked The BSNs asked about.
	 *
	 * @return array<string,bool> BSN => `isBerichtSturen`.
	 *
	 * @throws BerichtenboxException On a fault, an unreadable answer, or a BSN left unanswered.
	 */
	private function answers(string $xml, int $status, array $asked): array {
		$document = new DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$loaded = ($xml !== '' && $document->loadXML($xml, LIBXML_NONET) === true);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		if ($loaded === false) {
			throw new BerichtenboxException(
				message: sprintf('The subscription check answered HTTP %d without a SOAP body.', $status),
				reason: BerichtenboxException::CODE_SUBSCRIPTION_FAULT
			);
		}

		$xpath = new DOMXPath($document);
		$xpath->registerNamespace('s', self::NS_SOAP);
		$xpath->registerNamespace('w', self::NS_SERVICE);
		$xpath->registerNamespace('t', self::NS_TYPES);
		$xpath->registerNamespace('k', self::NS_SHARED);

		$this->assertNoFault(xpath: $xpath);

		$answers = [];
		$items = $xpath->query('/s:Envelope/s:Body/w:ValidateAbonnementenResponse/w:ValidateAbonnementenResult/t:Abonnement');
		if ($items === false) {
			$items = [];
		}

		foreach ($items as $item) {
			$bsn = trim((string)$xpath->evaluate('string(t:klant/k:Key)', $item));
			$answers[$bsn] = (trim((string)$xpath->evaluate('string(t:isBerichtSturen)', $item)) === 'true');
		}

		foreach ($asked as $bsn) {
			if (array_key_exists($bsn, $answers) === false) {
				throw new BerichtenboxException(
					message: 'Logius did not answer the subscription check for every BSN asked.',
					reason: BerichtenboxException::CODE_SUBSCRIPTION_FAULT
				);
			}
		}

		return $answers;
	}//end answers()
	/**
	 * Throw when the answer is a SOAP fault, with the fault's own message.
	 *
	 * @param DOMXPath $xpath The answer, with the SOAP and shared namespaces registered.
	 *
	 * @return void
	 *
	 * @throws BerichtenboxException On a fault.
	 */
	private function assertNoFault(DOMXPath $xpath): void {
		$fault = $xpath->query('/s:Envelope/s:Body/s:Fault');
		if ($fault !== false && $fault->length > 0) {
			$detail = trim((string)$xpath->evaluate('string(/s:Envelope/s:Body/s:Fault/detail//k:Message)'));
			$kind = (string)$xpath->evaluate('local-name(/s:Envelope/s:Body/s:Fault/detail/*[1])');
			if ($detail === '') {
				$detail = trim((string)$xpath->evaluate('string(/s:Envelope/s:Body/s:Fault/faultstring)'));
			}

			throw new BerichtenboxException(
				message: trim('Logius refused the subscription check: ' . $kind . ' ' . $detail),
				reason: BerichtenboxException::CODE_SUBSCRIPTION_FAULT
			);
		}
	}//end assertNoFault()
}//end class

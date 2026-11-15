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
use OCA\Integriq\Service\Mtls\MtlsCertificateBundle;
use OCA\Integriq\Service\Mtls\MtlsConfigResolverInterface;
use OCA\Integriq\Service\Mtls\MtlsTransportServiceInterface;
use OCA\Integriq\Service\Security\EgressGuardInterface;
use Psr\Http\Message\ResponseInterface;

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
	 * @param MtlsConfigResolverInterface $mtlsConfigResolver Decrypts and checks the stored certificate.
	 * @param MtlsTransportServiceInterface $mtlsTransport Sends with the certificate attached.
	 * @param EgressGuardInterface $egressGuard Refuses a URL integriq may not call.
	 */
	public function __construct(
		private readonly Client $httpClient,
		private readonly MtlsConfigResolverInterface $mtlsConfigResolver,
		private readonly MtlsTransportServiceInterface $mtlsTransport,
		private readonly EgressGuardInterface $egressGuard,
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
		$bsns = $this->distinctBsns(bsns: $bsns);
		$endpoint = (string)($config['wusEndpoint'] ?? '');
		$bundle = $this->certificateFor(endpoint: $endpoint, config: $config);
		$body = $this->envelope(bsns: $bsns, berichtType: $berichtType, senderOin: (string)($config['senderOin'] ?? ''));

		try {
			$response = $this->mtlsTransport->request($this->httpClient, 'POST', $endpoint, $this->requestOptions(body: $body), $bundle);
		} catch (MtlsTransportException $e) {
			throw $this->refusal(problem: 'The subscription check did not reach Logius: ', previous: $e);
		}

		return $this->answers(response: $response, asked: $bsns);
	}//end check()

	/**
	 * The BSNs to ask about, each once, refused unless there are 1 to 250.
	 *
	 * @param array<int,mixed> $bsns The BSNs as given.
	 *
	 * @return array<int,string> The distinct BSNs.
	 *
	 * @throws BerichtenboxException When there are none, or more than 250.
	 */
	private function distinctBsns(array $bsns): array {
		$bsns = array_values(array_unique(array_map('strval', $bsns)));
		if ($bsns === [] || count($bsns) > self::MAX_BSNS) {
			throw new BerichtenboxException('A subscription check takes 1 to 250 BSNs.', BerichtenboxException::CODE_SUBSCRIPTION_FAULT);
		}

		return $bsns;
	}//end distinctBsns()

	/**
	 * The client certificate for the call, once the endpoint is known to be one integriq may call.
	 *
	 * @param string              $endpoint The subscription check endpoint.
	 * @param array<string,mixed> $config   The source configuration.
	 *
	 * @return MtlsCertificateBundle The decrypted certificate.
	 *
	 * @throws BerichtenboxException When the endpoint may not be called or the certificate cannot be used.
	 */
	private function certificateFor(string $endpoint, array $config): MtlsCertificateBundle {
		try {
			$this->egressGuard->assertAllowed(url: $endpoint);
			return $this->mtlsConfigResolver->resolve(authConfig: (array)($config['authentication'] ?? []));
		} catch (EgressRefusedException $e) {
			throw $this->notConfigured(problem: 'The subscription check endpoint may not be called: ', previous: $e);
		} catch (MtlsConfigurationException $e) {
			throw $this->notConfigured(problem: 'The PKIoverheid certificate cannot be used: ', previous: $e);
		}
	}//end certificateFor()

	/**
	 * The request options of the SOAP call.
	 *
	 * @param string $body The envelope.
	 *
	 * @return array<string,mixed> The options.
	 */
	private function requestOptions(string $body): array {
		return [
			'headers' => [
				'Content-Type' => 'text/xml; charset=utf-8',
				'SOAPAction' => '"' . self::SOAP_ACTION . '"',
			],
			'body' => $body,
			'http_errors' => false,
			'timeout' => 30,
		];
	}//end requestOptions()

	/**
	 * A refusal because the check is not configured to be made.
	 *
	 * @param string    $problem  The start of the message.
	 * @param EgressRefusedException|MtlsTransportException $previous The cause, whose message completes it.
	 *
	 * @return BerichtenboxException The refusal.
	 */
	private function notConfigured(string $problem, EgressRefusedException|MtlsTransportException $previous): BerichtenboxException {
		return new BerichtenboxException(
			message: $problem . $previous->getMessage(),
			reason: BerichtenboxException::CODE_NOT_CONFIGURED,
			previous: $previous
		);
	}//end notConfigured()

	/**
	 * A refusal because the check failed.
	 *
	 * @param string    $problem  The start of the message.
	 * @param EgressRefusedException|MtlsTransportException $previous The cause, whose message completes it.
	 *
	 * @return BerichtenboxException The refusal.
	 */
	private function refusal(string $problem, EgressRefusedException|MtlsTransportException $previous): BerichtenboxException {
		return new BerichtenboxException(
			message: $problem . $previous->getMessage(),
			reason: BerichtenboxException::CODE_SUBSCRIPTION_FAULT,
			previous: $previous
		);
	}//end refusal()

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
	 * @param ResponseInterface $response The response.
	 * @param array<int,string> $asked    The BSNs asked about.
	 *
	 * @return array<string,bool> BSN => `isBerichtSturen`.
	 *
	 * @throws BerichtenboxException On a fault, an unreadable answer, or a BSN left unanswered.
	 */
	private function answers(ResponseInterface $response, array $asked): array {
		$xpath = $this->answerDocument(xml: (string)$response->getBody(), status: $response->getStatusCode());

		$this->assertNoFault(xpath: $xpath);

		$answers = $this->subscriptions(xpath: $xpath);
		$this->assertEveryBsnAnswered(answers: $answers, asked: $asked);

		return $answers;
	}//end answers()

	/**
	 * The answer as a document to query, with the SOAP, service, types and shared namespaces registered.
	 *
	 * @param string $xml    The response body.
	 * @param int    $status The HTTP status.
	 *
	 * @return DOMXPath The answer.
	 *
	 * @throws BerichtenboxException When the body is empty or not XML.
	 */
	private function answerDocument(string $xml, int $status): DOMXPath {
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
		$this->registerNamespaces(xpath: $xpath);

		return $xpath;
	}//end answerDocument()

	/**
	 * Register the prefixes the answer is queried with.
	 *
	 * @param DOMXPath $xpath The answer.
	 *
	 * @return void
	 */
	private function registerNamespaces(DOMXPath $xpath): void {
		$xpath->registerNamespace('s', self::NS_SOAP);
		$xpath->registerNamespace('w', self::NS_SERVICE);
		$xpath->registerNamespace('t', self::NS_TYPES);
		$xpath->registerNamespace('k', self::NS_SHARED);
	}//end registerNamespaces()

	/**
	 * The subscriptions in the answer.
	 *
	 * @param DOMXPath $xpath The answer.
	 *
	 * @return array<string,bool> BSN => `isBerichtSturen`.
	 */
	private function subscriptions(DOMXPath $xpath): array {
		$answers = [];
		$items = $xpath->query('/s:Envelope/s:Body/w:ValidateAbonnementenResponse/w:ValidateAbonnementenResult/t:Abonnement');
		if ($items === false) {
			$items = [];
		}

		foreach ($items as $item) {
			$bsn = trim((string)$xpath->evaluate('string(t:klant/k:Key)', $item));
			$answers[$bsn] = (trim((string)$xpath->evaluate('string(t:isBerichtSturen)', $item)) === 'true');
		}

		return $answers;
	}//end subscriptions()

	/**
	 * Throw unless every BSN asked about was answered.
	 *
	 * @param array<string,bool> $answers The subscriptions read.
	 * @param array<int,string>  $asked   The BSNs asked about.
	 *
	 * @return void
	 *
	 * @throws BerichtenboxException When a BSN was left unanswered.
	 */
	private function assertEveryBsnAnswered(array $answers, array $asked): void {
		foreach ($asked as $bsn) {
			if (array_key_exists($bsn, $answers) === false) {
				throw new BerichtenboxException(
					message: 'Logius did not answer the subscription check for every BSN asked.',
					reason: BerichtenboxException::CODE_SUBSCRIPTION_FAULT
				);
			}
		}
	}//end assertEveryBsnAnswered()

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

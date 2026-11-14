<?php

/**
 * Integriq WUS Digipoort Provider.
 *
 * The live binding of {@see DigipoortProviderInterface}. It builds the
 * aanleveren and getStatussenProces requests of Digipoort's koppelvlak,
 * signs each with WS-Security through {@see WusProfileService} using the
 * PKIoverheid certificate the credential broker holds under
 * `configuration.certificateRef`, and sends it over two-way TLS with the
 * client certificate `configuration.authentication.mtls` names. An answer
 * whose signature does not verify is rejected.
 *
 * Everything that differs between environments or WUS versions sits on the
 * source: the endpoints per `configuration.environment`, the autorisatie
 * adres, and the status codes that mean the recipient accepted the filing.
 * Without certificate material the provider fails closed, naming the
 * certificate, and sends nothing.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Digipoort
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
 * @spec openspec/changes/connectors-digipoort-sbr-filing/specs/digipoort-sbr-connector/spec.md#requirement-a-digipoort-source-delivers-through-a-log-or-wus-provider-req-dps-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Digipoort;

use DOMDocument;
use DOMElement;
use DOMXPath;
use GuzzleHttp\Client;
use OCA\Integriq\Adapters\Digikoppeling\WusProfileService;
use OCA\Integriq\Exception\DigikoppelingException;
use OCA\Integriq\Exception\DigipoortException;
use OCA\Integriq\Exception\EgressRefusedException;
use OCA\Integriq\Exception\MtlsConfigurationException;
use OCA\Integriq\Exception\MtlsTransportException;
use OCA\Integriq\Service\Mtls\MtlsCertificateBundle;
use OCA\Integriq\Service\Mtls\MtlsConfigResolverInterface;
use OCA\Integriq\Service\Mtls\MtlsTransportServiceInterface;
use OCA\Integriq\Service\Security\EgressGuardInterface;

/**
 * Digipoort over Digikoppeling WUS: signed, two-way TLS, verified answers.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/connectors-digipoort-sbr-filing/specs/digipoort-sbr-connector/spec.md#requirement-a-digipoort-source-delivers-through-a-log-or-wus-provider-req-dps-001
 */
class WusDigipoortProvider implements DigipoortProviderInterface {
	/**
	 * Namespace of Digipoort's koppelvlak services.
	 *
	 * @var string
	 */
	public const NS_KOPPELVLAK = 'http://logius.nl/digipoort/koppelvlakservices/1.2/';

	/**
	 * Autorisatie adres sent when the source names none: the value Digipoort
	 * takes for a filing that needs no authorisation by an intermediary.
	 *
	 * @var string
	 */
	public const DEFAULT_AUTORISATIE_ADRES = 'http://geenausp.nl';

	/**
	 * Status codes that mean the recipient accepted the filing, unless the
	 * source names its own in `configuration.acceptedStatusCodes`.
	 *
	 * @var list<string>
	 */
	public const DEFAULT_ACCEPTED_CODES = ['500'];

	/**
	 * Environments a source may name.
	 *
	 * @var list<string>
	 */
	private const ENVIRONMENTS = ['preproduction', 'production'];

	/**
	 * Constructor.
	 *
	 * @param Client                        $httpClient    The HTTP client the mTLS transport dispatches on.
	 * @param WusProfileService             $wusProfile    Signs requests and verifies answers (WS-Security).
	 * @param MtlsConfigResolverInterface   $mtlsResolver  Resolves the client certificate for two-way TLS.
	 * @param MtlsTransportServiceInterface $mtlsTransport Sends with the client certificate attached.
	 * @param EgressGuardInterface          $egressGuard   Refuses an endpoint the instance may not call.
	 */
	public function __construct(
		private readonly Client $httpClient,
		private readonly WusProfileService $wusProfile,
		private readonly MtlsConfigResolverInterface $mtlsResolver,
		private readonly MtlsTransportServiceInterface $mtlsTransport,
		private readonly EgressGuardInterface $egressGuard,
	) {
	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string,mixed> $configuration The source's `configuration`.
	 * @param SbrDelivery         $delivery      The instance and its envelope values.
	 *
	 * @return string Digipoort's kenmerk for the delivery.
	 *
	 * @throws DigipoortException When the certificate, endpoint or channel fails, or Digipoort refuses the instance.
	 *
	 * @spec openspec/changes/connectors-digipoort-sbr-filing/specs/digipoort-sbr-connector/spec.md#scenario-the-certificate-is-missing
	 */
	public function deliver(array $configuration, SbrDelivery $delivery): string {
		$body = '<aan:aanleverRequest xmlns:aan="' . self::NS_KOPPELVLAK . '">'
			. $this->element(name: 'kenmerk', value: $delivery->aanleverkenmerk)
			. $this->element(name: 'berichtsoort', value: $delivery->berichtsoort)
			. $this->element(name: 'aanleverkenmerk', value: $delivery->aanleverkenmerk)
			. '<aan:identiteitBelanghebbende>'
			. $this->element(name: 'nummer', value: $delivery->identityNumber)
			. $this->element(name: 'type', value: $delivery->identityType)
			. '</aan:identiteitBelanghebbende>'
			. $this->element(name: 'rolBelanghebbende', value: 'Bedrijf')
			. '<aan:berichtInhoud>'
			. $this->element(name: 'mimeType', value: 'text/xml')
			. $this->element(name: 'bestandsnaam', value: $delivery->fileName)
			. $this->element(name: 'inhoud', value: base64_encode($delivery->instance))
			. '</aan:berichtInhoud>'
			. $this->element(name: 'autorisatieAdres', value: $this->autorisatieAdres(configuration: $configuration))
			. '</aan:aanleverRequest>';

		$answer = $this->exchange(configuration: $configuration, endpointKey: 'aanleverUrl', body: $body);
		$kenmerk = $this->firstText(xpath: $answer, localName: 'kenmerk');
		if ($kenmerk === '') {
			throw new DigipoortException(message: 'Digipoort answered the delivery without a kenmerk.');
		}

		return $kenmerk;
	}//end deliver()

	/**
	 * {@inheritDoc}
	 *
	 * A status that carries a foutcode is a rejection, whatever its code; a
	 * status whose code is in the accepted list is an acceptance; any other
	 * status means the filing is still on its way.
	 *
	 * @param array<string,mixed> $configuration     The source's `configuration` (`acceptedStatusCodes`).
	 * @param string              $deliveryReference Digipoort's kenmerk for the delivery.
	 *
	 * @return list<array{status:string,code:string,description:string,at:string,errors:list<array{code:string,text:string}>}>
	 *
	 * @throws DigipoortException When the statuses cannot be read.
	 *
	 * @spec openspec/changes/connectors-digipoort-sbr-filing/specs/digipoort-sbr-connector/spec.md#requirement-every-status-change-is-reported-back-req-dps-003
	 */
	public function statuses(array $configuration, string $deliveryReference): array {
		$body = '<aan:getStatussenProcesRequest xmlns:aan="' . self::NS_KOPPELVLAK . '">'
			. $this->element(name: 'kenmerk', value: $deliveryReference)
			. $this->element(name: 'autorisatieAdres', value: $this->autorisatieAdres(configuration: $configuration))
			. '</aan:getStatussenProcesRequest>';

		$answer = $this->exchange(configuration: $configuration, endpointKey: 'statusUrl', body: $body);
		$accepted = array_map('strval', (array)($configuration['acceptedStatusCodes'] ?? self::DEFAULT_ACCEPTED_CODES));

		$statuses = [];
		$nodes = $answer->query('//*[local-name()="StatusResultaat"]');
		foreach (($nodes === false ? [] : $nodes) as $node) {
			if ($node instanceof DOMElement === false) {
				continue;
			}

			$statuses[] = $this->status(xpath: $answer, node: $node, acceptedCodes: $accepted);
		}

		return $statuses;
	}//end statuses()

	/**
	 * One StatusResultaat as `{status, code, description, at, errors}`.
	 *
	 * @param DOMXPath     $xpath         The parsed answer.
	 * @param DOMElement   $node          The StatusResultaat element.
	 * @param list<string> $acceptedCodes The codes that mean acceptance.
	 *
	 * @return array{status:string,code:string,description:string,at:string,errors:list<array{code:string,text:string}>}
	 */
	private function status(DOMXPath $xpath, DOMElement $node, array $acceptedCodes): array {
		$code = $this->firstText(xpath: $xpath, localName: 'statuscode', context: $node);
		$errors = [];
		$faults = $xpath->query('.//*[local-name()="statusFoutcode"]', $node);
		foreach (($faults === false ? [] : $faults) as $fault) {
			if ($fault instanceof DOMElement === false) {
				continue;
			}

			$errorCode = $this->firstText(xpath: $xpath, localName: 'foutcode', context: $fault);
			$text = $this->firstText(xpath: $xpath, localName: 'foutbeschrijving', context: $fault);
			if ($errorCode !== '' || $text !== '') {
				$errors[] = ['code' => $errorCode, 'text' => $text];
			}
		}

		$status = 'delivered';
		if ($errors !== []) {
			$status = 'rejected';
		} else if (in_array($code, $acceptedCodes, true) === true) {
			$status = 'accepted';
		}

		return [
			'status' => $status,
			'code' => $code,
			'description' => $this->firstText(xpath: $xpath, localName: 'statusomschrijving', context: $node),
			'at' => $this->firstText(xpath: $xpath, localName: 'tijdstempelStatus', context: $node),
			'errors' => $errors,
		];
	}//end status()

	/**
	 * Sign a request body, send it over two-way TLS and return the verified answer.
	 *
	 * @param array<string,mixed> $configuration The source's `configuration`.
	 * @param string              $endpointKey   `aanleverUrl` or `statusUrl`.
	 * @param string              $body          The koppelvlak request element.
	 *
	 * @return DOMXPath The verified answer, parsed.
	 *
	 * @throws DigipoortException When any step fails or Digipoort answers a fault.
	 */
	private function exchange(array $configuration, string $endpointKey, string $body): DOMXPath {
		$endpoint = $this->endpoint(configuration: $configuration, key: $endpointKey);
		$signed = $this->sign(configuration: $configuration, body: $body);
		$bundle = $this->clientCertificate(configuration: $configuration, endpoint: $endpoint);

		try {
			$response = $this->mtlsTransport->request(
				$this->httpClient,
				'POST',
				$endpoint,
				[
					'headers' => ['Content-Type' => 'text/xml; charset=utf-8'],
					'body' => $signed,
					'http_errors' => false,
					'timeout' => 60,
				],
				$bundle
			);
		} catch (MtlsTransportException $exception) {
			throw new DigipoortException(message: 'Digipoort could not be reached: ' . $exception->getMessage(), previous: $exception);
		}

		$answer = (string)$response->getBody();
		$xpath = $this->parse(xml: $answer);
		$fault = $this->fault(xpath: $xpath);

		$statusCode = $response->getStatusCode();
		if ($statusCode < 200 || $statusCode >= 300 || $fault !== '') {
			throw new DigipoortException(
				message: 'Digipoort refused the request (HTTP ' . $statusCode . ')' . ($fault === '' ? '.' : ': ' . $fault)
			);
		}

		try {
			$this->wusProfile->verifyResponse(responseXml: $answer);
		} catch (DigikoppelingException $exception) {
			throw new DigipoortException(message: $exception->getMessage(), previous: $exception);
		}

		return $xpath;
	}//end exchange()

	/**
	 * The description of a SOAP fault in the answer, or '' when it carries none.
	 *
	 * Only the Fault element is read: a status's own foutbeschrijving is a
	 * rejection by the recipient, not a refusal of the request.
	 *
	 * @param DOMXPath $xpath The parsed answer.
	 *
	 * @return string
	 */
	private function fault(DOMXPath $xpath): string {
		$faults = $xpath->query('//*[local-name()="Fault"]');
		$fault = ($faults === false ? null : $faults->item(0));
		if ($fault instanceof DOMElement === false) {
			return '';
		}

		$description = $this->firstText(xpath: $xpath, localName: 'foutbeschrijving', context: $fault);
		if ($description === '') {
			$description = $this->firstText(xpath: $xpath, localName: 'faultstring', context: $fault);
		}

		if ($description === '') {
			return 'SOAP fault';
		}

		return $description;
	}//end fault()

	/**
	 * The endpoint for the source's environment, after the egress check.
	 *
	 * @param array<string,mixed> $configuration The source's `configuration` (`environment`, `endpoints`).
	 * @param string              $key           `aanleverUrl` or `statusUrl`.
	 *
	 * @return string The URL.
	 *
	 * @throws DigipoortException When the environment or endpoint is missing, or the URL may not be called.
	 */
	private function endpoint(array $configuration, string $key): string {
		$environment = (string)($configuration['environment'] ?? '');
		if (in_array($environment, self::ENVIRONMENTS, true) === false) {
			throw new DigipoortException(message: 'The Digipoort source names no environment (preproduction or production).');
		}

		$url = (string)($configuration['endpoints'][$environment][$key] ?? '');
		if ($url === '') {
			throw new DigipoortException(message: 'The Digipoort source has no ' . $key . ' for ' . $environment . '.');
		}

		try {
			$this->egressGuard->assertAllowed(url: $url);
		} catch (EgressRefusedException $exception) {
			throw new DigipoortException(message: 'The Digipoort endpoint may not be called: ' . $exception->getMessage(), previous: $exception);
		}

		return $url;
	}//end endpoint()

	/**
	 * Sign the request with the PKIoverheid certificate, failing closed and naming it.
	 *
	 * @param array<string,mixed> $configuration The source's `configuration` (`certificateRef`).
	 * @param string              $body          The koppelvlak request element.
	 *
	 * @return string The signed SOAP envelope.
	 *
	 * @throws DigipoortException When the certificate cannot be resolved.
	 *
	 * @spec openspec/changes/connectors-digipoort-sbr-filing/specs/digipoort-sbr-connector/spec.md#scenario-the-certificate-is-missing
	 */
	private function sign(array $configuration, string $body): string {
		$certificateRef = (string)($configuration['certificateRef'] ?? '');
		try {
			return $this->wusProfile->buildSignedRequest(certificateRef: $certificateRef, stufBodyXml: $body);
		} catch (DigikoppelingException $exception) {
			$name = $certificateRef;
			if ($name === '') {
				$name = '(none configured)';
			}

			throw new DigipoortException(
				message: 'The PKIoverheid certificate ' . $name . ' cannot be used to sign the filing: ' . $exception->getMessage(),
				previous: $exception
			);
		}
	}//end sign()

	/**
	 * The client certificate for two-way TLS.
	 *
	 * @param array<string,mixed> $configuration The source's `configuration` (`authentication`).
	 * @param string              $endpoint      The URL, for the refusal.
	 *
	 * @return MtlsCertificateBundle The certificate material.
	 *
	 * @throws DigipoortException When no usable client certificate is configured.
	 */
	private function clientCertificate(array $configuration, string $endpoint): MtlsCertificateBundle {
		try {
			return $this->mtlsResolver->resolve(authConfig: (array)($configuration['authentication'] ?? []));
		} catch (MtlsConfigurationException $exception) {
			throw new DigipoortException(
				message: 'The client certificate for ' . $endpoint . ' cannot be used: ' . $exception->getMessage(),
				previous: $exception
			);
		}
	}//end clientCertificate()

	/**
	 * The autorisatie adres from the source, or the default.
	 *
	 * @param array<string,mixed> $configuration The source's `configuration`.
	 *
	 * @return string
	 */
	private function autorisatieAdres(array $configuration): string {
		$address = (string)($configuration['autorisatieAdres'] ?? '');
		if ($address === '') {
			return self::DEFAULT_AUTORISATIE_ADRES;
		}

		return $address;
	}//end autorisatieAdres()

	/**
	 * One koppelvlak element with an escaped text value.
	 *
	 * @param string $name  The local name.
	 * @param string $value The text.
	 *
	 * @return string
	 */
	private function element(string $name, string $value): string {
		return '<aan:' . $name . '>' . htmlspecialchars($value, (ENT_XML1 | ENT_QUOTES), 'UTF-8') . '</aan:' . $name . '>';
	}//end element()

	/**
	 * Parse an answer, refusing one that is not XML.
	 *
	 * @param string $xml The answer.
	 *
	 * @return DOMXPath
	 *
	 * @throws DigipoortException When the answer is not well-formed XML.
	 */
	private function parse(string $xml): DOMXPath {
		$document = new DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$loaded = ($xml !== '' && $document->loadXML($xml, LIBXML_NONET) === true);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		if ($loaded === false) {
			throw new DigipoortException(message: 'Digipoort answered with something that is not XML.');
		}

		return new DOMXPath($document);
	}//end parse()

	/**
	 * The trimmed text of the first element with a local name, or ''.
	 *
	 * @param DOMXPath        $xpath     The parsed document.
	 * @param string          $localName The element's local name.
	 * @param DOMElement|null $context   Search below this element only.
	 *
	 * @return string
	 */
	private function firstText(DOMXPath $xpath, string $localName, ?DOMElement $context = null): string {
		$nodes = $xpath->query('.//*[local-name()="' . $localName . '"]', $context);
		$node = ($nodes === false ? null : $nodes->item(0));
		if ($node instanceof DOMElement === false) {
			return '';
		}

		return trim($node->textContent);
	}//end firstText()
}//end class

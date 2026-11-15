<?php

/**
 * Integriq StUF-ZKN Client.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Thin binding for {@see StufZknProviderInterface} against a subscribed
 * legacy StUF consumer's inbound endpoint. Deliberately a hand-rolled HTTP
 * client (Guzzle, already an app dependency) rather than a SOAP/XSD library
 * dependency — mirrors {@see \OCA\Integriq\Service\IwmoIjw\IStandardsClient}
 * and {@see \OCA\Integriq\Service\Dso\DsoClient}.
 *
 * ASSUMED TRANSPORT SHAPE — no live municipal StUF-ZKN endpoint was
 * available to verify against in this environment; every endpoint/header
 * below is an explicit, documented assumption. See design.md "StUF
 * element/attribute assumptions":
 * - `POST {baseUrl}` with the fully rendered `zakLk01` SOAP envelope XML as
 *   the raw request body (`Content-Type: text/xml; charset=utf-8`,
 *   `SOAPAction: ""` — the conventional empty-SOAPAction shape most StUF
 *   gateways accept). The response is expected to be the consumer's own
 *   `Bv03Bericht`/`Fo03Bericht` SOAP reply; this client extracts a usable
 *   reference from it (the reply's own `referentienummer` when present,
 *   else a locally-derived one) via {@see extractRef()} but does NOT
 *   otherwise validate/act on the reply's fault/success shape — that is a
 *   receiving concern the CONSUMER'S OWN system owns, not this bridge's.
 *
 * mTLS: real StUF-ZKN deployments overwhelmingly run over PKIoverheid
 * mutual-TLS (municipal Suwinet/Digikoppeling-style network trust), so this
 * client dispatches through the SAME shared
 * {@see \OCA\Integriq\Service\Mtls\MtlsTransportService} +
 * `authentication.mode` (`token`|`mtls`) pattern already proven by
 * `IStandardsClient`/`FscDirectoryClient`/`DsoClient` — never a new,
 * bespoke TLS implementation. Token mode remains available as the
 * demonstrable pre-production fallback (mirrors every sibling REST
 * binding's identical, already-accepted deviation for the SAME reason: no
 * live endpoint to test client-cert issuance against in this environment).
 *
 * CREDENTIAL STORAGE: the token is a static bearer-style secret — stored
 * `configuration.authentication.encryptedToken`, ENCRYPTED AT REST via
 * Nextcloud's `OCP\Security\ICrypto`, decrypted in-process only for the
 * instant needed to build each request's Authorization header (never
 * logged, never persisted decrypted).
 *
 * @category Service
 * @package  OCA\Integriq\Service\StufZkn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/stuf-zkn-bridge/spec.md#requirement-stufzkn-outbound-provider-abstraction-with-log-and-rest-bindings-req-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\StufZkn;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OCA\Integriq\Exception\MtlsTransportException;
use OCA\Integriq\Exception\StufZknProviderException;
use OCA\Integriq\Service\Mtls\ClientAuthenticationSchema;
use OCA\Integriq\Service\Mtls\MtlsConfigResolver;
use OCA\Integriq\Service\Mtls\MtlsTransportService;
use OCA\Integriq\Service\Stuf\StufXmlParser;
use OCP\IL10N;
use OCP\Security\ICrypto;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Generic REST/SOAP-over-HTTP StUF-ZKN outbound provider: mTLS-first dispatch.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/stuf-zkn-bridge/spec.md#requirement-stufzkn-outbound-provider-abstraction-with-log-and-rest-bindings-req-001
 */
class StufZknClient implements StufZknProviderInterface {

	/**
	 * Default `Authorization` header scheme.
	 *
	 * @var string
	 */
	private const DEFAULT_AUTH_SCHEME = ClientAuthenticationSchema::DEFAULT_SCHEME;

	/**
	 * Read timeout of one StUF-ZDS case message, in seconds.
	 *
	 * @var int
	 */
	public const ZDS_TIMEOUT_SECONDS = 30;

	/**
	 * Constructor.
	 *
	 * @param Client $httpClient Guzzle client (test seam: inject one with a MockHandler stack).
	 * @param ICrypto $crypto Encrypts/decrypts the stored API token at rest.
	 * @param IL10N $l The localization service.
	 * @param LoggerInterface $logger Logger for secret-free failure diagnostics.
	 * @param MtlsConfigResolver $mtlsConfigResolver Resolves `authentication.mtls` into a certificate bundle.
	 * @param MtlsTransportService $mtlsTransport Dispatches the request with a client certificate attached.
	 * @param StufXmlParser $xmlParser Shared XXE-hardened XML parser (reply ref extraction).
	 */
	public function __construct(
		private readonly Client $httpClient,
		private readonly ICrypto $crypto,
		private readonly IL10N $l,
		private readonly LoggerInterface $logger,
		private readonly MtlsConfigResolver $mtlsConfigResolver,
		private readonly MtlsTransportService $mtlsTransport,
		private readonly StufXmlParser $xmlParser = new StufXmlParser(),
	) {

	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The stable `rest` provider identifier.
	 *
	 * @spec openspec/specs/stuf-zkn-bridge/spec.md#requirement-stufzkn-outbound-provider-abstraction-with-log-and-rest-bindings-req-001
	 */
	public function getProviderId(): string {
		return 'rest';
	}//end getProviderId()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed> The StUF-ZKN source configuration JSON Schema.
	 *
	 * @spec openspec/specs/stuf-zkn-bridge/spec.md#requirement-stufzkn-outbound-provider-abstraction-with-log-and-rest-bindings-req-001
	 */
	public function getConfigSchema(): array {
		return [
			'type' => 'object',
			'required' => ['authentication'],
			'properties' => [
				'baseUrl' => [
					'type' => 'string',
					'description' => 'The subscribed StUF consumer\'s inbound endpoint URL',
				],
				'authentication' => [
					'type' => 'object',
					'properties' => [
						'mode' => ClientAuthenticationSchema::modeField(
							modes: ['token', 'mtls', 'wsse'],
							description: '`token` (default, pre-production fallback) sends a Bearer '
							. 'Authorization header from `encryptedToken`. `mtls` (the expected production '
							. 'mode for a real StUF/PKIoverheid-secured consumer) dispatches over a real '
							. 'mutual-TLS connection using `mtls.*`. `wsse` sends no Authorization header: '
							. 'the ZDS case client puts a WS-Security UsernameToken from `wsse.*` in the envelope.'
						),
						'wsse' => [
							'type' => 'object',
							'description' => 'Optional WS-Security UsernameToken for StUF-ZDS case messages, '
								. 'used by the ZDS case client in any mode. The password is encrypted at rest via '
								. 'OCP\\Security\\ICrypto.',
							'properties' => [
								'username' => ['type' => 'string', 'description' => 'The UsernameToken user name.'],
								'encryptedPassword' => ['type' => 'string', 'description' => 'The UsernameToken password, encrypted.'],
								'requireNonce' => [
									'type' => 'boolean',
									'default' => false,
									'description' => 'Inbound only (provider requests and confirmations): refuse a UsernameToken that carries no Nonce and Created. '
										. 'A token that does carry them is always checked: Created within five minutes of now, and a Nonce not seen before.',
								],
							],
						],
						'encryptedToken' => ClientAuthenticationSchema::tokenField(
							description: 'Required when `mode=token`. The StUF consumer API token, encrypted '
							. 'at rest via OCP\\Security\\ICrypto — never store the raw token.'
						),
						'scheme' => ClientAuthenticationSchema::schemeField(),
						'mtls' => ClientAuthenticationSchema::mtlsField(),
					],
				],
				'organisatie' => [
					'type' => 'string',
					'description' => 'This bridge\'s own organisatie code (stuurgegevens.zender default).',
				],
			],
		];

	}//end getConfigSchema()

	/**
	 * {@inheritDoc}
	 *
	 * @param array $sourceConfiguration The `stuf-zkn` source's `configuration` object.
	 * @param string $referenceNumber The kennisgeving's `stuurgegevens.referentienummer`.
	 * @param string $envelopeXml The fully rendered `zakLk01` envelope XML.
	 *
	 * @return string The extracted (or locally-derived) reference.
	 *
	 * @spec openspec/specs/stuf-zkn-bridge/spec.md#scenario-the-rest-provider-sends-the-expected-content-type-and-mtls-routing
	 */
	public function send(array $sourceConfiguration, string $referenceNumber, string $envelopeXml): string {
		$response = $this->post(call: new SoapCall(sourceConfiguration: $sourceConfiguration, soapAction: '""', envelopeXml: $envelopeXml));

		$status = $response->getStatusCode();
		$body = (string)$response->getBody();
		if ($status < 200 || $status >= 300) {
			throw new StufZknProviderException(message: 'StUF-ZKN consumer endpoint responded with HTTP ' . $status . '.');
		}

		return $this->extractRef(body: $body, referenceNumber: $referenceNumber);
	}//end send()

	/**
	 * Send one ZDS message and return the case system's answer.
	 *
	 * Unlike {@see send()}, the caller needs the answer itself (a Du02 carries
	 * the document identificatie) and a refusal in the case system's own
	 * words: a non-2xx answer throws with the Fo03 `code` and `omschrijving`,
	 * else the SOAP `faultstring`, else the HTTP status.
	 *
	 * @param array  $sourceConfiguration The `stuf-zkn` source's `configuration` object.
	 * @param string $soapAction          The ZDS SOAPAction (quoted), e.g. the voegZaakdocumentToe_Lk01 action.
	 * @param string $envelopeXml         The rendered envelope.
	 * @param string $url                 The service address; '' for `configuration.baseUrl`.
	 *
	 * @return string The answer body.
	 *
	 * @throws StufZknProviderException When the request fails or the case system refuses it.
	 *
	 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-filinq-delivery-becomes-a-document-in-the-case-system-req-csd-002
	 */
	public function exchange(array $sourceConfiguration, string $soapAction, string $envelopeXml, string $url=''): string {
		$call = new SoapCall(sourceConfiguration: $sourceConfiguration, soapAction: $soapAction, envelopeXml: $envelopeXml, url: $url);
		$response = $this->post(call: $call);

		$status = $response->getStatusCode();
		$body = (string)$response->getBody();
		if ($status >= 200 && $status < 300) {
			return $body;
		}

		throw new StufZknProviderException(message: 'The case system answered HTTP ' . $status . ': ' . $this->faultText(body: $body));
	}//end exchange()

	/**
	 * The refusal text of a SOAP fault: Fo03 code and omschrijving, else faultstring.
	 *
	 * @param string $body The answer body.
	 *
	 * @return string The text, or 'no fault details' when the body carries none.
	 */
	private function faultText(string $body): string {
		$parsed = $this->xmlParser->parse(xml: $body);
		if ($parsed === null) {
			return 'no fault details';
		}

		$parts = [];
		foreach (['code', 'omschrijving', 'details'] as $name) {
			$parts[] = $this->firstText(xml: $parsed, path: '//*[local-name()="Fo03Bericht"]/*[local-name()="body"]/*[local-name()="' . $name . '"]');
		}

		$parts = array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
		if ($parts === []) {
			$parts[] = $this->firstText(xml: $parsed, path: '//*[local-name()="Fault"]/*[local-name()="faultstring"]');
		}

		$text = trim(implode(' ', $parts));
		if ($text === '') {
			return 'no fault details';
		}

		return $text;
	}//end faultText()

	/**
	 * The trimmed text of the first node a path selects, '' when none.
	 *
	 * @param \SimpleXMLElement $xml  The parsed answer.
	 * @param string            $path The XPath.
	 *
	 * @return string
	 */
	private function firstText(\SimpleXMLElement $xml, string $path): string {
		$found = $xml->xpath($path);
		if (is_array($found) === false || $found === []) {
			return '';
		}

		return trim((string)$found[0]);
	}//end firstText()

	/**
	 * Send one StUF-ZDS envelope and report what happened, without throwing.
	 *
	 * Ported from dossiq's StufHttpClient: the ZDS case client needs the raw
	 * status, body and round-trip time of every attempt to classify it
	 * (bevestiging, fout, transient, permanent) and to drive its circuit
	 * breaker. A plain-HTTP address is refused before anything is sent; a
	 * transport failure comes back as status 0 with a TIMEOUT or NETWORK fout.
	 *
	 * @param array  $sourceConfiguration The `stuf-zkn` source's `configuration` object.
	 * @param string $soapAction          The SOAPAction header value.
	 * @param string $envelopeXml         The rendered envelope.
	 * @param string $url                 The address; '' for `configuration.baseUrl`.
	 * @param int    $timeoutSeconds      The read timeout.
	 *
	 * @return array{httpStatus:int,responseXml:string,durationMs:int,fout:array<string,string>|null}
	 *
	 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-secure-transport
	 */
	public function transmit(
		array $sourceConfiguration,
		string $soapAction,
		string $envelopeXml,
		string $url='',
		int $timeoutSeconds=self::ZDS_TIMEOUT_SECONDS,
	): array {
		$address = $this->transmitAddress(sourceConfiguration: $sourceConfiguration, url: $url);

		$call = new SoapCall(
			sourceConfiguration: $sourceConfiguration,
			soapAction: $soapAction,
			envelopeXml: $envelopeXml,
			url: $address,
			timeoutSeconds: $timeoutSeconds
		);

		return $this->transmitCall(call: $call);
	}//end transmit()

	/**
	 * The address an attempt goes to: the explicit one, else `configuration.baseUrl`.
	 *
	 * @param array  $sourceConfiguration The `stuf-zkn` source's `configuration` object.
	 * @param string $url                 The explicit address, or ''.
	 *
	 * @return string The trimmed address.
	 */
	private function transmitAddress(array $sourceConfiguration, string $url): string {
		$address = trim($url);
		if ($address === '') {
			$address = trim((string)($sourceConfiguration['baseUrl'] ?? ''));
		}

		return $address;

	}//end transmitAddress()

	/**
	 * Send one attempt to its resolved address and report what happened, without throwing.
	 *
	 * @param SoapCall $call The attempt, its address resolved.
	 *
	 * @return array{httpStatus:int,responseXml:string,durationMs:int,fout:array<string,string>|null}
	 */
	private function transmitCall(SoapCall $call): array {
		if (str_starts_with($call->url, 'https://') === false) {
			return $this->transmitFailure(
				fout: ['code' => 'TRANSPORT_NON_HTTPS', 'omschrijving' => 'Endpoint URL is not HTTPS', 'details' => '', 'kind' => 'permanent'],
				durationMs: 0
			);
		}

		$started = microtime(true);
		try {
			return $this->answered(response: $this->post(call: $call), started: $started);
		} catch (StufZknProviderException $exception) {
			return $this->unsentFailure(exception: $exception, started: $started);
		}

	}//end transmitCall()

	/**
	 * The result of an attempt that got an answer, whatever its status.
	 *
	 * @param ResponseInterface $response The answer.
	 * @param float             $started  microtime(true) when the attempt started.
	 *
	 * @return array{httpStatus:int,responseXml:string,durationMs:int,fout:null}
	 */
	private function answered(ResponseInterface $response, float $started): array {
		return [
			'httpStatus' => $response->getStatusCode(),
			'responseXml' => (string)$response->getBody(),
			'durationMs' => (int)round((microtime(true) - $started) * 1000),
			'fout' => null,
		];

	}//end answered()

	/**
	 * The result of an attempt post() refused or that failed in transport.
	 *
	 * A refusal without a previous exception came before sending (no address
	 * or no usable credential) and is permanent; a transport failure is a
	 * transient TIMEOUT or NETWORK fout with the time it took.
	 *
	 * @param StufZknProviderException $exception What post() threw.
	 * @param float                    $started   microtime(true) when the attempt started.
	 *
	 * @return array{httpStatus:int,responseXml:string,durationMs:int,fout:array<string,string>}
	 */
	private function unsentFailure(StufZknProviderException $exception, float $started): array {
		$message = $exception->getMessage();
		if ($exception->getPrevious() === null) {
			// Refused before sending: no address or no usable credential.
			return $this->transmitFailure(
				fout: ['code' => 'TRANSPORT_CONFIGURATION', 'omschrijving' => 'The source cannot send', 'details' => $message, 'kind' => 'permanent'],
				durationMs: 0
			);
		}

		return $this->transmitFailure(
			fout: ['code' => $this->transportFoutCode(message: $message), 'omschrijving' => 'Transport error', 'details' => $message, 'kind' => 'transient'],
			durationMs: (int)round((microtime(true) - $started) * 1000)
		);

	}//end unsentFailure()

	/**
	 * TIMEOUT when the transport message says the request timed out, else NETWORK.
	 *
	 * @param string $message The transport failure message.
	 *
	 * @return string The fout code.
	 */
	private function transportFoutCode(string $message): string {
		if (str_contains(strtolower($message), 'timed out') === true || str_contains(strtolower($message), 'timeout') === true) {
			return 'TIMEOUT';
		}

		return 'NETWORK';

	}//end transportFoutCode()

	/**
	 * The result of an attempt that got no answer from the case system.
	 *
	 * @param array{code:string,omschrijving:string,details:string,kind:string} $fout The fout: its code, the
	 *                                                                             short reason, the detail text, and
	 *                                                                             `transient` or `permanent`.
	 * @param int $durationMs How long the attempt took.
	 *
	 * @return array{httpStatus:int,responseXml:string,durationMs:int,fout:array<string,string>}
	 */
	private function transmitFailure(array $fout, int $durationMs): array {
		return [
			'httpStatus' => 0,
			'responseXml' => '',
			'durationMs' => $durationMs,
			'fout' => $fout,
		];
	}//end transmitFailure()

	/**
	 * POST an envelope with the SOAP headers, over mTLS when configured.
	 *
	 * @param SoapCall $call The envelope and where and how to send it.
	 *
	 * @return ResponseInterface The answer, whatever its status.
	 *
	 * @throws StufZknProviderException When no address is configured or the transport fails.
	 */
	private function post(SoapCall $call): ResponseInterface {
		$url = $this->postUrl(call: $call);
		$authConfig = (array)($call->sourceConfiguration['authentication'] ?? []);
		$useMtls = $this->mtlsConfigResolver->isMtlsConfigured(authConfig: $authConfig);
		$requestOptions = $this->soapRequestOptions(call: $call, useMtls: $useMtls);

		try {
			return $this->dispatch(useMtls: $useMtls, authConfig: $authConfig, url: $url, requestOptions: $requestOptions);
		} catch (MtlsTransportException $exception) {
			throw $this->mtlsFailure(exception: $exception);
		} catch (GuzzleException $exception) {
			throw $this->unexpectedFailure(exception: $exception);
		}//end try

	}//end post()

	/**
	 * The address to post to: the call's own, else `configuration.baseUrl`, which must be set either way.
	 *
	 * @param SoapCall $call The call.
	 *
	 * @return string The address.
	 *
	 * @throws StufZknProviderException When no base URL is configured.
	 */
	private function postUrl(SoapCall $call): string {
		$baseUrl = trim((string)($call->sourceConfiguration['baseUrl'] ?? ''));
		if ($baseUrl === '') {
			throw new StufZknProviderException(
				message: $this->l->t('StUF-ZKN consumer base URL missing') . ': `configuration.baseUrl` is required.'
			);
		}

		if (trim($call->url) === '') {
			return $baseUrl;
		}

		return $call->url;

	}//end postUrl()

	/**
	 * The Guzzle options: SOAP headers, a token Authorization header unless mTLS or WS-Security, the body and timeout.
	 *
	 * @param SoapCall $call    The call.
	 * @param bool     $useMtls Whether the request goes over mTLS.
	 *
	 * @return array The request options.
	 *
	 * @throws StufZknProviderException When token mode has no usable token.
	 */
	private function soapRequestOptions(SoapCall $call, bool $useMtls): array {
		$headers = [
			'Content-Type' => 'text/xml; charset=utf-8',
			'SOAPAction' => $call->soapAction,
			'Accept' => 'text/xml, application/soap+xml',
		];
		if ($useMtls === false && ($call->sourceConfiguration['authentication']['mode'] ?? 'token') !== 'wsse') {
			$headers['Authorization'] = $this->buildAuthorizationHeader(sourceConfiguration: $call->sourceConfiguration);
		}

		$requestOptions = ['headers' => $headers, 'body' => $call->envelopeXml, 'http_errors' => false];
		if ($call->timeoutSeconds > 0) {
			$requestOptions['timeout'] = $call->timeoutSeconds;
		}

		return $requestOptions;

	}//end soapRequestOptions()

	/**
	 * Log a failed mTLS request and wrap it.
	 *
	 * @param MtlsTransportException $exception The failure.
	 *
	 * @return StufZknProviderException The exception to throw.
	 */
	private function mtlsFailure(MtlsTransportException $exception): StufZknProviderException {
		$this->logger->warning(
			'[StufZknClient] mTLS request failed',
			['exception' => $exception->getMessage(), 'errorCode' => $exception->getErrorCode()]
		);

		return new StufZknProviderException(
			message: 'The StUF-ZKN mTLS request failed (' . $exception->getErrorCode() . '): ' . $exception->getMessage(),
			previous: $exception
		);

	}//end mtlsFailure()

	/**
	 * Log an unexpected transport failure and wrap it.
	 *
	 * @param GuzzleException $exception The failure.
	 *
	 * @return StufZknProviderException The exception to throw.
	 */
	private function unexpectedFailure(GuzzleException $exception): StufZknProviderException {
		$this->logger->warning(
			'[StufZknClient] unexpected transport failure',
			['exception' => $exception->getMessage()]
		);

		return new StufZknProviderException(
			message: 'The StUF-ZKN request failed unexpectedly: ' . $exception->getMessage(),
			previous: $exception
		);

	}//end unexpectedFailure()

	/**
	 * Dispatch the request over mTLS when configured, else over the existing token-mode path.
	 *
	 * @param boolean $useMtls Whether `authentication.mode=mtls` is configured.
	 * @param array $authConfig The source's `configuration.authentication` object.
	 * @param string $url The absolute request URL.
	 * @param array $requestOptions The Guzzle request options.
	 *
	 * @return ResponseInterface The Guzzle response.
	 *
	 * @throws MtlsTransportException When mTLS is configured but the material is unusable or the handshake fails.
	 * @throws GuzzleException When the token-mode dispatch fails.
	 *
	 * @spec openspec/specs/mtls-client-certificate-transport/spec.md#requirement-each-adapter-routes-through-the-mtls-transport-only-when-configured-proving-no-orphaned-capability-req-004
	 */
	private function dispatch(bool $useMtls, array $authConfig, string $url, array $requestOptions): ResponseInterface {
		if ($useMtls === true) {
			$bundle = $this->mtlsConfigResolver->resolve(authConfig: $authConfig);
			return $this->mtlsTransport->request($this->httpClient, 'POST', $url, $requestOptions, $bundle);
		}

		return $this->httpClient->request('POST', $url, $requestOptions);
	}//end dispatch()

	/**
	 * Extract a usable reference from the consumer's reply body — the reply's own
	 * `referentienummer` when the body parses as XML and carries one, else a locally-derived
	 * reference so the caller still has something to persist/correlate on.
	 *
	 * @param string $body The raw response body.
	 * @param string $referenceNumber The outbound kennisgeving's own referentienummer.
	 *
	 * @return string The extracted (or locally-derived) reference.
	 */
	private function extractRef(string $body, string $referenceNumber): string {
		$parsed = $this->xmlParser->parse(xml: $body);
		if ($parsed !== null) {
			$found = $parsed->xpath('//*[local-name()="referentienummer"]');
			if (empty($found) === false) {
				$value = trim((string)$found[0]);
				if ($value !== '') {
					return $value;
				}
			}
		}

		return $referenceNumber . '-ack-' . (string)time();
	}//end extractRef()

	/**
	 * Build the per-request `Authorization: <scheme> <token>` header value.
	 *
	 * @param array $sourceConfiguration The `stuf-zkn` source's `configuration` object.
	 *
	 * @return string The Authorization header value.
	 *
	 * @throws StufZknProviderException When the credential is missing or undecryptable.
	 */
	private function buildAuthorizationHeader(array $sourceConfiguration): string {
		$encrypted = (string)($sourceConfiguration['authentication']['encryptedToken'] ?? '');
		if ($encrypted === '') {
			throw new StufZknProviderException(
				message: $this->l->t('StUF-ZKN credential missing')
					. ': `configuration.authentication.encryptedToken` is required. No plaintext-token fallback is permitted.'
			);
		}

		try {
			$token = $this->crypto->decrypt($encrypted);
		} catch (Throwable $exception) {
			throw new StufZknProviderException(
				message: 'The stored StUF-ZKN API token could not be decrypted: ' . $exception->getMessage()
			);
		}

		$scheme = (string)($sourceConfiguration['authentication']['scheme'] ?? self::DEFAULT_AUTH_SCHEME);

		return $scheme . ' ' . $token;
	}//end buildAuthorizationHeader()
}//end class

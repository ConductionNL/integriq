<?php

/**
 * Integriq StUF-ZKN Client.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
	private const DEFAULT_AUTH_SCHEME = 'Bearer';

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
						'mode' => [
							'type' => 'string',
							'enum' => ['token', 'mtls'],
							'default' => 'token',
							'description' => '`token` (default, pre-production fallback) sends a Bearer '
								. 'Authorization header from `encryptedToken`. `mtls` (the expected production '
								. 'mode for a real StUF/PKIoverheid-secured consumer) dispatches over a real '
								. 'mutual-TLS connection using `mtls.*`.',
						],
						'encryptedToken' => [
							'type' => 'string',
							'description' => 'Required when `mode=token`. The StUF consumer API token, encrypted '
								. 'at rest via OCP\\Security\\ICrypto — never store the raw token.',
						],
						'scheme' => [
							'type' => 'string',
							'description' => 'Authorization header scheme, used only when `mode=token`.',
							'default' => self::DEFAULT_AUTH_SCHEME,
						],
						'mtls' => [
							'type' => 'object',
							'description' => 'Required when `mode=mtls`. Client certificate material, each field '
								. 'individually encrypted at rest via OCP\\Security\\ICrypto.',
							'properties' => [
								'encryptedCertificate' => ['type' => 'string', 'description' => 'PEM client certificate.'],
								'encryptedPrivateKey' => ['type' => 'string', 'description' => 'PEM private key.'],
								'encryptedPassphrase' => ['type' => 'string', 'description' => 'Optional private key passphrase.'],
								'encryptedCaBundle' => ['type' => 'string', 'description' => 'Optional PEM CA bundle to verify the peer against.'],
							],
						],
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
		$baseUrl = trim((string)($sourceConfiguration['baseUrl'] ?? ''));
		if ($baseUrl === '') {
			throw new StufZknProviderException(
				message: $this->l->t('StUF-ZKN consumer base URL missing') . ': `configuration.baseUrl` is required.'
			);
		}

		$authConfig = (array)($sourceConfiguration['authentication'] ?? []);
		$useMtls = $this->mtlsConfigResolver->isMtlsConfigured(authConfig: $authConfig);

		$headers = [
			'Content-Type' => 'text/xml; charset=utf-8',
			'SOAPAction' => '""',
			'Accept' => 'text/xml, application/soap+xml',
		];
		if ($useMtls === false) {
			$headers['Authorization'] = $this->buildAuthorizationHeader(sourceConfiguration: $sourceConfiguration);
		}

		$requestOptions = [
			'headers' => $headers,
			'body' => $envelopeXml,
			'http_errors' => false,
		];

		try {
			$response = $this->dispatch(
				useMtls: $useMtls,
				authConfig: $authConfig,
				url: $baseUrl,
				requestOptions: $requestOptions
			);
		} catch (MtlsTransportException $exception) {
			$this->logger->warning(
				'[StufZknClient] mTLS request failed',
				['exception' => $exception->getMessage(), 'errorCode' => $exception->getErrorCode()]
			);
			throw new StufZknProviderException(
				message: 'The StUF-ZKN mTLS request failed (' . $exception->getErrorCode() . '): ' . $exception->getMessage(),
				previous: $exception
			);
		} catch (GuzzleException $exception) {
			$this->logger->warning(
				'[StufZknClient] unexpected transport failure',
				['exception' => $exception->getMessage()]
			);
			throw new StufZknProviderException(
				message: 'The StUF-ZKN request failed unexpectedly: ' . $exception->getMessage(),
				previous: $exception
			);
		}//end try

		$status = $response->getStatusCode();
		$body = (string)$response->getBody();
		if ($status < 200 || $status >= 300) {
			throw new StufZknProviderException(message: 'StUF-ZKN consumer endpoint responded with HTTP ' . $status . '.');
		}

		return $this->extractRef(body: $body, referenceNumber: $referenceNumber);
	}//end send()

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

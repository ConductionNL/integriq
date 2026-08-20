<?php

/**
 * OpenConnector iStandaarden (iWMO/iJW) Client.
 *
 * Thin REST binding for {@see IwmoIjwProviderInterface} against a
 * GGk/VECOZO-fronted iWMO/iJW (StUF iStandaarden Wmo 3.0 / Jeugdwet 3.0)
 * endpoint. Deliberately a hand-rolled HTTP client (Guzzle, already an app
 * dependency) rather than a SOAP/XSD library dependency — mirrors
 * {@see \OCA\OpenConnector\Service\Kiss\KlantinteractiesClient} and
 * {@see \OCA\OpenConnector\Service\Sms\RestNotifyNlProvider}.
 *
 * ASSUMED TRANSPORT SHAPE — no live GGk/VECOZO connection was available to
 * verify against in this environment; every endpoint/header below is an
 * explicit, documented assumption. See design.md "Message-shape
 * assumptions" for the full list:
 * - `POST {baseUrl}/berichten` with the fully rendered envelope XML as the
 *   raw request body (`Content-Type: application/xml`) sends one berichttype
 *   message; the response body is expected to be the transport's assigned
 *   reference as plain text, or a JSON `{ref: "..."}` envelope — both shapes
 *   are accepted (see `extractRef()`).
 * - Auth: `Authorization: Bearer <token>` — a DELIBERATE DEVIATION from the
 *   real GGk/VECOZO transport, which historically uses mutual TLS
 *   client-certificate authentication, not a bearer token. This is flagged
 *   explicitly in design.md "Open Questions" — client-cert support is a
 *   documented gap, not a silent omission. The bearer-token shape mirrors
 *   every other REST binding already in this app so the connector is at
 *   least demonstrable end-to-end against a stand-in endpoint.
 *
 * CREDENTIAL STORAGE: the token is a static bearer-style secret — stored
 * `configuration.authentication.encryptedToken`, ENCRYPTED AT REST via
 * Nextcloud's `OCP\Security\ICrypto`, decrypted in-process only for the
 * instant needed to build each request's Authorization header (never
 * logged, never persisted decrypted). Mirrors
 * `KlantinteractiesClient`/`RestNotifyNlProvider`'s identical, already-
 * accepted deviation from `credentialRef`/`BrokeredCallService` — see
 * design.md "Provider seam, credential storage, feature gating".
 *
 * mTLS: closed by `mtls-client-certificate-transport` — set
 * `configuration.authentication.mode=mtls` (default remains `token`) and
 * populate `configuration.authentication.mtls` (ICrypto-encrypted
 * certificate/key/optional passphrase/optional CA bundle, same at-rest
 * pattern as the token above) to dispatch this GGk/VECOZO-fronted request
 * over a real mutual-TLS connection via {@see
 * \OCA\OpenConnector\Service\Mtls\MtlsTransportService}. Token mode is
 * unchanged.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service\IwmoIjw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/specs/iwmo-ijw-adapter/spec.md#requirement-iwmoijw-provider-abstraction-with-log-and-rest-bindings-req-001
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\IwmoIjw;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OCA\OpenConnector\Exception\IwmoIjwProviderException;
use OCA\OpenConnector\Exception\MtlsTransportException;
use OCA\OpenConnector\Service\Mtls\MtlsConfigResolver;
use OCA\OpenConnector\Service\Mtls\MtlsTransportService;
use OCP\IL10N;
use OCP\Security\ICrypto;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Generic REST iStandaarden provider: token-authenticated envelope dispatch.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/iwmo-ijw-adapter/spec.md#requirement-iwmoijw-provider-abstraction-with-log-and-rest-bindings-req-001
 */
class IStandardsClient implements IwmoIjwProviderInterface {

	/**
	 * Default `Authorization` header scheme.
	 *
	 * @var string
	 */
	private const DEFAULT_AUTH_SCHEME = 'Bearer';

	/**
	 * Default berichten path (relative to `configuration.baseUrl`).
	 *
	 * @var string
	 */
	private const DEFAULT_PATH = '/berichten';

	/**
	 * Constructor.
	 *
	 * @param Client $httpClient Guzzle client (test seam: inject one with a MockHandler stack).
	 * @param ICrypto $crypto Encrypts/decrypts the stored API token at rest.
	 * @param IL10N $l The localization service.
	 * @param LoggerInterface $logger Logger for secret-free failure diagnostics.
	 * @param MtlsConfigResolver $mtlsConfigResolver Resolves `authentication.mtls` into a certificate bundle.
	 * @param MtlsTransportService $mtlsTransport Dispatches the request with a client certificate attached.
	 */
	public function __construct(
		private readonly Client $httpClient,
		private readonly ICrypto $crypto,
		private readonly IL10N $l,
		private readonly LoggerInterface $logger,
		private readonly MtlsConfigResolver $mtlsConfigResolver,
		private readonly MtlsTransportService $mtlsTransport,
	) {

	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The stable `rest` provider identifier.
	 *
	 * @spec openspec/specs/iwmo-ijw-adapter/spec.md#requirement-iwmoijw-provider-abstraction-with-log-and-rest-bindings-req-001
	 */
	public function getProviderId(): string {
		return 'rest';
	}//end getProviderId()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed> The iWMO/iJW source configuration JSON Schema.
	 *
	 * @spec openspec/specs/iwmo-ijw-adapter/spec.md#requirement-iwmoijw-provider-abstraction-with-log-and-rest-bindings-req-001
	 */
	public function getConfigSchema(): array {
		return [
			'type' => 'object',
			'required' => ['authentication'],
			'properties' => [
				'baseUrl' => [
					'type' => 'string',
					'description' => 'iStandaarden (GGk/VECOZO-fronted) endpoint base URL (no trailing slash)',
				],
				'authentication' => [
					'type' => 'object',
					'properties' => [
						'mode' => [
							'type' => 'string',
							'enum' => ['token', 'mtls'],
							'default' => 'token',
							'description' => '`token` (default) sends a Bearer Authorization header from '
								. '`encryptedToken`. `mtls` dispatches over a real mutual-TLS connection using '
								. '`mtls.*` — closes the real GGk/VECOZO transport gap.',
						],
						'encryptedToken' => [
							'type' => 'string',
							'description' => 'Required when `mode=token` (the default). The iStandaarden API token, '
								. 'encrypted at rest via OCP\\Security\\ICrypto — never store the raw token.',
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
				'gemeentecode' => [
					'type' => 'string',
					'description' => 'Sending municipality code (stuurgegevens.zender.code default).',
				],
			],
		];

	}//end getConfigSchema()

	/**
	 * {@inheritDoc}
	 *
	 * @param array $sourceConfiguration The iWMO/iJW source's `configuration` object.
	 * @param string $berichttype The berichtcode being sent (e.g. `Wmo303`).
	 * @param string $envelopeXml The fully rendered envelope XML.
	 *
	 * @return string The extracted reference.
	 *
	 * @spec openspec/specs/iwmo-ijw-adapter/spec.md#scenario-the-rest-provider-sends-the-expected-bearer-auth-header
	 */
	public function send(array $sourceConfiguration, string $berichttype, string $envelopeXml): string {
		$baseUrl = rtrim((string)($sourceConfiguration['baseUrl'] ?? ''), '/');
		if ($baseUrl === '') {
			throw new IwmoIjwProviderException(
				message: $this->l->t('iStandaarden base URL missing') . ': `configuration.baseUrl` is required.'
			);
		}

		$authConfig = (array)($sourceConfiguration['authentication'] ?? []);
		$useMtls = $this->mtlsConfigResolver->isMtlsConfigured(authConfig: $authConfig);

		$headers = [
			'Content-Type' => 'application/xml',
			'Accept' => 'application/xml, application/json',
			'X-Berichttype' => $berichttype,
		];
		if ($useMtls === false) {
			// Token mode (default) — unchanged from before this change.
			$headers['Authorization'] = $this->buildAuthorizationHeader(sourceConfiguration: $sourceConfiguration);
		}

		$requestOptions = [
			'headers' => $headers,
			'body' => $envelopeXml,
			'http_errors' => false,
		];

		$url = $baseUrl . self::DEFAULT_PATH;

		try {
			$response = $this->dispatch(
				useMtls: $useMtls,
				authConfig: $authConfig,
				url: $url,
				requestOptions: $requestOptions
			);
		} catch (MtlsTransportException $exception) {
			$this->logger->warning(
				'[IStandardsClient] mTLS request failed',
				['exception' => $exception->getMessage(), 'errorCode' => $exception->getErrorCode()]
			);
			throw new IwmoIjwProviderException(
				message: 'The iStandaarden mTLS request failed (' . $exception->getErrorCode() . '): ' . $exception->getMessage(),
				previous: $exception
			);
		} catch (GuzzleException $exception) {
			$this->logger->warning(
				'[IStandardsClient] unexpected transport failure',
				['exception' => $exception->getMessage()]
			);
			throw new IwmoIjwProviderException(
				message: 'The iStandaarden request failed unexpectedly: ' . $exception->getMessage(),
				previous: $exception
			);
		}//end try

		$status = $response->getStatusCode();
		$body = (string)$response->getBody();
		if ($status < 200 || $status >= 300) {
			throw new IwmoIjwProviderException(message: 'iStandaarden endpoint responded with HTTP ' . $status . '.');
		}

		return $this->extractRef(body: $body);
	}//end send()

	/**
	 * Dispatch the request over mTLS when configured, else over the existing
	 * token-mode path (unchanged). Never falls back between the two: an mTLS
	 * resolve/handshake failure propagates as {@see MtlsTransportException}.
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
	 * @spec openspec/specs/mtls-client-certificate-transport/spec.md#scenario-istandaardenclient-routes-through-the-mtls-transport-when-configured
	 */
	private function dispatch(bool $useMtls, array $authConfig, string $url, array $requestOptions): ResponseInterface {
		if ($useMtls === true) {
			$bundle = $this->mtlsConfigResolver->resolve(authConfig: $authConfig);
			return $this->mtlsTransport->request($this->httpClient, 'POST', $url, $requestOptions, $bundle);
		}

		return $this->httpClient->request('POST', $url, $requestOptions);
	}//end dispatch()

	/**
	 * Extract a usable reference from the response body — either a bare
	 * plain-text reference, or a JSON `{ref: "..."}`/`{referentienummer:
	 * "..."}` envelope (both shapes accepted, see class docblock).
	 *
	 * @param string $body The raw response body.
	 *
	 * @return string The extracted reference.
	 *
	 * @throws IwmoIjwProviderException When no usable reference is present.
	 */
	private function extractRef(string $body): string {
		$trimmed = trim($body);
		if ($trimmed === '') {
			throw new IwmoIjwProviderException(
				message: 'The iStandaarden endpoint accepted the message but returned an empty response.'
			);
		}

		$decoded = json_decode($trimmed, true);
		if (is_array($decoded) === true) {
			$ref = ($decoded['ref'] ?? ($decoded['referentienummer'] ?? null));
			if (is_string($ref) === true && $ref !== '') {
				return $ref;
			}

			throw new IwmoIjwProviderException(
				message: 'The iStandaarden endpoint returned a JSON response with no usable ref/referentienummer.'
			);
		}

		// Not JSON — treat the whole trimmed body as a bare plain-text reference.
		return $trimmed;
	}//end extractRef()

	/**
	 * Build the per-request `Authorization: <scheme> <token>` header value.
	 *
	 * Decrypts `configuration.authentication.encryptedToken` (never logged,
	 * never persisted decrypted).
	 *
	 * @param array $sourceConfiguration The iWMO/iJW source's `configuration` object.
	 *
	 * @return string The Authorization header value.
	 *
	 * @throws IwmoIjwProviderException When the credential is missing or undecryptable.
	 */
	private function buildAuthorizationHeader(array $sourceConfiguration): string {
		$encrypted = (string)($sourceConfiguration['authentication']['encryptedToken'] ?? '');
		if ($encrypted === '') {
			throw new IwmoIjwProviderException(
				message: $this->l->t('iStandaarden credential missing')
					. ': `configuration.authentication.encryptedToken` is required. No plaintext-token fallback is permitted.'
			);
		}

		try {
			$token = $this->crypto->decrypt($encrypted);
		} catch (Throwable $exception) {
			throw new IwmoIjwProviderException(
				message: 'The stored iStandaarden API token could not be decrypted: ' . $exception->getMessage()
			);
		}

		$scheme = (string)($sourceConfiguration['authentication']['scheme'] ?? self::DEFAULT_AUTH_SCHEME);

		return $scheme . ' ' . $token;
	}//end buildAuthorizationHeader()
}//end class

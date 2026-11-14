<?php

/**
 * Integriq DSO (Digitaal Stelsel Omgevingswet) Client.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Thin REST binding for {@see DsoConnectorProviderInterface} against a
 * DSO-LV-fronted "voortgangsinformatie"/besluit endpoint. Deliberately a
 * hand-rolled HTTP client (Guzzle, already an app dependency) rather than a
 * DSO SDK dependency — mirrors {@see \OCA\Integriq\Service\IwmoIjw\IStandardsClient}
 * and {@see \OCA\Integriq\Service\Kiss\KlantinteractiesClient}.
 *
 * ASSUMED TRANSPORT SHAPE — no live DSO-LV/preprod connection was available
 * to verify against in this environment; every endpoint/header below is an
 * explicit, documented assumption. See design.md "Outbound message-shape
 * assumptions" for the full list:
 * - `POST {baseUrl}/statussen` with `{verzoekId, status, timestamp}` posts a
 *   status (voortgangsinformatie) update; `POST {baseUrl}/besluiten` with
 *   `{verzoekId, besluit, gemotiveerd, timestamp}` posts a besluit. Both
 *   accept a JSON `{ref: "..."}` response envelope, or an empty 2xx body (in
 *   which case a locally-derived reference is returned — see `extractRef()`).
 * - Auth: `Authorization: Bearer <token>` by default — a DELIBERATE
 *   DEVIATION from the real DSO-LV transport, which uses PKIoverheid
 *   client-certificate (mTLS) authentication for production traffic, not a
 *   bearer token (see {@see \OCA\Integriq\Service\DSOSignatureVerifierService},
 *   which already implements the *inbound* PKIoverheid chain-validation
 *   half of this — this class is the *outbound* leg and does not reuse it,
 *   since verifying an inbound signature and presenting an outbound client
 *   certificate are different operations). This is flagged explicitly in
 *   design.md "Open Questions" — mTLS client-certificate support on the
 *   OUTBOUND leg is a documented gap, not a silent omission. The
 *   bearer-token shape mirrors every other REST provider binding already in
 *   this app so the connector is at least demonstrable end-to-end against a
 *   stand-in endpoint, and is the same pre-production fallback shape
 *   `DSOSignatureVerifierService` already supports for the inbound leg
 *   (`MODE_HMAC` vs `MODE_RSA`).
 *
 * CREDENTIAL STORAGE: the token is a static bearer-style secret — stored
 * `configuration.authentication.encryptedToken`, ENCRYPTED AT REST via
 * Nextcloud's `OCP\Security\ICrypto`, decrypted in-process only for the
 * instant needed to build each request's Authorization header (never
 * logged, never persisted decrypted). Mirrors `IStandardsClient`'s
 * identical, already-accepted deviation from `credentialRef`/
 * `BrokeredCallService`.
 *
 * mTLS: closed by `mtls-client-certificate-transport` — set
 * `configuration.authentication.mode=mtls` (default remains `token`) and
 * populate `configuration.authentication.mtls` (ICrypto-encrypted
 * certificate/key/optional passphrase/optional CA bundle, same at-rest
 * pattern as the token above) to dispatch this OUTBOUND leg over a real
 * PKIoverheid mutual-TLS connection via {@see
 * \OCA\Integriq\Service\Mtls\MtlsTransportService}. This is
 * independent of `DSOSignatureVerifierService`'s INBOUND signature
 * verification. Token mode is unchanged.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Dso
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-dso-outbound-provider-abstraction-with-log-and-rest-bindings-req-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Dso;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OCA\Integriq\Exception\DsoProviderException;
use OCA\Integriq\Exception\MtlsTransportException;
use OCA\Integriq\Service\PayloadTooLargeException;
use OCA\Integriq\Service\Mtls\ClientAuthenticationSchema;
use OCA\Integriq\Service\Mtls\MtlsConfigResolver;
use OCA\Integriq\Service\Mtls\MtlsTransportService;
use OCP\IL10N;
use OCP\Security\ICrypto;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Generic REST DSO outbound provider: token-authenticated status/besluit dispatch.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-dso-outbound-provider-abstraction-with-log-and-rest-bindings-req-001
 */
class DsoClient implements DsoConnectorProviderInterface {

	/**
	 * Default `Authorization` header scheme.
	 *
	 * @var string
	 */
	private const DEFAULT_AUTH_SCHEME = ClientAuthenticationSchema::DEFAULT_SCHEME;

	/**
	 * Outbound message-kind to endpoint-path mapping (relative to `configuration.baseUrl`).
	 *
	 * @var array<string, string>
	 */
	private const PATHS = [
		'status' => '/statussen',
		'besluit' => '/besluiten',
	];

	/**
	 * Bytes read per chunk while a bijlage body is streamed.
	 *
	 * @var integer
	 */
	private const DOWNLOAD_CHUNK_BYTES = 8192;

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
	 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-dso-outbound-provider-abstraction-with-log-and-rest-bindings-req-001
	 */
	public function getProviderId(): string {
		return 'rest';
	}//end getProviderId()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed> The DSO source configuration JSON Schema.
	 *
	 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-dso-outbound-provider-abstraction-with-log-and-rest-bindings-req-001
	 */
	public function getConfigSchema(): array {
		return [
			'type' => 'object',
			'required' => ['authentication'],
			'properties' => [
				'baseUrl' => [
					'type' => 'string',
					'description' => 'DSO-LV outbound (voortgangsinformatie/besluit) endpoint base URL (no trailing slash)',
				],
				'authentication' => ClientAuthenticationSchema::tokenOrMtls(
					modeDescription: '`token` (default) sends a Bearer Authorization header from '
						. '`encryptedToken`. `mtls` dispatches over a real PKIoverheid mutual-TLS connection '
						. 'using `mtls.*` — closes the real DSO-LV outbound transport gap.',
					tokenDescription: 'Required when `mode=token` (the default). The DSO-LV API token, '
						. 'encrypted at rest via OCP\\Security\\ICrypto — never store the raw token.'
				),
				'bronorganisatie' => [
					'type' => 'string',
					'description' => 'This bevoegd gezag\'s OIN/bronorganisatie code (outbound stuurgegevens default).',
				],
				'maxFileSize' => [
					'type' => 'integer',
					'description' => 'Largest bijlage download in bytes. Larger bijlagen are not stored. Default 104857600 (100 MB).',
				],
			],
		];

	}//end getConfigSchema()

	/**
	 * {@inheritDoc}
	 *
	 * @param array $sourceConfiguration The `dso` source's `configuration` object.
	 * @param string $requestId The DSO `verzoekId` this message concerns.
	 * @param string $type The message kind: `status` or `besluit`.
	 * @param array $payload The already-built message payload.
	 *
	 * @return string The extracted (or locally-derived) reference.
	 *
	 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#scenario-the-rest-provider-sends-the-expected-bearer-auth-header
	 */
	public function send(array $sourceConfiguration, string $requestId, string $type, array $payload): string {
		$url = $this->messageUrl(sourceConfiguration: $sourceConfiguration, type: $type);

		$request = $this->authenticatedRequest(
			sourceConfiguration: $sourceConfiguration,
			headers: [
				'Content-Type' => 'application/json',
				'Accept' => 'application/json',
			]
		);
		$request->url = $url;
		$request->options = ['json' => $payload, 'http_errors' => false];

		$body = $this->messageBody(response: $this->postMessage(request: $request));

		return $this->extractRef(body: $body, fallbackPrefix: $requestId . '-' . $type);
	}//end send()

	/**
	 * Start a request carrying the source's own authentication: the Bearer
	 * header in token mode (the default), nothing extra in mTLS mode, where
	 * the client certificate authenticates.
	 *
	 * @param array                 $sourceConfiguration The `dso` source's `configuration` object.
	 * @param array<string, string> $headers             The request's other headers.
	 *
	 * @return DsoTransportRequest The request, without URL or options yet.
	 *
	 * @throws DsoProviderException When token mode has no usable credential.
	 *
	 * @spec openspec/specs/mtls-client-certificate-transport/spec.md#scenario-token-mode-is-unchanged-when-mtls-is-not-configured
	 */
	private function authenticatedRequest(array $sourceConfiguration, array $headers): DsoTransportRequest {
		$authConfig = (array)($sourceConfiguration['authentication'] ?? []);
		$request = new DsoTransportRequest(
			authConfig: $authConfig,
			useMtls: $this->mtlsConfigResolver->isMtlsConfigured(authConfig: $authConfig)
		);

		if ($request->useMtls === false) {
			$headers['Authorization'] = $this->buildAuthorizationHeader(sourceConfiguration: $sourceConfiguration);
		}

		$request->headers = $headers;

		return $request;
	}//end authenticatedRequest()

	/**
	 * The body of a message answer, refusing an answer outside 2xx.
	 *
	 * @param ResponseInterface $response The answer.
	 *
	 * @return string The raw body.
	 *
	 * @throws DsoProviderException When DSO-LV answered non-2xx.
	 */
	private function messageBody(ResponseInterface $response): string {
		$status = $response->getStatusCode();
		$body = (string)$response->getBody();
		if ($status < 200 || $status >= 300) {
			throw new DsoProviderException(message: 'DSO-LV endpoint responded with HTTP ' . $status . '.');
		}

		return $body;
	}//end messageBody()

	/**
	 * The URL an outbound message of this type is posted to.
	 *
	 * @param array  $sourceConfiguration The `dso` source's `configuration` object.
	 * @param string $type                The message kind: `status` or `besluit`.
	 *
	 * @return string The URL.
	 *
	 * @throws DsoProviderException When the base URL is missing or the type is unknown.
	 */
	private function messageUrl(array $sourceConfiguration, string $type): string {
		$baseUrl = rtrim((string)($sourceConfiguration['baseUrl'] ?? ''), '/');
		if ($baseUrl === '') {
			throw new DsoProviderException(
				message: $this->l->t('DSO base URL missing') . ': `configuration.baseUrl` is required.'
			);
		}

		$path = (self::PATHS[$type] ?? null);
		if ($path === null) {
			throw new DsoProviderException(
				message: 'Unknown DSO outbound message type "' . $type . '" (must be `status` or `besluit`).'
			);
		}

		return $baseUrl . $path;
	}//end messageUrl()

	/**
	 * Post one outbound message, turning a transport failure into a DsoProviderException.
	 *
	 * @param DsoTransportRequest $request The prepared request.
	 *
	 * @return ResponseInterface The response, whatever its status.
	 *
	 * @throws DsoProviderException When the mTLS or HTTP transport fails.
	 */
	private function postMessage(DsoTransportRequest $request): ResponseInterface {
		try {
			return $this->dispatch(request: $request);
		} catch (MtlsTransportException $exception) {
			throw $this->messageMtlsFailure(exception: $exception);
		} catch (GuzzleException $exception) {
			throw $this->messageTransportFailure(exception: $exception);
		}
	}//end postMessage()

	/**
	 * Log a failed mTLS message post and describe it as a provider failure.
	 *
	 * @param MtlsTransportException $exception The transport failure.
	 *
	 * @return DsoProviderException The failure to raise.
	 */
	private function messageMtlsFailure(MtlsTransportException $exception): DsoProviderException {
		$this->logger->warning(
			'[DsoClient] mTLS request failed',
			['exception' => $exception->getMessage(), 'errorCode' => $exception->getErrorCode()]
		);

		return new DsoProviderException(
			message: 'The DSO-LV mTLS request failed (' . $exception->getErrorCode() . '): ' . $exception->getMessage(),
			previous: $exception
		);
	}//end messageMtlsFailure()

	/**
	 * Log a failed message post and describe it as a provider failure.
	 *
	 * @param GuzzleException $exception The transport failure.
	 *
	 * @return DsoProviderException The failure to raise.
	 */
	private function messageTransportFailure(GuzzleException $exception): DsoProviderException {
		$this->logger->warning(
			'[DsoClient] unexpected transport failure',
			['exception' => $exception->getMessage()]
		);

		return new DsoProviderException(
			message: 'The DSO-LV request failed unexpectedly: ' . $exception->getMessage(),
			previous: $exception
		);
	}//end messageTransportFailure()

	/**
	 * Download one bijlage from DSO-LV with the source's own authentication:
	 * a Bearer token in token mode, the client certificate in mTLS mode.
	 *
	 * The body is streamed into a temporary stream, never held in memory as
	 * one string. A declared Content-Length above `$maxBytes`, or a body that
	 * grows past it, stops the download.
	 *
	 * @param array $sourceConfiguration The `dso` source's `configuration` object.
	 * @param string $url The absolute bijlage URL.
	 * @param integer $maxBytes The largest body accepted, in bytes.
	 *
	 * @return resource A readable stream positioned at the start of the body.
	 *
	 * @throws PayloadTooLargeException When the bijlage is larger than `$maxBytes`.
	 * @throws DsoProviderException When the credential is unusable, the transport fails or DSO-LV answers non-2xx.
	 *
	 * @spec openspec/changes/dso-attachments-on-the-request/specs/dso-omgevingsloket/spec.md#scenario-multiple-bijlagen-downloaded-and-linked
	 */
	public function download(array $sourceConfiguration, string $url, int $maxBytes) {
		$request = $this->authenticatedRequest(sourceConfiguration: $sourceConfiguration, headers: ['Accept' => '*/*']);
		$request->url = $url;
		$request->options = ['http_errors' => false, 'stream' => true];
		$request->method = 'GET';

		$response = $this->fetchDownload(request: $request);
		$this->assertDownloadAccepted(response: $response, maxBytes: $maxBytes);

		return $this->copyCapped(body: $response->getBody(), maxBytes: $maxBytes);
	}//end download()

	/**
	 * Send the download request, turning a transport failure into a DsoProviderException.
	 *
	 * @param DsoTransportRequest $request The prepared GET.
	 *
	 * @return ResponseInterface The response, whatever its status.
	 *
	 * @throws DsoProviderException When the mTLS or HTTP transport fails.
	 */
	private function fetchDownload(DsoTransportRequest $request): ResponseInterface {
		try {
			return $this->dispatch(request: $request);
		} catch (MtlsTransportException $exception) {
			throw new DsoProviderException(
				message: 'The DSO-LV mTLS download failed (' . $exception->getErrorCode() . '): ' . $exception->getMessage(),
				previous: $exception
			);
		} catch (GuzzleException $exception) {
			throw new DsoProviderException(
				message: 'The DSO-LV download failed: ' . $exception->getMessage(),
				previous: $exception
			);
		}
	}//end fetchDownload()

	/**
	 * Refuse a download answered outside 2xx or declared larger than the cap,
	 * closing its body.
	 *
	 * @param ResponseInterface $response The answer.
	 * @param integer           $maxBytes The largest body accepted, in bytes.
	 *
	 * @return void
	 *
	 * @throws PayloadTooLargeException When the declared length is larger than `$maxBytes`.
	 * @throws DsoProviderException When DSO-LV answered non-2xx.
	 */
	private function assertDownloadAccepted(ResponseInterface $response, int $maxBytes): void {
		$status = $response->getStatusCode();
		$body = $response->getBody();
		if ($status < 200 || $status >= 300) {
			$body->close();
			throw new DsoProviderException(message: 'DSO-LV answered the download with HTTP ' . $status . '.');
		}

		$declared = $response->getHeaderLine('Content-Length');
		if (ctype_digit($declared) === true && (int)$declared > $maxBytes) {
			$body->close();
			throw new PayloadTooLargeException(
				message: 'The bijlage is ' . $declared . ' bytes, more than the maximum of ' . $maxBytes . ' bytes.'
			);
		}
	}//end assertDownloadAccepted()

	/**
	 * Copy a response body into a temporary stream, chunk by chunk, and stop
	 * as soon as it grows past `$maxBytes`.
	 *
	 * @param StreamInterface $body The response body.
	 * @param integer $maxBytes The largest body accepted, in bytes.
	 *
	 * @return resource A readable stream positioned at the start of the copy.
	 *
	 * @throws PayloadTooLargeException When the body is larger than `$maxBytes`.
	 * @throws DsoProviderException When no temporary stream can be opened.
	 */
	private function copyCapped(StreamInterface $body, int $maxBytes) {
		$target = fopen('php://temp', 'w+b');
		if ($target === false) {
			$body->close();
			throw new DsoProviderException(message: 'No temporary stream could be opened for the download.');
		}

		$received = 0;
		while ($body->eof() === false) {
			$chunk = $body->read(self::DOWNLOAD_CHUNK_BYTES);
			$received += strlen($chunk);
			if ($received > $maxBytes) {
				$body->close();
				fclose($target);
				throw new PayloadTooLargeException(
					message: 'The bijlage is more than the maximum of ' . $maxBytes . ' bytes.'
				);
			}

			fwrite($target, $chunk);
		}

		$body->close();
		rewind($target);

		return $target;
	}//end copyCapped()

	/**
	 * Dispatch the request over mTLS when configured, else over the existing
	 * token-mode path (unchanged). Never falls back between the two: an mTLS
	 * resolve/handshake failure propagates as {@see MtlsTransportException}.
	 *
	 * @param DsoTransportRequest $request The prepared request.
	 *
	 * @return ResponseInterface The Guzzle response.
	 *
	 * @throws MtlsTransportException When mTLS is configured but the material is unusable or the handshake fails.
	 * @throws GuzzleException When the token-mode dispatch fails.
	 *
	 * @spec openspec/specs/mtls-client-certificate-transport/spec.md#scenario-dsoclient-routes-through-the-mtls-transport-when-configured
	 */
	private function dispatch(DsoTransportRequest $request): ResponseInterface {
		if ($request->useMtls === true) {
			$bundle = $this->mtlsConfigResolver->resolve(authConfig: $request->authConfig);
			return $this->mtlsTransport->request(
				$this->httpClient,
				$request->method,
				$request->url,
				$request->guzzleOptions(),
				$bundle
			);
		}

		return $this->httpClient->request($request->method, $request->url, $request->guzzleOptions());
	}//end dispatch()

	/**
	 * Extract a usable reference from the response body — a JSON `{ref:
	 * "..."}` envelope when present, otherwise a locally-derived reference
	 * (the endpoint accepted the message but assigned no ref of its own).
	 *
	 * @param string $body           The raw response body.
	 * @param string $fallbackPrefix The DSO `verzoekId` and message kind (`status`|`besluit`) as `<verzoekId>-<kind>`.
	 *
	 * @return string The extracted (or locally-derived) reference.
	 */
	private function extractRef(string $body, string $fallbackPrefix): string {
		$trimmed = trim($body);
		if ($trimmed !== '') {
			$decoded = json_decode($trimmed, true);
			if (is_array($decoded) === true) {
				$ref = ($decoded['ref'] ?? null);
				if (is_string($ref) === true && $ref !== '') {
					return $ref;
				}
			}
		}

		// No transport-assigned ref — derive a locally-unique one so the
		// caller still has something to persist/correlate on.
		return $fallbackPrefix . '-' . (string)time();
	}//end extractRef()

	/**
	 * Build the per-request `Authorization: <scheme> <token>` header value.
	 *
	 * Decrypts `configuration.authentication.encryptedToken` (never logged,
	 * never persisted decrypted).
	 *
	 * @param array $sourceConfiguration The `dso` source's `configuration` object.
	 *
	 * @return string The Authorization header value.
	 *
	 * @throws DsoProviderException When the credential is missing or undecryptable.
	 */
	private function buildAuthorizationHeader(array $sourceConfiguration): string {
		$encrypted = (string)($sourceConfiguration['authentication']['encryptedToken'] ?? '');
		if ($encrypted === '') {
			throw new DsoProviderException(
				message: $this->l->t('DSO credential missing')
					. ': `configuration.authentication.encryptedToken` is required. No plaintext-token fallback is permitted.'
			);
		}

		try {
			$token = $this->crypto->decrypt($encrypted);
		} catch (Throwable $exception) {
			throw new DsoProviderException(
				message: 'The stored DSO API token could not be decrypted: ' . $exception->getMessage()
			);
		}

		$scheme = (string)($sourceConfiguration['authentication']['scheme'] ?? self::DEFAULT_AUTH_SCHEME);

		return $scheme . ' ' . $token;
	}//end buildAuthorizationHeader()
}//end class

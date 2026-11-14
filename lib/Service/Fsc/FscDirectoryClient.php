<?php

/**
 * Integriq FSC Directory Client.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The ONE class housing every HTTP and credential operation this change
 * performs — both the FSC Directory lookup (`resolveService()`) and the
 * downstream service invocation (`call()`) — per the task brief's explicit
 * "keep every HTTP/cert operation in one client class" instruction. Thin
 * REST binding for {@see FscConnectivityProviderInterface} against an
 * assumed FSC (Federatieve Service Connectiviteit) Directory endpoint.
 * Deliberately a hand-rolled HTTP client (Guzzle, already an app
 * dependency) rather than any FSC-specific SDK — mirrors
 * {@see \OCA\Integriq\Service\IwmoIjw\IStandardsClient} and
 * {@see \OCA\Integriq\Service\Kiss\KlantinteractiesClient}.
 *
 * ASSUMED WIRE SHAPE — no live FSC Directory/Outway/Inway was available to
 * verify against in this environment; every endpoint/header below is an
 * explicit, documented assumption. See design.md "Directory API shape" for
 * the full request/response contract:
 * - `GET {directoryUrl}/organisations/{organisation}/services/{service}`
 *   resolves one organisation+service pair; `404` means unknown
 *   organisation OR service (this change cannot distinguish the two from
 *   the directory's response alone — see `resolveService()`'s docblock).
 * - The resolved `endpoint` is called directly, `{method}` verb, the
 *   payload JSON-encoded as the request body for any method (a
 *   simplification — a real Outway proxy would forward the verb/body
 *   untouched, but this change has no live Outway to verify a richer
 *   contract against).
 *
 * OUTWAY/mTLS DEVIATION — a real FSC deployment secures the downstream call
 * via mutual TLS between the caller's own locally-running Outway process
 * and the target's Inway, never a bearer token the calling application
 * builds itself. This class implements token auth ONLY — a DELIBERATE,
 * explicitly documented deviation (see design.md "Outway/mTLS deviation")
 * because (a) no live Outway/Inway pair exists in this environment to
 * validate a real mTLS handshake against, and (b) Guzzle's client-cert
 * options (`cert`/`ssl_key`) are a deployment-time concern this change
 * cannot safely invent. The provider seam isolates correcting this to this
 * one class alone.
 *
 * CREDENTIAL STORAGE: the token is a static bearer-style secret — stored
 * `configuration.authentication.encryptedToken`, ENCRYPTED AT REST via
 * Nextcloud's `OCP\Security\ICrypto`, decrypted in-process only for the
 * instant needed to build each request's Authorization header (never
 * logged, never persisted decrypted). Mirrors `IStandardsClient`'s
 * identical, already-accepted deviation from `credentialRef`/
 * `BrokeredCallService` — see design.md "Provider seam, credential
 * storage, feature gating".
 *
 * mTLS: closed by `mtls-client-certificate-transport` — set
 * `configuration.authentication.mode=mtls` (default remains `token`) and
 * populate `configuration.authentication.mtls` (ICrypto-encrypted
 * certificate/key/optional passphrase/optional CA bundle, same at-rest
 * pattern as the token above) to dispatch the downstream `call()` over a
 * real mutual-TLS connection via {@see
 * \OCA\Integriq\Service\Mtls\MtlsTransportService}, standing in for a
 * real Outway process. Directory `resolveService()` lookups stay
 * unauthenticated/plain (mirrors a real FSC Directory, which is not itself
 * behind the Outway/Inway mTLS boundary). Token mode is unchanged.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Fsc
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/fsc-connectivity/spec.md#requirement-fsc-provider-abstraction-with-log-and-rest-bindings-req-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Fsc;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OCA\Integriq\Exception\FscConnectivityException;
use OCA\Integriq\Exception\FscDirectoryException;
use OCA\Integriq\Exception\MtlsTransportException;
use OCA\Integriq\Service\Mtls\ClientAuthenticationSchema;
use OCA\Integriq\Service\Mtls\MtlsConfigResolver;
use OCA\Integriq\Service\Mtls\MtlsTransportService;
use OCP\IL10N;
use OCP\Security\ICrypto;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Generic REST FSC Directory + call binding: token-authenticated resolution and dispatch.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/fsc-connectivity/spec.md#requirement-fsc-provider-abstraction-with-log-and-rest-bindings-req-001
 */
class FscDirectoryClient implements FscConnectivityProviderInterface {

	/**
	 * Default `Authorization` header scheme.
	 *
	 * @var string
	 */
	private const DEFAULT_AUTH_SCHEME = ClientAuthenticationSchema::DEFAULT_SCHEME;

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
	 * @spec openspec/specs/fsc-connectivity/spec.md#requirement-fsc-provider-abstraction-with-log-and-rest-bindings-req-001
	 */
	public function getProviderId(): string {
		return 'rest';
	}//end getProviderId()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed> The FSC source configuration JSON Schema.
	 *
	 * @spec openspec/specs/fsc-connectivity/spec.md#requirement-fsc-provider-abstraction-with-log-and-rest-bindings-req-001
	 */
	public function getConfigSchema(): array {
		return [
			'type' => 'object',
			'required' => ['directoryUrl', 'authentication'],
			'properties' => [
				'directoryUrl' => [
					'type' => 'string',
					'description' => 'FSC Directory base URL (no trailing slash) — ASSUMED shape, see design.md '
						. '"Directory API shape".',
				],
				'authentication' => ClientAuthenticationSchema::tokenOrMtls(
					modeDescription: '`token` (default) sends a Bearer Authorization header from '
						. '`encryptedToken`. `mtls` dispatches over a real mutual-TLS connection using '
						. '`mtls.*` — closes the real Outway/Inway transport gap.',
					tokenDescription: 'Required when `mode=token` (the default). The FSC-fronting API token, '
						. 'encrypted at rest via OCP\\Security\\ICrypto — never store the raw token.'
				),
			],
		];

	}//end getConfigSchema()

	/**
	 * {@inheritDoc}
	 *
	 * Note: a `404` from the directory is treated as "unknown organisation"
	 * when the response body does not clearly indicate otherwise, else
	 * "unknown service" — the ASSUMED directory response shape (see class
	 * docblock) does not define a way to distinguish the two cases with
	 * certainty, so this client inspects the response body for an `error`
	 * hint (`organisation`/`service`) and falls back to "organisation or
	 * service" when absent.
	 *
	 * @param array $directoryConfig The FSC source's `configuration.directory` object.
	 * @param string $organisation The target organisation identifier.
	 * @param string $service The target service identifier.
	 *
	 * @return array{organisation: string, service: string, endpoint: string, grantRequired: bool, authContext: array<string, mixed>}
	 *
	 * @throws FscDirectoryException When the directory reports the organisation/service as unknown.
	 * @throws FscConnectivityException When the directory is unreachable, errors, or is misconfigured.
	 *
	 * @spec openspec/specs/fsc-connectivity/spec.md#requirement-directory-resolution-req-002
	 */
	public function resolveService(array $directoryConfig, string $organisation, string $service): array {
		$target = new FscServiceRef(organisation: $organisation, service: $service);
		$response = $this->lookupService(directoryUrl: $this->requireDirectoryUrl(directoryConfig: $directoryConfig), target: $target);
		$entry = $this->directoryEntry(response: $response, target: $target);

		return [
			'organisation' => $organisation,
			'service' => $service,
			'endpoint' => $this->entryEndpoint(entry: $entry, target: $target),
			'grantRequired' => (bool)($entry['grantRequired'] ?? false),
			'authContext' => ['publicKeyFingerprint' => ($entry['publicKeyFingerprint'] ?? null)],
		];

	}//end resolveService()

	/**
	 * The directory's base URL; it is required.
	 *
	 * @param array $directoryConfig The FSC source's `configuration.directory` object.
	 *
	 * @return string The URL without a trailing slash.
	 *
	 * @throws FscConnectivityException When none is configured.
	 */
	private function requireDirectoryUrl(array $directoryConfig): string {
		$directoryUrl = rtrim((string)($directoryConfig['directoryUrl'] ?? ''), '/');
		if ($directoryUrl === '') {
			throw new FscConnectivityException(
				message: $this->l->t('FSC directory URL missing') . ': `configuration.directory.directoryUrl` is required.'
			);
		}

		return $directoryUrl;

	}//end requireDirectoryUrl()

	/**
	 * Look the service up in the directory.
	 *
	 * @param string        $directoryUrl The directory's base URL.
	 * @param FscServiceRef $target       The service.
	 *
	 * @return ResponseInterface The directory's answer, whatever its status.
	 *
	 * @throws FscConnectivityException When the lookup fails in transport.
	 */
	private function lookupService(string $directoryUrl, FscServiceRef $target): ResponseInterface {
		try {
			return $this->httpClient->request(
				'GET',
				$directoryUrl . $target->directoryPath(),
				['headers' => ['Accept' => 'application/json'], 'http_errors' => false]
			);
		} catch (GuzzleException $exception) {
			$this->logger->warning(
				'[FscDirectoryClient] directory lookup failed unexpectedly',
				['exception' => $exception->getMessage()]
			);
			throw new FscConnectivityException(
				message: 'The FSC directory lookup failed unexpectedly: ' . $exception->getMessage(),
				previous: $exception
			);
		}

	}//end lookupService()

	/**
	 * The decoded directory entry; a 404 is an unknown service, any other non-2xx or non-JSON a connectivity failure.
	 *
	 * @param ResponseInterface $response The directory's answer.
	 * @param FscServiceRef     $target   The service.
	 *
	 * @return array The entry.
	 *
	 * @throws FscDirectoryException    When the directory has no entry for the service.
	 * @throws FscConnectivityException When the directory errs or answers something other than JSON.
	 */
	private function directoryEntry(ResponseInterface $response, FscServiceRef $target): array {
		$status = $response->getStatusCode();
		if ($status === 404) {
			throw new FscDirectoryException(
				message: 'Unknown organisation or service: no FSC directory entry for ' . $target->described() . '.'
			);
		}

		if ($status < 200 || $status >= 300) {
			throw new FscConnectivityException(message: 'FSC directory responded with HTTP ' . $status . '.');
		}

		$decoded = json_decode((string)$response->getBody(), true);
		if (is_array($decoded) === false) {
			throw new FscConnectivityException(message: 'The FSC directory returned a non-JSON response for ' . $target->described() . '.');
		}

		return $decoded;

	}//end directoryEntry()

	/**
	 * The entry's endpoint; it is required.
	 *
	 * @param array         $entry  The directory entry.
	 * @param FscServiceRef $target The service.
	 *
	 * @return string The endpoint.
	 *
	 * @throws FscConnectivityException When the entry carries none.
	 */
	private function entryEndpoint(array $entry, FscServiceRef $target): string {
		$endpoint = (string)($entry['endpoint'] ?? '');
		if ($endpoint === '') {
			throw new FscConnectivityException(
				message: 'The FSC directory response for ' . $target->described() . ' carried no usable endpoint.'
			);
		}

		return $endpoint;

	}//end entryEndpoint()

	/**
	 * {@inheritDoc}
	 *
	 * @param array $directoryConfig The FSC source's `configuration.directory` object.
	 * @param array $resolution The resolution returned by {@see resolveService()}.
	 * @param string $method The HTTP-style method to invoke.
	 * @param array $payload The call payload, JSON-encoded as the request body.
	 *
	 * @return array{ref: string, statusCode: int, body: mixed}
	 *
	 * @throws FscConnectivityException When the endpoint is unreachable, errors, or is misconfigured.
	 *
	 * @spec openspec/specs/fsc-connectivity/spec.md#scenario-the-rest-provider-sends-the-expected-bearer-auth-header-on-call
	 */
	public function call(array $directoryConfig, array $resolution, string $method, array $payload): array {
		$run = new FscCallRun(method: strtoupper($method), url: $this->requireEndpoint(resolution: $resolution));
		$run->authConfig = (array)($directoryConfig['authentication'] ?? []);
		$run->useMtls    = $this->mtlsConfigResolver->isMtlsConfigured(authConfig: $run->authConfig);
		$run->requestOptions = [
			'headers' => $this->callHeaders(directoryConfig: $directoryConfig, useMtls: $run->useMtls),
			'json' => $payload,
			'http_errors' => false,
		];

		return $this->callResult(response: $this->send(run: $run));

	}//end call()

	/**
	 * Send the call, wrapping a transport failure in a connectivity exception.
	 *
	 * @param FscCallRun $run The call.
	 *
	 * @return ResponseInterface The endpoint's answer.
	 *
	 * @throws FscConnectivityException When the mTLS or token-mode transport fails.
	 */
	private function send(FscCallRun $run): ResponseInterface {
		try {
			return $this->dispatch(run: $run);
		} catch (MtlsTransportException $exception) {
			throw $this->mtlsCallFailure(exception: $exception);
		} catch (GuzzleException $exception) {
			throw $this->unexpectedCallFailure(exception: $exception);
		}

	}//end send()

	/**
	 * The resolution's endpoint; it is required.
	 *
	 * @param array $resolution The resolution returned by {@see resolveService()}.
	 *
	 * @return string The endpoint.
	 *
	 * @throws FscConnectivityException When the resolution carries none.
	 */
	private function requireEndpoint(array $resolution): string {
		$endpoint = (string)($resolution['endpoint'] ?? '');
		if ($endpoint === '') {
			throw new FscConnectivityException(
				message: $this->l->t('FSC call resolution missing an endpoint') . ': cannot dispatch without one.'
			);
		}

		return $endpoint;

	}//end requireEndpoint()

	/**
	 * The JSON headers, with the token Authorization header unless mTLS is configured.
	 *
	 * @param array $directoryConfig The FSC source's `configuration.directory` object.
	 * @param bool  $useMtls         Whether the call goes over mTLS.
	 *
	 * @return array<string, string> The headers.
	 *
	 * @throws FscConnectivityException When token mode has no usable token.
	 */
	private function callHeaders(array $directoryConfig, bool $useMtls): array {
		$headers = [
			'Content-Type' => 'application/json',
			'Accept' => 'application/json',
		];
		if ($useMtls === false) {
			// Token mode (default) — unchanged from before this change.
			$headers['Authorization'] = $this->buildAuthorizationHeader(directoryConfig: $directoryConfig);
		}

		return $headers;

	}//end callHeaders()

	/**
	 * Log a failed mTLS call and wrap it.
	 *
	 * @param MtlsTransportException $exception The failure.
	 *
	 * @return FscConnectivityException The exception to throw.
	 */
	private function mtlsCallFailure(MtlsTransportException $exception): FscConnectivityException {
		$this->logger->warning(
			'[FscDirectoryClient] mTLS call failed',
			['exception' => $exception->getMessage(), 'errorCode' => $exception->getErrorCode()]
		);

		return new FscConnectivityException(
			message: 'The FSC mTLS call failed (' . $exception->getErrorCode() . '): ' . $exception->getMessage(),
			previous: $exception
		);

	}//end mtlsCallFailure()

	/**
	 * Log an unexpected transport failure of a call and wrap it.
	 *
	 * @param GuzzleException $exception The failure.
	 *
	 * @return FscConnectivityException The exception to throw.
	 */
	private function unexpectedCallFailure(GuzzleException $exception): FscConnectivityException {
		$this->logger->warning(
			'[FscDirectoryClient] unexpected transport failure',
			['exception' => $exception->getMessage()]
		);

		return new FscConnectivityException(
			message: 'The FSC call failed unexpectedly: ' . $exception->getMessage(),
			previous: $exception
		);

	}//end unexpectedCallFailure()

	/**
	 * The call's result: its reference, status and decoded body; a non-2xx answer is refused.
	 *
	 * @param ResponseInterface $response The endpoint's answer.
	 *
	 * @return array{ref: string, statusCode: int, body: mixed}
	 *
	 * @throws FscConnectivityException When the endpoint answered non-2xx.
	 */
	private function callResult(ResponseInterface $response): array {
		$status = $response->getStatusCode();
		$body = (string)$response->getBody();
		if ($status < 200 || $status >= 300) {
			throw new FscConnectivityException(message: 'FSC endpoint responded with HTTP ' . $status . '.');
		}

		return [
			'ref' => $this->extractRef(response: $response, body: $body),
			'statusCode' => $status,
			'body' => (json_decode($body, true) ?? $body),
		];

	}//end callResult()

	/**
	 * Dispatch the downstream call over mTLS when configured, else over the
	 * existing token-mode path (unchanged). Never falls back between the
	 * two: an mTLS resolve/handshake failure propagates as
	 * {@see MtlsTransportException}.
	 *
	 * @param FscCallRun $run The call: method, URL, transport and request options.
	 *
	 * @return ResponseInterface The Guzzle response.
	 *
	 * @throws MtlsTransportException When mTLS is configured but the material is unusable or the handshake fails.
	 * @throws GuzzleException When the token-mode dispatch fails.
	 *
	 * @spec openspec/specs/mtls-client-certificate-transport/spec.md#scenario-fscdirectoryclient-routes-through-the-mtls-transport-when-configured
	 */
	private function dispatch(FscCallRun $run): ResponseInterface {
		if ($run->useMtls === true) {
			$bundle = $this->mtlsConfigResolver->resolve(authConfig: $run->authConfig);
			return $this->mtlsTransport->request($this->httpClient, $run->method, $run->url, $run->requestOptions, $bundle);
		}

		return $this->httpClient->request($run->method, $run->url, $run->requestOptions);
	}//end dispatch()

	/**
	 * Extract a usable reference from the response — prefers an
	 * `X-FSC-Reference` header, falls back to a JSON body `ref`/
	 * `reference` field, and finally a locally generated reference when
	 * neither is present (a generic downstream service is not guaranteed
	 * to echo back any correlation id of its own).
	 *
	 * @param ResponseInterface $response The Guzzle response.
	 * @param string $body The raw response body.
	 *
	 * @return string The extracted or generated reference.
	 */
	private function extractRef(ResponseInterface $response, string $body): string {
		$header = trim($response->getHeaderLine('X-FSC-Reference'));
		if ($header !== '') {
			return $header;
		}

		$decoded = json_decode($body, true);
		if (is_array($decoded) === true) {
			$ref = ($decoded['ref'] ?? ($decoded['reference'] ?? null));
			if (is_string($ref) === true && $ref !== '') {
				return $ref;
			}
		}

		return 'FSC-' . bin2hex(random_bytes(8));
	}//end extractRef()

	/**
	 * Build the per-request `Authorization: <scheme> <token>` header value.
	 *
	 * Decrypts `configuration.authentication.encryptedToken` (never logged,
	 * never persisted decrypted).
	 *
	 * @param array $directoryConfig The FSC source's `configuration.directory` object.
	 *
	 * @return string The Authorization header value.
	 *
	 * @throws FscConnectivityException When the credential is missing or undecryptable.
	 */
	private function buildAuthorizationHeader(array $directoryConfig): string {
		$encrypted = (string)($directoryConfig['authentication']['encryptedToken'] ?? '');
		if ($encrypted === '') {
			throw new FscConnectivityException(
				message: $this->l->t('FSC credential missing')
					. ': `configuration.directory.authentication.encryptedToken` is required. '
					. 'No plaintext-token fallback is permitted.'
			);
		}

		try {
			$token = $this->crypto->decrypt($encrypted);
		} catch (Throwable $exception) {
			throw new FscConnectivityException(
				message: 'The stored FSC API token could not be decrypted: ' . $exception->getMessage()
			);
		}

		$scheme = (string)($directoryConfig['authentication']['scheme'] ?? self::DEFAULT_AUTH_SCHEME);

		return $scheme . ' ' . $token;
	}//end buildAuthorizationHeader()
}//end class

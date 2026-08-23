<?php

/**
 * Integriq mTLS Transport Service.
 *
 * The ONE call-site in the app that dispatches an outbound HTTP request
 * with a client certificate attached: materialise → merge Guzzle TLS
 * options → dispatch via the CALLER'S OWN injected Guzzle `Client` (no
 * parallel HTTP stack) → clean up temp files in a `finally` block,
 * regardless of success or failure. `IStandardsClient`,
 * `FscDirectoryClient`, and `DsoClient` each call this instead of
 * `Client::request()` directly when their source's
 * `configuration.authentication.mode=mtls`.
 *
 * Never fails open: a `GuzzleException` raised during dispatch is wrapped
 * as {@see \OCA\Integriq\Exception\MtlsHandshakeException} — there is
 * no retry-without-certificate path.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Mtls
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
 * @spec openspec/specs/mtls-client-certificate-transport/spec.md#requirement-mtls-never-fails-open-to-plaintext-or-token-auth-req-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Mtls;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OCA\Integriq\Exception\MtlsHandshakeException;
use OCA\Integriq\Exception\MtlsTransportException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Dispatches one outbound HTTP request with a client certificate attached,
 * guaranteeing temp-file cleanup regardless of outcome.
 *
 * @spec openspec/specs/mtls-client-certificate-transport/spec.md#requirement-mtls-never-fails-open-to-plaintext-or-token-auth-req-003
 */
class MtlsTransportService {
	/**
	 * Constructor.
	 *
	 * @param MtlsTransportOptionsBuilder $optionsBuilder Materialises certificate material + builds Guzzle TLS options.
	 * @param LoggerInterface $logger Logger for secret-free failure diagnostics.
	 */
	public function __construct(
		private readonly MtlsTransportOptionsBuilder $optionsBuilder,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Dispatch one request with the given certificate bundle attached.
	 *
	 * @param Client $httpClient The caller's own injected Guzzle client (no parallel HTTP stack).
	 * @param string $method The HTTP method.
	 * @param string $url The absolute request URL.
	 * @param array<string, mixed> $requestOptions The caller's Guzzle request options (headers, body/json, etc.);
	 *                                             the mTLS `cert`/`ssl_key`/`verify` options are merged in and
	 *                                             take precedence over any caller-supplied value of the same key.
	 * @param MtlsCertificateBundle $bundle The validated, decrypted certificate material.
	 *
	 * @return ResponseInterface The Guzzle response.
	 *
	 * @throws MtlsHandshakeException When the wrapped dispatch fails for any reason.
	 *
	 * @spec openspec/specs/mtls-client-certificate-transport/spec.md#scenario-a-handshake-failure-raises-a-typed-exception-not-a-silent-fallback
	 */
	public function request(
		Client $httpClient,
		string $method,
		string $url,
		array $requestOptions,
		MtlsCertificateBundle $bundle,
	): ResponseInterface {
		$files = $this->optionsBuilder->materialize(bundle: $bundle);

		try {
			$tlsOptions = $this->optionsBuilder->toGuzzleOptions(files: $files, passphrase: $bundle->passphrase);
			$mergedOptions = array_merge($requestOptions, $tlsOptions);

			try {
				return $httpClient->request($method, $url, $mergedOptions);
			} catch (GuzzleException $exception) {
				$this->logger->warning(
					'[MtlsTransportService] mTLS dispatch failed',
					['exception' => $exception->getMessage()]
				);
				throw new MtlsHandshakeException(
					message: 'The mTLS-authenticated request failed: ' . $exception->getMessage(),
					errorCode: MtlsTransportException::ERROR_HANDSHAKE_FAILED,
					previous: $exception
				);
			}
		} finally {
			// Cleanup ALWAYS runs — success, GuzzleException, or any other
			// throwable escaping the block above.
			$this->optionsBuilder->cleanup(files: $files);
		}//end try

	}//end request()
}//end class

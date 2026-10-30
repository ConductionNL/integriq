<?php

/**
 * Contract of the MtlsTransportService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Mtls
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Mtls;

use GuzzleHttp\Client;
use OCA\Integriq\Exception\MtlsHandshakeException;
use Psr\Http\Message\ResponseInterface;

/**
 * Public operations of {@see MtlsTransportService} that other components call.
 */
interface MtlsTransportServiceInterface {
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
	): ResponseInterface;
}

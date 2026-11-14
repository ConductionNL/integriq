<?php

/**
 * Integriq ClientCertificateReader.
 *
 * Reads the client certificate the web server verified for this request.
 * Integriq does not terminate TLS: it takes the certificate from
 * `SSL_CLIENT_VERIFY` and `SSL_CLIENT_CERT` in the server environment, or,
 * behind a reverse proxy, from one forwarding header that is read only when
 * the request comes straight from a Nextcloud trusted proxy. A header from
 * any other address is ignored, so it cannot be forged from outside.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Auth
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-can-require-a-client-certificate-req-cred-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Auth;

use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IRequest;

/**
 * Finds and parses the verified client certificate of a request.
 *
 * @spec openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-can-require-a-client-certificate-req-cred-004
 */
class ClientCertificateReader {

	/**
	 * The app config key naming the forwarding header.
	 */
	public const HEADER_KEY = 'mtls_certificate_header';

	/**
	 * The forwarding header when none is configured.
	 */
	public const DEFAULT_HEADER = 'X-SSL-Client-Cert';

	/**
	 * An OIN is twenty digits.
	 */
	private const OIN_PATTERN = '/^\d{20}$/';

	/**
	 * @param IRequest   $request   The current request.
	 * @param IConfig    $config    Holds Nextcloud's `trusted_proxies`.
	 * @param IAppConfig $appConfig Holds the forwarding header name.
	 */
	public function __construct(
		private readonly IRequest $request,
		private readonly IConfig $config,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The verified client certificate of the current request, or null.
	 *
	 * @return ClientCertificate|null
	 *
	 * @spec openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-can-require-a-client-certificate-req-cred-004
	 */
	public function fromRequest(): ?ClientCertificate {
		$verified = ($this->request->getEnv('SSL_CLIENT_VERIFY') === 'SUCCESS');
		$serverCertificate = $this->request->getEnv('SSL_CLIENT_CERT');
		if ($verified === true && is_string($serverCertificate) === true && $serverCertificate !== '') {
			return self::parse(pem: $serverCertificate);
		}

		$peer = (string)($this->request->getEnv('REMOTE_ADDR') ?? '');
		if ($this->isTrustedProxy(address: $peer) === false) {
			return null;
		}

		$header = $this->request->getHeader($this->headerName());
		if ($header === '') {
			return null;
		}

		return self::parse(pem: self::decodeHeader(value: $header));
	}//end fromRequest()

	/**
	 * Parse one PEM certificate; null when it is not one.
	 *
	 * @param string $pem The certificate, PEM encoded.
	 *
	 * @return ClientCertificate|null
	 *
	 * @spec openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-can-require-a-client-certificate-req-cred-004
	 */
	public static function parse(string $pem): ?ClientCertificate {
		$pem = self::normalisePem(pem: $pem);
		if ($pem === null) {
			return null;
		}

		$parsed = @openssl_x509_parse($pem);
		$fingerprint = @openssl_x509_fingerprint($pem, 'sha256');
		if (is_array($parsed) === false || is_string($fingerprint) === false) {
			return null;
		}

		$subject = (array)($parsed['subject'] ?? []);
		$serial  = ($subject['serialNumber'] ?? null);
		$oin     = null;
		if (is_string($serial) === true && preg_match(self::OIN_PATTERN, $serial) === 1) {
			$oin = $serial;
		}

		return new ClientCertificate(
			pem: $pem,
			fingerprint: strtolower($fingerprint),
			subject: self::oneLine(name: $subject),
			issuer: self::oneLine(name: (array)($parsed['issuer'] ?? [])),
			validFrom: (int)($parsed['validFrom_time_t'] ?? 0),
			validTo: (int)($parsed['validTo_time_t'] ?? 0),
			oin: $oin
		);
	}//end parse()

	/**
	 * Whether an address is one of Nextcloud's trusted proxies (an address or a CIDR range).
	 *
	 * @param string $address The direct peer address.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-can-require-a-client-certificate-req-cred-004
	 */
	public function isTrustedProxy(string $address): bool {
		if ($address === '' || @inet_pton($address) === false) {
			return false;
		}

		$proxies = $this->config->getSystemValue('trusted_proxies', []);
		if (is_array($proxies) === false) {
			return false;
		}

		foreach ($proxies as $proxy) {
			if (is_string($proxy) === true && self::inRange(address: $address, range: trim($proxy)) === true) {
				return true;
			}
		}

		return false;
	}//end isTrustedProxy()

	/**
	 * The configured forwarding header name.
	 *
	 * @return string
	 */
	private function headerName(): string {
		$name = trim($this->appConfig->getValueString('integriq', self::HEADER_KEY, self::DEFAULT_HEADER));
		if ($name === '') {
			return self::DEFAULT_HEADER;
		}

		return $name;
	}//end headerName()

	/**
	 * A forwarded certificate as PEM: nginx sends it URL-encoded
	 * (`$ssl_client_escaped_cert`), Apache with spaces where the newlines were.
	 *
	 * @param string $value The header value.
	 *
	 * @return string
	 */
	private static function decodeHeader(string $value): string {
		if (str_contains($value, '%') === true) {
			return rawurldecode($value);
		}

		return $value;
	}//end decodeHeader()

	/**
	 * Rebuild a PEM from its base64 body, whatever whitespace it arrived with.
	 *
	 * @param string $pem A PEM, possibly with its newlines replaced.
	 *
	 * @return string|null
	 */
	private static function normalisePem(string $pem): ?string {
		if (preg_match('/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s', $pem, $match) !== 1) {
			return null;
		}

		$body = preg_replace('/\s+/', '', $match[1]);
		if ($body === null || $body === '' || base64_decode($body, true) === false) {
			return null;
		}

		return "-----BEGIN CERTIFICATE-----\n" . chunk_split($body, 64, "\n") . "-----END CERTIFICATE-----\n";
	}//end normalisePem()

	/**
	 * A distinguished name as one line, `/C=NL/O=Gemeente/serialNumber=...`.
	 *
	 * @param array<string,string|array<int,string>> $name The parsed name.
	 *
	 * @return string
	 */
	private static function oneLine(array $name): string {
		$line = '';
		foreach ($name as $key => $value) {
			foreach ((array)$value as $part) {
				$line .= '/' . $key . '=' . (string)$part;
			}
		}

		return $line;
	}//end oneLine()

	/**
	 * Whether an address lies in a range: an exact address, or `address/bits`.
	 *
	 * @param string $address The address.
	 * @param string $range   The address or CIDR range.
	 *
	 * @return bool
	 */
	private static function inRange(string $address, string $range): bool {
		$bits = null;
		if (str_contains($range, '/') === true) {
			[$range, $bitText] = explode('/', $range, 2);
			if (ctype_digit($bitText) === false) {
				return false;
			}

			$bits = (int)$bitText;
		}

		$subject = @inet_pton($address);
		$network = @inet_pton($range);
		if ($subject === false || $network === false || strlen($subject) !== strlen($network)) {
			return false;
		}

		if ($bits === null) {
			return hash_equals($network, $subject);
		}

		if ($bits > (strlen($subject) * 8)) {
			return false;
		}

		$whole = intdiv($bits, 8);
		if (substr($subject, 0, $whole) !== substr($network, 0, $whole)) {
			return false;
		}

		$rest = ($bits % 8);
		if ($rest === 0) {
			return true;
		}

		$mask = ((0xFF << (8 - $rest)) & 0xFF);
		return ((ord($subject[$whole]) & $mask) === (ord($network[$whole]) & $mask));
	}//end inRange()
}//end class

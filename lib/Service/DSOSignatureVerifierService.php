<?php

/**
 * Integriq DSOSignatureVerifierService.
 *
 * Cryptographic signature verification for the DSO / Omgevingsloket STAM
 * koppelvlak inbound webhook (`DSOController::receiveRequest()`). Fails
 * closed on every error path: a missing/malformed header, an untrusted or
 * expired certificate, a broken chain-of-trust, or a body-hash mismatch all
 * return `false`.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dso-stam-pkioverheid-signature-verification/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * PKIoverheid certificate-chain / HMAC body-signature verifier for DSO STAM.
 *
 * Two signing modes, selected by the `mode` of the trust configuration the
 * caller passes in. Since dso-intake-through-an-integriq-connection that
 * configuration lives on the instance's `dso-stam` consumer, not in app config:
 *   - `hmac`: shared-secret HMAC-SHA256, for the DSO-LV pre-production
 *             environment. Delegates the actual HMAC comparison to the
 *             already-hardened {@see WebhookSignatureService} (GitHub-style
 *             `sha256=<hex>` scheme) so the constant-time comparison logic is
 *             not duplicated.
 *   - `pkioverheid` (legacy name `rsa`): PKIoverheid certificate-chain +
 *             RSA-SHA256 body signature, for
 *             production. The signing certificate, intermediate chain, and
 *             trusted root CA are all admin-configured (never hardcoded).
 *
 * Every public entry point fails closed: any missing config, parse failure,
 * expired certificate, broken chain-of-trust, or signature mismatch returns
 * `false` rather than throwing, so a misconfiguration cannot be mistaken for
 * "verification skipped, allow the request".
 *
 * @spec openspec/changes/dso-stam-pkioverheid-signature-verification/tasks.md#task-1
 */
class DSOSignatureVerifierService {

	/**
	 * Legacy app-config key selecting the signing mode (`hmac` or `rsa`).
	 * Read only by the MigrateDsoStamConnection repair step.
	 *
	 * @var string
	 */
	public const CONFIG_MODE = 'dso_pki_mode';

	/**
	 * App-config key for the HMAC shared secret (pre-production mode).
	 *
	 * @var string
	 */
	public const CONFIG_HMAC_SECRET = 'dso_pki_hmac_secret';

	/**
	 * App-config key for the PEM-encoded signing (leaf) certificate.
	 *
	 * @var string
	 */
	public const CONFIG_SIGNING_CERTIFICATE = 'dso_pki_signing_certificate';

	/**
	 * App-config key for the PEM-encoded intermediate certificate chain.
	 *
	 * @var string
	 */
	public const CONFIG_INTERMEDIATE_CHAIN = 'dso_pki_intermediate_chain';

	/**
	 * App-config key for the PEM-encoded trusted PKIoverheid root CA.
	 *
	 * @var string
	 */
	public const CONFIG_ROOT_CA = 'dso_pki_root_ca';

	/**
	 * Pre-production HMAC shared-secret signing mode.
	 *
	 * @var string
	 */
	public const MODE_HMAC = 'hmac';

	/**
	 * Production PKIoverheid certificate-chain signing mode.
	 *
	 * @var string
	 */
	public const MODE_PKIOVERHEID = 'pkioverheid';

	/**
	 * Legacy name of the PKIoverheid mode, as the app config stored it.
	 *
	 * @var string
	 */
	public const MODE_RSA = 'rsa';

	/**
	 * Constructor.
	 *
	 * @param WebhookSignatureService $webhookSignatureService Shared HMAC verifier (pre-production mode).
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 */
	public function __construct(
		private readonly WebhookSignatureService $webhookSignatureService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Verify the `X-DSO-Signature` header against the raw request body.
	 *
	 * @param string|null $signatureHeader The raw `X-DSO-Signature` header value.
	 * @param string $rawBody The exact raw request body bytes.
	 * @param array<string, mixed> $trust The trust configuration: `mode`, `hmacSecret`,
	 *                                    `signingCertificate`, `intermediateChain`, `rootCa`.
	 *
	 * @return boolean True only when the signature cryptographically verifies.
	 *
	 * @spec openspec/changes/dso-stam-pkioverheid-signature-verification/tasks.md#task-1
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-1
	 */
	public function verify(?string $signatureHeader, string $rawBody, array $trust): bool {
		if ($signatureHeader === null || $signatureHeader === '') {
			return false;
		}

		try {
			if (self::normalizeMode(mode: ($trust['mode'] ?? null)) === self::MODE_PKIOVERHEID) {
				return $this->verifyRsaChain(signatureHeader: $signatureHeader, rawBody: $rawBody, trust: $trust);
			}

			return $this->verifyHmac(signatureHeader: $signatureHeader, rawBody: $rawBody, trust: $trust);
		} catch (\Throwable $e) {
			// Fail closed: any unexpected error (malformed PEM, filesystem
			// failure while staging a temp CA bundle, etc.) rejects the
			// request rather than silently accepting it.
			$this->logger->warning(
				'DSO STAM: signature verification raised an exception, failing closed',
				['exception' => $e->getMessage()]
			);
			return false;
		}

	}//end verify()

	/**
	 * Normalise a stored signing mode.
	 *
	 * @param mixed $mode The stored mode: `hmac`, `pkioverheid`, or the legacy `rsa`.
	 *
	 * @return string {@see self::MODE_HMAC} or {@see self::MODE_PKIOVERHEID}. Anything
	 *                else reads as HMAC (pre-production) until an admin switches.
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-1
	 */
	public static function normalizeMode(mixed $mode): string {
		if ($mode === self::MODE_PKIOVERHEID || $mode === self::MODE_RSA) {
			return self::MODE_PKIOVERHEID;
		}

		return self::MODE_HMAC;
	}//end normalizeMode()

	/**
	 * Verify an HMAC-SHA256 body signature (pre-production mode).
	 *
	 * @param string $signatureHeader The `sha256=<hex>` (or bare hex) signature header.
	 * @param string $rawBody The raw request body.
	 * @param array<string, mixed> $trust The trust configuration.
	 *
	 * @return boolean
	 */
	private function verifyHmac(string $signatureHeader, string $rawBody, array $trust): bool {
		$secret = (string)($trust['hmacSecret'] ?? '');
		if ($secret === '') {
			return false;
		}

		return $this->webhookSignatureService->verify(
			rawBody: $rawBody,
			headerValue: $signatureHeader,
			config: [
				'scheme' => 'github',
				'secret' => $secret,
			]
		);

	}//end verifyHmac()

	/**
	 * Verify an RSA-SHA256 body signature against the configured PKIoverheid
	 * certificate chain (production mode).
	 *
	 * @param string $signatureHeader Base64-encoded RSA signature over the raw body.
	 * @param string $rawBody The raw request body.
	 * @param array<string, mixed> $trust The trust configuration.
	 *
	 * @return boolean
	 */
	private function verifyRsaChain(string $signatureHeader, string $rawBody, array $trust): bool {
		$certPem = (string)($trust['signingCertificate'] ?? '');
		$rootPem = (string)($trust['rootCa'] ?? '');
		if ($certPem === '' || $rootPem === '') {
			return false;
		}

		$intermediatePem = (string)($trust['intermediateChain'] ?? '');

		$decodedSignature = base64_decode($signatureHeader, true);
		if ($decodedSignature === false || $decodedSignature === '') {
			return false;
		}

		$certResource = openssl_x509_read($certPem);
		if ($certResource === false) {
			return false;
		}

		if ($this->isCertificateCurrentlyValid(certPem: $certPem) === false) {
			return false;
		}

		if ($this->chainIsTrusted(certPem: $certPem, rootPem: $rootPem, intermediatePem: $intermediatePem) === false) {
			return false;
		}

		$publicKey = openssl_pkey_get_public($certResource);
		if ($publicKey === false) {
			return false;
		}

		return openssl_verify($rawBody, $decodedSignature, $publicKey, OPENSSL_ALGO_SHA256) === 1;
	}//end verifyRsaChain()

	/**
	 * Whether a PEM certificate is currently within its validity window.
	 *
	 * @param string $certPem PEM-encoded X.509 certificate.
	 *
	 * @return boolean False for unparseable, not-yet-valid, or expired certificates.
	 *
	 * @spec openspec/changes/dso-stam-pkioverheid-signature-verification/tasks.md#task-1
	 */
	public function isCertificateCurrentlyValid(string $certPem): bool {
		$parsed = openssl_x509_parse($certPem);
		if ($parsed === false) {
			return false;
		}

		$validFrom = ($parsed['validFrom_time_t'] ?? null);
		$validTo = ($parsed['validTo_time_t'] ?? null);
		if (is_int($validFrom) === false || is_int($validTo) === false) {
			return false;
		}

		$now = time();

		return ($now >= $validFrom && $now <= $validTo);
	}//end isCertificateCurrentlyValid()

	/**
	 * Whether a signing certificate chains to the trusted root CA.
	 *
	 * Stages the certificate material into temp files so PHP's
	 * `openssl_x509_checkpurpose()` can walk the chain-of-trust (the
	 * function requires filesystem paths, not PEM strings, for its CA
	 * bundle and untrusted-intermediate arguments). Temp files are removed
	 * unconditionally, including on error.
	 *
	 * @param string $certPem PEM-encoded signing (leaf) certificate.
	 * @param string $rootPem PEM-encoded trusted root CA.
	 * @param string $intermediatePem PEM-encoded intermediate chain (may be empty).
	 *
	 * @return boolean True when the certificate chains to the trusted root.
	 *
	 * @spec openspec/changes/dso-stam-pkioverheid-signature-verification/tasks.md#task-1
	 */
	public function chainIsTrusted(string $certPem, string $rootPem, string $intermediatePem = ''): bool {
		$caFile = null;
		$untrustedFile = null;

		try {
			$caFile = $this->writeTempPem(pem: $rootPem);

			$untrustedBundle = trim($certPem . "\n" . $intermediatePem);
			$untrustedFile = $this->writeTempPem(pem: $untrustedBundle);

			$certResource = openssl_x509_read($certPem);
			if ($certResource === false) {
				return false;
			}

			return openssl_x509_checkpurpose($certResource, X509_PURPOSE_ANY, [$caFile], $untrustedFile) === true;
		} finally {
			foreach ([$caFile, $untrustedFile] as $file) {
				if ($file !== null && file_exists($file) === true) {
					unlink($file);
				}
			}
		}

	}//end chainIsTrusted()

	/**
	 * Validate a candidate PKIoverheid chain configuration at admin save-time.
	 *
	 * @param string $certPem PEM-encoded signing (leaf) certificate.
	 * @param string $rootPem PEM-encoded trusted root CA.
	 * @param string $intermediatePem PEM-encoded intermediate chain (may be empty).
	 *
	 * @return array<int, string> Human-readable errors; empty when the chain is valid.
	 *
	 * @spec openspec/changes/dso-stam-pkioverheid-signature-verification/tasks.md#task-2
	 */
	public function validateChainConfig(string $certPem, string $rootPem, string $intermediatePem = ''): array {
		$errors = [];

		if (openssl_x509_read($certPem) === false) {
			$errors[] = 'Signing certificate is not a valid X.509 PEM certificate.';
		} elseif ($this->isCertificateCurrentlyValid(certPem: $certPem) === false) {
			$errors[] = 'Signing certificate is expired or not yet valid.';
		}

		if ($rootPem === '') {
			$errors[] = 'A trusted root CA certificate is required.';
		} elseif (openssl_x509_read($rootPem) === false) {
			$errors[] = 'Root CA is not a valid X.509 PEM certificate.';
		}

		if (empty($errors) === true
			&& $this->chainIsTrusted(certPem: $certPem, rootPem: $rootPem, intermediatePem: $intermediatePem) === false
		) {
			$errors[] = 'Signing certificate does not chain to the configured root CA.';
		}

		return $errors;
	}//end validateChainConfig()

	/**
	 * Stage a PEM blob into a temp file for OpenSSL's file-based chain APIs.
	 *
	 * @param string $pem PEM-encoded certificate material.
	 *
	 * @return string Absolute path to the temp file.
	 *
	 * @throws \RuntimeException When a temp file could not be allocated.
	 */
	private function writeTempPem(string $pem): string {
		$path = tempnam(sys_get_temp_dir(), 'dso_pki_');
		if ($path === false) {
			throw new RuntimeException('Unable to allocate a temporary file for PKIoverheid chain validation.');
		}

		file_put_contents($path, $pem);

		return $path;
	}//end writeTempPem()
}//end class

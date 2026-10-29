<?php

/**
 * Contract of the DSOSignatureVerifierService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service
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

namespace OCA\Integriq\Service;

/**
 * Public operations of {@see DSOSignatureVerifierService} that other components call.
 */
interface DSOSignatureVerifierServiceInterface {
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
	public function verify(?string $signatureHeader, string $rawBody, array $trust): bool;

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
	public function normalizeMode(mixed $mode): string;

	/**
	 * Whether a PEM certificate is currently within its validity window.
	 *
	 * @param string $certPem PEM-encoded X.509 certificate.
	 *
	 * @return boolean False for unparseable, not-yet-valid, or expired certificates.
	 *
	 * @spec openspec/changes/dso-stam-pkioverheid-signature-verification/tasks.md#task-1
	 */
	public function isCertificateCurrentlyValid(string $certPem): bool;

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
	public function chainIsTrusted(string $certPem, string $rootPem, string $intermediatePem = ''): bool;

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
	public function validateChainConfig(string $certPem, string $rootPem, string $intermediatePem = ''): array;
}

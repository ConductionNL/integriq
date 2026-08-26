<?php

/**
 * Integriq mTLS Configuration Resolver.
 *
 * Decrypts and validates a source's `configuration.authentication.mtls`
 * block into an in-memory {@see MtlsCertificateBundle}, entirely BEFORE any
 * network call is attempted. Certificate material is `OCP\Security\ICrypto`-
 * encrypted at rest — the SAME storage pattern already used by
 * `IStandardsClient`/`FscDirectoryClient`/`DsoClient`/`RestNotifyNlProvider`
 * for their bearer tokens. See the change's design.md "Key-storage decision"
 * for why the OpenRegister credential broker's `resolveInjectable()` was
 * verified unsuitable for raw private-key material (it is a documented
 * constrained proxy, per `PkiOverheidCredentialResolver`, that cannot yet
 * hand back in-process signing material).
 *
 * FAIL-CLOSED: every validation failure (missing material, undecryptable
 * material, malformed PEM, expired certificate, wrong passphrase) raises
 * {@see \OCA\Integriq\Exception\MtlsConfigurationException} with a
 * stable errorCode. There is no plaintext-on-disk fallback and no path that
 * silently downgrades to token/plaintext auth.
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
 * @spec openspec/specs/mtls-client-certificate-transport/spec.md#requirement-shared-mtls-transport-builds-guzzle-tls-options-from-encrypted-at-rest-certificate-material-req-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Mtls;

use OCA\Integriq\Exception\MtlsConfigurationException;
use OCA\Integriq\Exception\MtlsTransportException;
use OCP\Security\ICrypto;
use Throwable;

/**
 * Resolves + validates encrypted-at-rest mTLS certificate material.
 *
 * `@`-silenced `openssl_*` calls are DELIBERATE: malformed/unparsable
 * certificate or key material makes those functions emit a PHP warning
 * whose text can echo the offending input back into the log. Every such
 * call's return value IS checked and converted into a typed, secret-free
 * {@see MtlsConfigurationException}, so suppressing the raw warning is
 * both safe and required to keep key material out of the logs. Mirrors
 * {@see \OCA\Integriq\Adapters\Digikoppeling\WsSecuritySigner}'s
 * identical, already-accepted suppression.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ErrorControlOperator)
 *
 * @spec openspec/specs/mtls-client-certificate-transport/spec.md#requirement-shared-mtls-transport-builds-guzzle-tls-options-from-encrypted-at-rest-certificate-material-req-001
 */
class MtlsConfigResolver {

	/**
	 * Auth mode value that selects mTLS dispatch.
	 *
	 * @var string
	 */
	public const MODE_MTLS = 'mtls';

	/**
	 * Auth mode value that selects the existing token/Bearer dispatch (default).
	 *
	 * @var string
	 */
	public const MODE_TOKEN = 'token';

	/**
	 * Constructor.
	 *
	 * @param ICrypto $crypto Encrypts/decrypts the stored certificate material at rest.
	 */
	public function __construct(
		private readonly ICrypto $crypto,
	) {

	}//end __construct()

	/**
	 * Whether the given `authentication` configuration block selects mTLS dispatch.
	 *
	 * @param array<string, mixed> $authConfig The source's `configuration.authentication` object.
	 *
	 * @return boolean
	 *
	 * @spec openspec/specs/mtls-client-certificate-transport/spec.md#requirement-each-adapter-routes-through-the-mtls-transport-only-when-configured-proving-no-orphaned-capability-req-004
	 */
	public function isMtlsConfigured(array $authConfig): bool {
		return (($authConfig['mode'] ?? self::MODE_TOKEN) === self::MODE_MTLS);
	}//end isMtlsConfigured()

	/**
	 * Resolve `authentication.mtls` into a validated, in-memory certificate bundle.
	 *
	 * @param array<string, mixed> $authConfig The source's `configuration.authentication` object.
	 *
	 * @return MtlsCertificateBundle The validated, decrypted material.
	 *
	 * @throws MtlsConfigurationException Fail-closed on any missing/invalid/expired/undecryptable material.
	 *
	 * @spec openspec/specs/mtls-client-certificate-transport/spec.md#requirement-shared-mtls-transport-builds-guzzle-tls-options-from-encrypted-at-rest-certificate-material-req-001
	 */
	public function resolve(array $authConfig): MtlsCertificateBundle {
		$mtls = ($authConfig['mtls'] ?? []);
		if (is_array($mtls) === false) {
			$mtls = [];
		}

		$encryptedCertificate = (string)($mtls['encryptedCertificate'] ?? '');
		$encryptedPrivateKey = (string)($mtls['encryptedPrivateKey'] ?? '');
		if ($encryptedCertificate === '' || $encryptedPrivateKey === '') {
			throw new MtlsConfigurationException(
				message: 'mTLS is configured (`authentication.mode=mtls`) but '
					. '`authentication.mtls.encryptedCertificate`/`encryptedPrivateKey` are missing. '
					. 'No plaintext-certificate fallback is permitted.',
				errorCode: MtlsTransportException::ERROR_MATERIAL_MISSING
			);
		}

		$certificatePem = $this->decrypt(label: 'certificate', encrypted: $encryptedCertificate);
		$privateKeyPem = $this->decrypt(label: 'private key', encrypted: $encryptedPrivateKey);

		$passphrase = null;
		$encryptedPassphrase = (string)($mtls['encryptedPassphrase'] ?? '');
		if ($encryptedPassphrase !== '') {
			$passphrase = $this->decrypt(label: 'passphrase', encrypted: $encryptedPassphrase);
		}

		$caBundlePem = null;
		$encryptedCaBundle = (string)($mtls['encryptedCaBundle'] ?? '');
		if ($encryptedCaBundle !== '') {
			$caBundlePem = $this->decrypt(label: 'CA bundle', encrypted: $encryptedCaBundle);
		}

		$this->assertCertificateShape(certificatePem: $certificatePem);
		$this->assertPrivateKeyShape(privateKeyPem: $privateKeyPem);
		$this->assertCertificateNotExpired(certificatePem: $certificatePem);
		$this->assertPassphraseUnlocksKey(privateKeyPem: $privateKeyPem, passphrase: $passphrase);

		return new MtlsCertificateBundle(
			certificatePem: $certificatePem,
			privateKeyPem: $privateKeyPem,
			passphrase: $passphrase,
			caBundlePem: $caBundlePem
		);

	}//end resolve()

	/**
	 * Decrypt one stored field, wrapping any decrypt failure.
	 *
	 * @param string $label A secret-free label used only in the failure message.
	 * @param string $encrypted The `ICrypto`-encrypted stored value.
	 *
	 * @return string The decrypted value.
	 *
	 * @throws MtlsConfigurationException When decryption fails.
	 */
	private function decrypt(string $label, string $encrypted): string {
		try {
			return $this->crypto->decrypt($encrypted);
		} catch (Throwable $exception) {
			throw new MtlsConfigurationException(
				message: 'The stored mTLS ' . $label . ' could not be decrypted.',
				errorCode: MtlsTransportException::ERROR_DECRYPTION_FAILED,
				previous: $exception
			);
		}

	}//end decrypt()

	/**
	 * Validate the certificate PEM carries a recognisable certificate marker.
	 *
	 * @param string $certificatePem The decrypted certificate PEM.
	 *
	 * @return void
	 *
	 * @throws MtlsConfigurationException When the PEM shape is not a certificate.
	 */
	private function assertCertificateShape(string $certificatePem): void {
		if (str_contains($certificatePem, 'BEGIN CERTIFICATE') === false) {
			throw new MtlsConfigurationException(
				message: 'The stored mTLS certificate is not a well-formed PEM certificate.',
				errorCode: MtlsTransportException::ERROR_INVALID_CERTIFICATE
			);
		}

	}//end assertCertificateShape()

	/**
	 * Validate the private key PEM carries a recognisable private-key marker.
	 *
	 * @param string $privateKeyPem The decrypted private key PEM.
	 *
	 * @return void
	 *
	 * @throws MtlsConfigurationException When the PEM shape is not a private key.
	 */
	private function assertPrivateKeyShape(string $privateKeyPem): void {
		if (str_contains($privateKeyPem, 'PRIVATE KEY') === false) {
			throw new MtlsConfigurationException(
				message: 'The stored mTLS private key is not a well-formed PEM private key.',
				errorCode: MtlsTransportException::ERROR_INVALID_PRIVATE_KEY
			);
		}

	}//end assertPrivateKeyShape()

	/**
	 * Validate the certificate has not passed its `validTo` date.
	 *
	 * @param string $certificatePem The decrypted certificate PEM.
	 *
	 * @return void
	 *
	 * @throws MtlsConfigurationException When the certificate is expired or unparsable.
	 */
	private function assertCertificateNotExpired(string $certificatePem): void {
		$parsed = @openssl_x509_parse($certificatePem);
		if ($parsed === false) {
			throw new MtlsConfigurationException(
				message: 'The stored mTLS certificate could not be parsed to verify its validity period.',
				errorCode: MtlsTransportException::ERROR_INVALID_CERTIFICATE
			);
		}

		$validTo = ($parsed['validTo_time_t'] ?? null);
		if (is_int($validTo) === true && $validTo < time()) {
			throw new MtlsConfigurationException(
				message: 'The stored mTLS certificate has expired.',
				errorCode: MtlsTransportException::ERROR_CERTIFICATE_EXPIRED
			);
		}

	}//end assertCertificateNotExpired()

	/**
	 * Validate a configured passphrase actually unlocks the private key.
	 *
	 * @param string $privateKeyPem The decrypted private key PEM.
	 * @param string|null $passphrase The decrypted passphrase, or null when none is configured.
	 *
	 * @return void
	 *
	 * @throws MtlsConfigurationException When the key cannot be opened with (or without) the passphrase.
	 */
	private function assertPassphraseUnlocksKey(string $privateKeyPem, ?string $passphrase): void {
		$handle = @openssl_pkey_get_private($privateKeyPem, ($passphrase ?? ''));
		if ($handle === false) {
			throw new MtlsConfigurationException(
				message: 'The stored mTLS private key could not be opened — the configured passphrase is invalid, '
					. 'or the key requires a passphrase that was not configured.',
				errorCode: MtlsTransportException::ERROR_PASSPHRASE_INVALID
			);
		}

	}//end assertPassphraseUnlocksKey()
}//end class

<?php

/**
 * Integriq — what an administrator may set on a Berichtenbox source, checked and encrypted.
 *
 * @category Service
 * @package  OCA\Integriq\Service\DigitalPost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\DigitalPost;

use OCA\Integriq\Exception\MtlsConfigurationException;
use OCA\Integriq\Exception\MtlsTransportException;
use OCA\Integriq\Service\Mtls\MtlsCertificateBundle;
use OCA\Integriq\Service\Mtls\MtlsConfigResolver;
use OCA\Integriq\Kernel\AppIdentity;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\Security\ICrypto;

/**
 * The certificate and key arrive as PEM, are checked exactly as a send will
 * check them (MtlsConfigResolver), and are stored encrypted with ICrypto under
 * the source's `configuration.authentication.mtls`, a write-only path no
 * rendered read returns. The source then names the certificate by its
 * fingerprint in `certificateRef`. {@see describe()} answers the certificate's
 * subject, OIN and expiry, never the PEM, the key or the token.
 *
 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-the-transport-certificate-is-held-encrypted-and-named-by-reference-req-dpa-004
 */
class BerichtenboxSourceSettings implements BerichtenboxSourceSettingsInterface {

	/**
	 * Constructor.
	 *
	 * @param MtlsConfigResolver $mtlsConfigResolver Checks the certificate the way a send will.
	 * @param ICrypto $crypto Encrypts the certificate, key and token at rest.
	 * @param IL10N $l Messages.
	 * @param IAppConfig $appConfig Reads the live flag.
	 */
	public function __construct(
		private readonly MtlsConfigResolver $mtlsConfigResolver,
		private readonly ICrypto $crypto,
		private readonly IL10N $l,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Apply the administrator's settings to a raw source.
	 *
	 * @param array<string,mixed> $data The raw source.
	 * @param array<string,mixed> $params The request parameters.
	 *
	 * @return array{data:array<string,mixed>,warnings:array<int,string>} The source to save, and warnings.
	 *
	 * @throws BerichtenboxSettingsRefusal When a setting is wrong; nothing is applied then.
	 *
	 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-the-transport-certificate-is-held-encrypted-and-named-by-reference-req-dpa-004
	 */
	public function apply(array $data, array $params): array {
		$config = $this->withFields(config: $this->configurationOf(data: $data), params: $params);
		$this->assertFields(config: $config);

		if (is_array($params['berichtTypes'] ?? null) === true) {
			$config['berichtTypes'] = $this->berichtTypes(types: $params['berichtTypes']);
		}

		$authentication = (array)($config['authentication'] ?? []);
		$warnings = [];
		$upload = $this->uploadedCertificate(config: $config, certificate: ($params['certificate'] ?? null));
		if ($upload !== null) {
			$authentication = $upload->authentication;
			$config['certificateRef'] = $upload->certificateRef;
			$warnings = $upload->warnings;
		}

		$config['authentication'] = $this->withToken(authentication: $authentication, token: ($params['adapterToken'] ?? null));
		$data['configuration'] = $config;

		return ['data' => $data, 'warnings' => $warnings];
	}//end apply()

	/**
	 * The source's configuration, decoded when it is stored as JSON text.
	 *
	 * @param array<string, mixed> $data The source data.
	 *
	 * @return array<string, mixed>
	 */
	private function configurationOf(array $data): array {
		$config = ($data['configuration'] ?? []);
		if (is_string($config) === true) {
			$config = (array)json_decode($config, true);
		}

		return (array)$config;
	}//end configurationOf()

	/**
	 * The configuration with the provider id and every given field, trimmed.
	 *
	 * @param array<string, mixed> $config The configuration.
	 * @param array<string, mixed> $params The form fields.
	 *
	 * @return array<string, mixed>
	 */
	private function withFields(array $config, array $params): array {
		$config['providerId'] = BerichtenboxProvider::ID;
		foreach (self::FIELDS as $field) {
			if (isset($params[$field]) === true) {
				$config[$field] = trim((string)$params[$field]);
			}
		}

		return $config;
	}//end withFields()

	/**
	 * The accepted upload, or null when no certificate was given.
	 *
	 * @param array<string, mixed> $config      The configuration (its authentication block and sender OIN).
	 * @param mixed                $certificate The `certificate` form field.
	 *
	 * @return CertificateUpload|null
	 *
	 * @throws BerichtenboxSettingsRefusal When the certificate cannot be used.
	 */
	private function uploadedCertificate(array $config, mixed $certificate): ?CertificateUpload {
		if (is_array($certificate) === false || trim((string)($certificate['certificatePem'] ?? '')) === '') {
			return null;
		}

		try {
			return $this->encryptCertificate(certificate: $certificate, config: $config);
		} catch (MtlsConfigurationException $e) {
			throw new BerichtenboxSettingsRefusal(field: 'certificate', message: $e->getMessage());
		}
	}//end uploadedCertificate()

	/**
	 * Whether the live binding is on.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-the-live-binding-speaks-the-interface-logius-publishes-req-dpa-008
	 */
	public function live(): bool {
		$raw = $this->appConfig->getValueString(AppIdentity::APP_ID, 'logius.berichtenbox.feature_flag', '0');

		return ($raw === '1' || strtolower($raw) === 'true');
	}//end live()

	/**
	 * The authentication block with a new adapter token, encrypted, when one was given.
	 *
	 * @param array<string,mixed> $authentication The block.
	 * @param mixed $token The token from the request.
	 *
	 * @return array<string,mixed>
	 */
	private function withToken(array $authentication, mixed $token): array {
		if (is_string($token) === true && $token !== '') {
			$authentication['encryptedToken'] = $this->crypto->encrypt($token);
		}

		return $authentication;
	}//end withToken()

	/**
	 * The source as the settings page shows it: no PEM, no key, no token.
	 *
	 * @param array<string,mixed> $data The raw source.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#scenario-a-send-names-a-certificate-reference-not-a-key
	 */
	public function describe(array $data): array {
		$config = $this->configurationOf(data: $data);

		$described = [
			'slug' => (string)($data['slug'] ?? ''),
			'berichtTypes' => (array)($config['berichtTypes'] ?? []),
			'certificateRef' => (string)($config['certificateRef'] ?? ''),
		];
		foreach (self::FIELDS as $field) {
			$described[$field] = (string)($config[$field] ?? '');
		}

		$described['certificate'] = $this->certificateSummary(authentication: (array)($config['authentication'] ?? []));
		$described['adapterToken'] = isset($config['authentication']['encryptedToken']);

		return $described;
	}//end describe()

	/**
	 * Refuse the first field that is not right.
	 *
	 * @param array<string,mixed> $config The configuration.
	 *
	 * @return void
	 *
	 * @throws BerichtenboxSettingsRefusal When a field is wrong.
	 */
	private function assertFields(array $config): void {
		$oin = (string)($config['senderOin'] ?? '');
		if ($oin !== '' && preg_match('/^\d{20}$/', $oin) !== 1) {
			throw new BerichtenboxSettingsRefusal(field: 'senderOin', message: $this->l->t('An OIN has 20 digits.'));
		}

		foreach (['adapterUrl', 'wusEndpoint'] as $field) {
			$url = (string)($config[$field] ?? '');
			if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL) === false) {
				throw new BerichtenboxSettingsRefusal(field: $field, message: $this->l->t('%s is not a URL.', [$field]));
			}
		}

		$wus = (string)($config['wusEndpoint'] ?? '');
		if ($wus !== '' && str_starts_with($wus, 'https://') === false) {
			throw new BerichtenboxSettingsRefusal(
				field: 'wusEndpoint',
				message: $this->l->t('The subscription check goes over two-way TLS, so its endpoint starts with https://.')
			);
		}
	}//end assertFields()

	/**
	 * The BerichtType per category, empty ones dropped, each at most 8 characters.
	 *
	 * @param array<mixed> $types Category => BerichtType.
	 *
	 * @return array<string,string>
	 *
	 * @throws BerichtenboxSettingsRefusal When a type is too long.
	 */
	private function berichtTypes(array $types): array {
		$clean = [];
		foreach ($types as $category => $type) {
			$type = trim((string)$type);
			if ($type === '') {
				continue;
			}

			if (mb_strlen($type) > 8) {
				throw new BerichtenboxSettingsRefusal(
					field: 'berichtTypes',
					message: $this->l->t('BerichtType %s is longer than the 8 characters Logius allows.', [$type])
				);
			}

			$clean[(string)$category] = $type;
		}

		return $clean;
	}//end berichtTypes()

	/**
	 * Check, encrypt and name the uploaded certificate.
	 *
	 * @param array<string,mixed> $certificate `certificatePem`, `privateKeyPem`, optional `passphrase` and `caBundlePem`.
	 * @param array<string,mixed> $config      The configuration: its authentication block and sender OIN.
	 *
	 * @return CertificateUpload The new block, the fingerprint reference and the warnings.
	 *
	 * @throws MtlsConfigurationException When the material cannot be used.
	 */
	private function encryptCertificate(array $certificate, array $config): CertificateUpload {
		$block = (array)($config['authentication'] ?? []);
		$block['mode'] = MtlsConfigResolver::MODE_MTLS;
		$block['mtls'] = $this->encryptedMaterial(certificate: $certificate);

		// Exactly the check a send makes, so a certificate that would fail at
		// send time is refused here, at upload.
		$bundle = $this->mtlsConfigResolver->resolve(authConfig: $block);
		$this->assertKeyMatches(bundle: $bundle);

		return new CertificateUpload(
			authentication: $block,
			certificateRef: 'sha256:' . (string)openssl_x509_fingerprint($bundle->certificatePem, 'sha256'),
			warnings: $this->serialWarnings(bundle: $bundle, senderOin: (string)($config['senderOin'] ?? ''))
		);
	}//end encryptCertificate()

	/**
	 * The uploaded material, encrypted: the passphrase is kept as given, the PEMs trimmed.
	 *
	 * @param array<string,mixed> $certificate The `certificate` form field.
	 *
	 * @return array<string,string>
	 */
	private function encryptedMaterial(array $certificate): array {
		$mtls = [
			'encryptedCertificate' => $this->crypto->encrypt(trim((string)$certificate['certificatePem'])),
			'encryptedPrivateKey' => $this->crypto->encrypt(trim((string)($certificate['privateKeyPem'] ?? ''))),
		];
		$passphrase = (string)($certificate['passphrase'] ?? '');
		if ($passphrase !== '') {
			$mtls['encryptedPassphrase'] = $this->crypto->encrypt($passphrase);
		}

		$caBundle = trim((string)($certificate['caBundlePem'] ?? ''));
		if ($caBundle !== '') {
			$mtls['encryptedCaBundle'] = $this->crypto->encrypt($caBundle);
		}

		return $mtls;
	}//end encryptedMaterial()

	/**
	 * Refuse a private key that does not belong to the certificate.
	 *
	 * @param MtlsCertificateBundle $bundle The resolved material.
	 *
	 * @return void
	 *
	 * @throws MtlsConfigurationException When the key does not match.
	 */
	private function assertKeyMatches(MtlsCertificateBundle $bundle): void {
		$key = openssl_pkey_get_private($bundle->privateKeyPem, (string)$bundle->passphrase);
		if ($key === false || openssl_x509_check_private_key($bundle->certificatePem, $key) === false) {
			throw new MtlsConfigurationException(
				message: $this->l->t('The private key does not belong to this certificate.'),
				errorCode: MtlsTransportException::ERROR_INVALID_PRIVATE_KEY
			);
		}
	}//end assertKeyMatches()

	/**
	 * The warning when the certificate's serial number is not the sender OIN.
	 *
	 * @param MtlsCertificateBundle $bundle    The resolved material.
	 * @param string                $senderOin The sender OIN, empty when none is set.
	 *
	 * @return list<string>
	 */
	private function serialWarnings(MtlsCertificateBundle $bundle, string $senderOin): array {
		$serial = (string)(openssl_x509_parse($bundle->certificatePem)['subject']['serialNumber'] ?? '');
		if ($senderOin === '' || $serial === $senderOin) {
			return [];
		}

		$shown = $serial;
		if ($shown === '') {
			$shown = '-';
		}

		$warning = $this->l->t(
			// phpcs:ignore Generic.Files.LineLength.MaxExceeded -- one translatable sentence; splitting it breaks the l10n catalogue match.
			'The certificate carries %1$s as its serial number, not the sender OIN %2$s. Logius refuses a letter whose OIN differs from the certificate.',
			[$shown, $senderOin]
		);
		return [$warning];
	}//end serialWarnings()

	/**
	 * Subject, OIN and expiry of the stored certificate, or why it cannot be read.
	 *
	 * @param array<string,mixed> $authentication The authentication block.
	 *
	 * @return array<string,mixed>
	 */
	private function certificateSummary(array $authentication): array {
		if (isset($authentication['mtls']) === false) {
			return ['configured' => false];
		}

		try {
			$bundle = $this->mtlsConfigResolver->resolve(authConfig: $authentication);
		} catch (MtlsConfigurationException $e) {
			return ['configured' => true, 'usable' => false, 'error' => $e->getMessage()];
		}

		$parsed = (array)openssl_x509_parse($bundle->certificatePem);

		return [
			'configured' => true,
			'usable' => true,
			'subject' => (string)($parsed['subject']['CN'] ?? ''),
			'serialNumber' => (string)($parsed['subject']['serialNumber'] ?? ''),
			'validTo' => gmdate('c', (int)($parsed['validTo_time_t'] ?? 0)),
			'caBundle' => ($bundle->caBundlePem !== null),
		];
	}//end certificateSummary()
}//end class

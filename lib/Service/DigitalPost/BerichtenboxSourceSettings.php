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
use OCA\Integriq\Service\Mtls\MtlsConfigResolver;
use OCA\Integriq\AppInfo\Application;
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
class BerichtenboxSourceSettings {
	/**
	 * The plain settings an administrator may set, and nothing else.
	 */
	public const FIELDS = ['senderOin', 'adapterUrl', 'cpaId', 'fromPartyId', 'toPartyId', 'service', 'wusEndpoint'];

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
		$config = ($data['configuration'] ?? []);
		if (is_string($config) === true) {
			$config = (array)json_decode($config, true);
		}

		$config['providerId'] = BerichtenboxProvider::ID;
		foreach (self::FIELDS as $field) {
			if (isset($params[$field]) === true) {
				$config[$field] = trim((string)$params[$field]);
			}
		}

		$this->assertFields(config: $config);

		if (is_array($params['berichtTypes'] ?? null) === true) {
			$config['berichtTypes'] = $this->berichtTypes(types: $params['berichtTypes']);
		}

		$authentication = (array)($config['authentication'] ?? []);
		$warnings = [];

		$certificate = ($params['certificate'] ?? null);
		if (is_array($certificate) === true && trim((string)($certificate['certificatePem'] ?? '')) !== '') {
			try {
				[$authentication, $config['certificateRef'], $warnings] = $this->encryptCertificate(
					certificate: $certificate,
					authentication: $authentication,
					senderOin: (string)($config['senderOin'] ?? '')
				);
			} catch (MtlsConfigurationException $e) {
				throw new BerichtenboxSettingsRefusal(field: 'certificate', message: $e->getMessage());
			}
		}

		$config['authentication'] = $this->withToken(authentication: $authentication, token: ($params['adapterToken'] ?? null));
		$data['configuration'] = $config;

		return ['data' => $data, 'warnings' => $warnings];
	}//end apply()

	/**
	 * Whether the live binding is on.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-the-live-binding-speaks-the-interface-logius-publishes-req-dpa-008
	 */
	public function live(): bool {
		$raw = $this->appConfig->getValueString(Application::APP_ID, 'logius.berichtenbox.feature_flag', '0');

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
		$config = ($data['configuration'] ?? []);
		if (is_string($config) === true) {
			$config = (array)json_decode($config, true);
		}

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
	 * @param array<string,mixed> $authentication The source's authentication block.
	 * @param string $senderOin The sender OIN, to compare with the certificate.
	 *
	 * @return array{0:array<string,mixed>,1:string,2:array<int,string>} The new block, the fingerprint, warnings.
	 *
	 * @throws MtlsConfigurationException When the material cannot be used.
	 */
	private function encryptCertificate(array $certificate, array $authentication, string $senderOin): array {
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

		$block = $authentication;
		$block['mode'] = MtlsConfigResolver::MODE_MTLS;
		$block['mtls'] = $mtls;

		// Exactly the check a send makes, so a certificate that would fail at
		// send time is refused here, at upload.
		$bundle = $this->mtlsConfigResolver->resolve(authConfig: $block);
		$key = openssl_pkey_get_private($bundle->privateKeyPem, (string)$bundle->passphrase);
		if ($key === false || openssl_x509_check_private_key($bundle->certificatePem, $key) === false) {
			throw new MtlsConfigurationException(
				message: $this->l->t('The private key does not belong to this certificate.'),
				errorCode: MtlsTransportException::ERROR_INVALID_PRIVATE_KEY
			);
		}

		$warnings = [];
		$serial = (string)(openssl_x509_parse($bundle->certificatePem)['subject']['serialNumber'] ?? '');
		if ($senderOin !== '' && $serial !== $senderOin) {
			$shown = $serial;
			if ($shown === '') {
				$shown = '-';
			}

			$warnings[] = $this->l->t(
				// phpcs:ignore Generic.Files.LineLength.MaxExceeded -- one translatable sentence; splitting it breaks the l10n catalogue match.
				'The certificate carries %1$s as its serial number, not the sender OIN %2$s. Logius refuses a letter whose OIN differs from the certificate.',
				[$shown, $senderOin]
			);
		}

		return [$block, 'sha256:' . (string)openssl_x509_fingerprint($bundle->certificatePem, 'sha256'), $warnings];
	}//end encryptCertificate()

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

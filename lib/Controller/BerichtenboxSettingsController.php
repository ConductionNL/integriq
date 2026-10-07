<?php

/**
 * Integriq — the administrator's Berichtenbox settings: OIN, CPA values, adapter and the certificate.
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
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

namespace OCA\Integriq\Controller;

use OCA\Integriq\AppInfo\Application;
use OCA\Integriq\Exception\MtlsConfigurationException;
use OCA\Integriq\Exception\MtlsTransportException;
use OCA\Integriq\Service\ConnectionStore;
use OCA\Integriq\Service\DigitalPost\BerichtenboxProvider;
use OCA\Integriq\Service\Mtls\MtlsConfigResolver;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One Berichtenbox source per organisation (design D1), configured here.
 *
 * The certificate and key arrive as PEM, are checked exactly as a send will
 * check them (MtlsConfigResolver), and are stored encrypted with ICrypto under
 * the source's `configuration.authentication.mtls`, a write-only path no
 * rendered read returns. The source then names the certificate by its
 * fingerprint in `certificateRef`. Nothing secret is ever answered: the GET
 * returns the certificate's subject, OIN and expiry, never the PEM or the key.
 *
 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-the-transport-certificate-is-held-encrypted-and-named-by-reference-req-dpa-004
 */
class BerichtenboxSettingsController extends Controller {
	/**
	 * The settings an administrator may set, and nothing else.
	 */
	private const FIELDS = ['senderOin', 'adapterUrl', 'cpaId', 'fromPartyId', 'toPartyId', 'service', 'wusEndpoint'];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param ConnectionStore $connectionStore Finds the source and reads it raw.
	 * @param OrObjectService $objectService Saves the source, as the administrator.
	 * @param MtlsConfigResolver $mtlsConfigResolver Checks the certificate the way a send will.
	 * @param ICrypto $crypto Encrypts the certificate, key and token at rest.
	 * @param IAppConfig $appConfig Reads the live flag.
	 * @param IL10N $l Messages.
	 * @param LoggerInterface $logger Diagnostics, never a secret.
	 */
	public function __construct(
		IRequest $request,
		private readonly ConnectionStore $connectionStore,
		private readonly OrObjectService $objectService,
		private readonly MtlsConfigResolver $mtlsConfigResolver,
		private readonly ICrypto $crypto,
		private readonly IAppConfig $appConfig,
		private readonly IL10N $l,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * One Berichtenbox source, without its secrets.
	 *
	 * @param string $slug The source slug.
	 *
	 * @return JSONResponse `{source: {...}, live: bool}`, or 404.
	 *
	 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#scenario-a-send-names-a-certificate-reference-not-a-key
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function getConfig(string $slug): JSONResponse {
		$source = $this->connectionStore->findSourceBySlug(slug: $slug);
		if ($source instanceof ObjectEntity === false) {
			return new JSONResponse(['source' => null, 'live' => $this->live()], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['source' => $this->describe(data: $this->connectionStore->readSourceRaw(source: $source)->getObject()), 'live' => $this->live()]);
	}//end getConfig()

	/**
	 * Set a Berichtenbox source, creating it when it does not exist.
	 *
	 * @param string $slug The source slug.
	 *
	 * @return JSONResponse The saved source, or 400 with field errors.
	 *
	 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-the-transport-certificate-is-held-encrypted-and-named-by-reference-req-dpa-004
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function setConfig(string $slug): JSONResponse {
		if (preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $slug) !== 1) {
			return $this->refuse(field: 'slug', message: $this->l->t('A source slug is lower-case letters, digits and hyphens.'));
		}

		$found = $this->connectionStore->findSourceBySlug(slug: $slug);
		$data = [
			'slug' => $slug,
			'name' => 'MijnOverheid Berichtenbox',
			'type' => 'digital-post',
			'configuration' => [],
		];
		$uuid = null;
		if ($found instanceof ObjectEntity === true) {
			$data = $this->connectionStore->readSourceRaw(source: $found)->getObject();
			$uuid = (string)$found->getUuid();
		}

		$config = ($data['configuration'] ?? []);
		if (is_string($config) === true) {
			$config = (array)json_decode($config, true);
		}

		$config['providerId'] = BerichtenboxProvider::ID;
		foreach (self::FIELDS as $field) {
			$value = $this->request->getParam($field);
			if ($value !== null) {
				$config[$field] = trim((string)$value);
			}
		}

		$error = $this->fieldError(config: $config);
		if ($error !== null) {
			return $this->refuse(field: $error[0], message: $error[1]);
		}

		$types = $this->request->getParam('berichtTypes');
		if (is_array($types) === true) {
			$clean = [];
			foreach ($types as $category => $type) {
				$type = trim((string)$type);
				if ($type === '') {
					continue;
				}

				if (mb_strlen($type) > 8) {
					return $this->refuse(field: 'berichtTypes', message: $this->l->t('BerichtType %s is longer than the 8 characters Logius allows.', [$type]));
				}

				$clean[(string)$category] = $type;
			}

			$config['berichtTypes'] = $clean;
		}

		$authentication = (array)($config['authentication'] ?? []);
		$warnings = [];

		$certificate = $this->request->getParam('certificate');
		if (is_array($certificate) === true && trim((string)($certificate['certificatePem'] ?? '')) !== '') {
			try {
				[$authentication, $fingerprint, $warnings] = $this->encryptCertificate(
					certificate: $certificate,
					authentication: $authentication,
					senderOin: (string)($config['senderOin'] ?? '')
				);
			} catch (MtlsConfigurationException $e) {
				return $this->refuse(field: 'certificate', message: $e->getMessage());
			}

			$config['certificateRef'] = $fingerprint;
		}

		$token = $this->request->getParam('adapterToken');
		if (is_string($token) === true && $token !== '') {
			$authentication['encryptedToken'] = $this->crypto->encrypt($token);
		}

		$config['authentication'] = $authentication;
		$data['configuration'] = $config;

		try {
			$saved = $this->objectService->saveObject(object: $data, register: ConnectionStore::REGISTER, schema: 'source', uuid: $uuid);
		} catch (Throwable $e) {
			$this->logger->error('[BerichtenboxSettingsController] the Berichtenbox source was not saved', ['exception' => $e->getMessage()]);
			return new JSONResponse(['errors' => [$this->l->t('The Berichtenbox source was not saved: %s', [$e->getMessage()])]], Http::STATUS_BAD_REQUEST);
		}

		$stored = $this->connectionStore->readSourceRaw(source: $saved)->getObject();

		return new JSONResponse(['source' => $this->describe(data: $stored), 'warnings' => $warnings, 'live' => $this->live()]);
	}//end setConfig()

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
		$pem = trim((string)$certificate['certificatePem']);
		$mtls = [
			'encryptedCertificate' => $this->crypto->encrypt($pem),
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
			throw new MtlsConfigurationException(message: $this->l->t('The private key does not belong to this certificate.'), errorCode: MtlsTransportException::ERROR_INVALID_PRIVATE_KEY);
		}

		$warnings = [];
		$serial = (string)(openssl_x509_parse($bundle->certificatePem)['subject']['serialNumber'] ?? '');
		if ($senderOin !== '' && $serial !== $senderOin) {
			$warnings[] = $this->l->t(
				'The certificate carries %1$s as its serial number, not the sender OIN %2$s. Logius refuses a letter whose OIN differs from the certificate.',
				[$serial === '' ? '-' : $serial, $senderOin]
			);
		}

		return [$block, 'sha256:' . (string)openssl_x509_fingerprint($bundle->certificatePem, 'sha256'), $warnings];
	}//end encryptCertificate()

	/**
	 * The first field that is not right, or null.
	 *
	 * @param array<string,mixed> $config The configuration.
	 *
	 * @return array{0:string,1:string}|null The field and the message.
	 */
	private function fieldError(array $config): ?array {
		$oin = (string)($config['senderOin'] ?? '');
		if ($oin !== '' && preg_match('/^\d{20}$/', $oin) !== 1) {
			return ['senderOin', $this->l->t('An OIN has 20 digits.')];
		}

		foreach (['adapterUrl', 'wusEndpoint'] as $field) {
			$url = (string)($config[$field] ?? '');
			if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL) === false) {
				return [$field, $this->l->t('%s is not a URL.', [$field])];
			}
		}

		$wus = (string)($config['wusEndpoint'] ?? '');
		if ($wus !== '' && str_starts_with($wus, 'https://') === false) {
			return ['wusEndpoint', $this->l->t('The subscription check goes over two-way TLS, so its endpoint starts with https://.')];
		}

		return null;
	}//end fieldError()

	/**
	 * The source as the settings page shows it: no PEM, no key, no token.
	 *
	 * @param array<string,mixed> $data The raw source.
	 *
	 * @return array<string,mixed>
	 */
	private function describe(array $data): array {
		$config = ($data['configuration'] ?? []);
		if (is_string($config) === true) {
			$config = (array)json_decode($config, true);
		}

		$described = ['slug' => (string)($data['slug'] ?? ''), 'berichtTypes' => (array)($config['berichtTypes'] ?? []), 'certificateRef' => (string)($config['certificateRef'] ?? '')];
		foreach (self::FIELDS as $field) {
			$described[$field] = (string)($config[$field] ?? '');
		}

		$described['certificate'] = $this->certificateSummary(authentication: (array)($config['authentication'] ?? []));
		$described['adapterToken'] = isset($config['authentication']['encryptedToken']);

		return $described;
	}//end describe()

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

	/**
	 * Whether the live binding is on.
	 *
	 * @return bool
	 */
	private function live(): bool {
		$raw = $this->appConfig->getValueString(Application::APP_ID, 'logius.berichtenbox.feature_flag', '0');

		return ($raw === '1' || strtolower($raw) === 'true');
	}//end live()

	/**
	 * A 400 with one field error.
	 *
	 * @param string $field The field.
	 * @param string $message The message.
	 *
	 * @return JSONResponse
	 */
	private function refuse(string $field, string $message): JSONResponse {
		return new JSONResponse(['errors' => [$message], 'fieldErrors' => [$field => $message]], Http::STATUS_BAD_REQUEST);
	}//end refuse()
}//end class

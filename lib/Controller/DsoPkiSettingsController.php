<?php

/**
 * DsoPkiSettingsController
 *
 * Admin-only API for reading and writing the DSO STAM PKIoverheid signature
 * verification configuration (signing mode, HMAC secret, certificate chain).
 * Both endpoints are gated at the middleware layer via
 * #[AuthorizedAdminSetting], so no in-body authorization is required.
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dso-stam-pkioverheid-signature-verification/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\AppInfo\Application;
use OCA\Integriq\Service\DSOSignatureVerifierService;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;

/**
 * Admin-only controller exposing the DSO STAM PKIoverheid signing
 * configuration used by {@see DSOSignatureVerifierService}.
 *
 * @spec openspec/changes/dso-stam-pkioverheid-signature-verification/tasks.md#task-2
 */
class DsoPkiSettingsController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param IAppConfig $appConfig App config storage.
	 * @param DSOSignatureVerifierService $signatureVerifier Chain-validation helper.
	 */
	public function __construct(
		IRequest $request,
		private readonly IAppConfig $appConfig,
		private readonly DSOSignatureVerifierService $signatureVerifier,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Get the current DSO PKI signing configuration.
	 *
	 * The HMAC secret is never returned in full — only whether one is set —
	 * so the admin form cannot leak the shared secret back over the wire.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/dso-stam-pkioverheid-signature-verification/tasks.md#task-2
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function getConfig(): JSONResponse {
		$hmacSecret = $this->appConfig->getValueString(
			Application::APP_ID,
			DSOSignatureVerifierService::CONFIG_HMAC_SECRET,
			''
		);

		return new JSONResponse(
			[
				'mode' => $this->signatureVerifier->getMode(),
				'hmacSecretConfigured' => ($hmacSecret !== ''),
				'signingCertificate' => $this->appConfig->getValueString(
					Application::APP_ID,
					DSOSignatureVerifierService::CONFIG_SIGNING_CERTIFICATE,
					''
				),
				'intermediateChain' => $this->appConfig->getValueString(
					Application::APP_ID,
					DSOSignatureVerifierService::CONFIG_INTERMEDIATE_CHAIN,
					''
				),
				'rootCa' => $this->appConfig->getValueString(
					Application::APP_ID,
					DSOSignatureVerifierService::CONFIG_ROOT_CA,
					''
				),
			]
		);

	}//end getConfig()

	/**
	 * Persist the DSO PKI signing configuration.
	 *
	 * Validates the certificate chain (parseable X.509, not expired, chains
	 * to the configured root) before saving in `rsa` mode, surfacing a
	 * clear admin-facing error and refusing to save otherwise.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/dso-stam-pkioverheid-signature-verification/tasks.md#task-2
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function setConfig(): JSONResponse {
		$mode = (string)$this->request->getParam('mode', DSOSignatureVerifierService::MODE_HMAC);
		if ($mode !== DSOSignatureVerifierService::MODE_RSA) {
			$mode = DSOSignatureVerifierService::MODE_HMAC;
		}

		$hmacSecret = (string)$this->request->getParam('hmacSecret', '');
		$signingCertificate = (string)$this->request->getParam('signingCertificate', '');
		$intermediateChain = (string)$this->request->getParam('intermediateChain', '');
		$rootCa = (string)$this->request->getParam('rootCa', '');

		if ($mode === DSOSignatureVerifierService::MODE_RSA) {
			$errors = $this->signatureVerifier->validateChainConfig(
				certPem: $signingCertificate,
				rootPem: $rootCa,
				intermediatePem: $intermediateChain
			);

			if (empty($errors) === false) {
				return new JSONResponse(
					['errors' => $errors],
					Http::STATUS_BAD_REQUEST
				);
			}
		}

		$this->appConfig->setValueString(Application::APP_ID, DSOSignatureVerifierService::CONFIG_MODE, $mode);
		$this->appConfig->setValueString(
			Application::APP_ID,
			DSOSignatureVerifierService::CONFIG_SIGNING_CERTIFICATE,
			$signingCertificate
		);
		$this->appConfig->setValueString(
			Application::APP_ID,
			DSOSignatureVerifierService::CONFIG_INTERMEDIATE_CHAIN,
			$intermediateChain
		);
		$this->appConfig->setValueString(Application::APP_ID, DSOSignatureVerifierService::CONFIG_ROOT_CA, $rootCa);

		// Only overwrite the HMAC secret when a non-empty value was submitted,
		// so the admin form can save other fields without re-typing (and
		// re-exposing) the secret every time.
		if ($hmacSecret !== '') {
			$this->appConfig->setValueString(
				Application::APP_ID,
				DSOSignatureVerifierService::CONFIG_HMAC_SECRET,
				$hmacSecret,
				sensitive: true
			);
		}

		return new JSONResponse(['mode' => $mode]);
	}//end setConfig()
}//end class

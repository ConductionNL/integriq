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
use OCA\Integriq\Service\ConnectionStore;
use OCA\Integriq\Service\DigitalPost\BerichtenboxSettingsRefusal;
use OCA\Integriq\Service\DigitalPost\BerichtenboxSourceSettings;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One Berichtenbox source per organisation (design D1), configured here.
 *
 * The checks and the encryption live in {@see BerichtenboxSourceSettings}; this
 * controller finds the source, saves it as the administrator (RBAC on) and
 * answers what the settings page may see.
 *
 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-the-transport-certificate-is-held-encrypted-and-named-by-reference-req-dpa-004
 */
class BerichtenboxSettingsController extends Controller {
	/**
	 * The request parameters the settings take.
	 */
	private const PARAMS = ['berichtTypes', 'certificate', 'adapterToken'];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param ConnectionStore $connectionStore Finds the source and reads it raw.
	 * @param OrObjectService $objectService Saves the source, as the administrator.
	 * @param BerichtenboxSourceSettings $settings Checks, encrypts and describes the settings.
	 * @param IL10N $l Messages.
	 * @param LoggerInterface $logger Diagnostics, never a secret.
	 */
	public function __construct(
		IRequest $request,
		private readonly ConnectionStore $connectionStore,
		private readonly OrObjectService $objectService,
		private readonly BerichtenboxSourceSettings $settings,
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
			return new JSONResponse(['source' => null, 'live' => $this->settings->live()], Http::STATUS_NOT_FOUND);
		}

		$raw = $this->connectionStore->readSourceRaw(source: $source)->getObject();

		return new JSONResponse(['source' => $this->settings->describe(data: $raw), 'live' => $this->settings->live()]);
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
		$data = ['slug' => $slug, 'name' => 'MijnOverheid Berichtenbox', 'type' => 'digital-post', 'configuration' => []];
		$uuid = null;
		if ($found instanceof ObjectEntity === true) {
			$data = $this->connectionStore->readSourceRaw(source: $found)->getObject();
			$uuid = (string)$found->getUuid();
		}

		$params = [];
		foreach (array_merge(BerichtenboxSourceSettings::FIELDS, self::PARAMS) as $name) {
			$params[$name] = $this->request->getParam($name);
		}

		try {
			$applied = $this->settings->apply(data: $data, params: $params);
		} catch (BerichtenboxSettingsRefusal $refusal) {
			return $this->refuse(field: $refusal->getField(), message: $refusal->getMessage());
		}

		try {
			$saved = $this->objectService->saveObject(object: $applied['data'], register: ConnectionStore::REGISTER, schema: 'source', uuid: $uuid);
		} catch (Throwable $e) {
			$this->logger->error('[BerichtenboxSettingsController] the Berichtenbox source was not saved', ['exception' => $e->getMessage()]);
			return new JSONResponse(
				['errors' => [$this->l->t('The Berichtenbox source was not saved: %s', [$e->getMessage()])]],
				Http::STATUS_BAD_REQUEST
			);
		}

		$stored = $this->connectionStore->readSourceRaw(source: $saved)->getObject();

		return new JSONResponse(
			['source' => $this->settings->describe(data: $stored), 'warnings' => $applied['warnings'], 'live' => $this->settings->live()]
		);
	}//end setConfig()

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

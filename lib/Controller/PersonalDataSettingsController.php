<?php

/**
 * Integriq PersonalDataSettingsController: the field names an administrator
 * adds to the personal data masked in logged bodies.
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
 * @link https://github.com/ConductionNL/integriq
 *
 * @spec openspec/changes/call-log-personal-data-masking/specs/outbound-call-log/spec.md#requirement-personal-data-is-masked-before-a-body-is-logged-req-pdm-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Kernel\AppIdentity;
use OCA\Integriq\Service\Security\PersonalDataMasker;
use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Reads and writes the app setting `personal_data_fields`. Admin only.
 *
 * @spec openspec/changes/call-log-personal-data-masking/specs/outbound-call-log/spec.md#requirement-personal-data-is-masked-before-a-body-is-logged-req-pdm-001
 */
class PersonalDataSettingsController extends Controller {

	/**
	 * The longest field name accepted.
	 *
	 * @var integer
	 */
	private const MAX_NAME_LENGTH = 100;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param IAppConfig $appConfig Stores the added field names.
	 * @param IL10N $l Translations.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IAppConfig $appConfig,
		private readonly IL10N $l,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The default personal data fields and the ones an administrator added.
	 *
	 * @return JSONResponse `{"defaults": [...], "added": [...]}`.
	 *
	 * @spec openspec/changes/call-log-personal-data-masking/specs/outbound-call-log/spec.md#requirement-personal-data-is-masked-before-a-body-is-logged-req-pdm-001
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function getConfig(): JSONResponse {
		return new JSONResponse($this->config());
	}//end getConfig()

	/**
	 * Replace the added field names.
	 *
	 * Takes `fields` as a list of names or as one comma-separated string. An
	 * empty list removes every addition; the default list always applies.
	 *
	 * @return JSONResponse The stored setting, or 400 naming the field that is refused.
	 *
	 * @spec openspec/changes/call-log-personal-data-masking/specs/outbound-call-log/spec.md#requirement-personal-data-is-masked-before-a-body-is-logged-req-pdm-001
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function setConfig(): JSONResponse {
		$fields = $this->request->getParam('fields', []);
		if (is_string($fields) === true) {
			$fields = explode(',', $fields);
		}

		if (is_array($fields) === false) {
			return new JSONResponse(
				['error' => $this->l->t('Send the field names as a list.')],
				Http::STATUS_BAD_REQUEST
			);
		}

		$names = [];
		foreach ($fields as $field) {
			if (is_scalar($field) === false) {
				continue;
			}

			$name = trim((string)$field);
			if ($name === '') {
				continue;
			}

			if (mb_strlen($name) > self::MAX_NAME_LENGTH || PersonalDataMasker::normalise(name: $name) === '') {
				return new JSONResponse(
					['error' => $this->l->t('"%s" is not a field name.', [mb_substr($name, 0, self::MAX_NAME_LENGTH)])],
					Http::STATUS_BAD_REQUEST
				);
			}

			$names[$name] = $name;
		}

		if ($names === []) {
			$this->appConfig->deleteKey(AppIdentity::APP_ID, SensitiveFieldRegistry::PERSONAL_DATA_CONFIG_KEY);
			return new JSONResponse($this->config());
		}

		$this->appConfig->setValueString(
			AppIdentity::APP_ID,
			SensitiveFieldRegistry::PERSONAL_DATA_CONFIG_KEY,
			(string)json_encode(array_values($names))
		);

		return new JSONResponse($this->config());
	}//end setConfig()

	/**
	 * The setting as the endpoint answers it.
	 *
	 * @return array{defaults: array<int, string>, added: array<int, string>}
	 */
	private function config(): array {
		return [
			'defaults' => SensitiveFieldRegistry::DEFAULT_PERSONAL_DATA_FIELDS,
			'added' => (new SensitiveFieldRegistry(appConfig: $this->appConfig))->addedPersonalDataFields(),
		];
	}//end config()
}//end class

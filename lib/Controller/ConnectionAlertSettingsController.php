<?php

/**
 * Integriq ConnectionAlertSettingsController: the group that hears about
 * connection alerts.
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
 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-an-opened-alert-notifies-the-group-an-administrator-named-req-crun-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\AppInfo\Application;
use OCA\Integriq\Notification\ConnectionAlertRecipientResolver;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Reads and sets the app setting `connection_alert_group`, admin only.
 *
 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-an-opened-alert-notifies-the-group-an-administrator-named-req-crun-005
 */
class ConnectionAlertSettingsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param IAppConfig $appConfig The app configuration.
	 * @param IGroupManager $groupManager The group manager.
	 * @param IL10N $l The localization service.
	 */
	public function __construct(
		IRequest $request,
		private readonly IAppConfig $appConfig,
		private readonly IGroupManager $groupManager,
		private readonly IL10N $l,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The group named for connection alerts, `admin` when none is.
	 *
	 * @return JSONResponse `{group}`.
	 *
	 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-an-opened-alert-notifies-the-group-an-administrator-named-req-crun-005
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function getConfig(): JSONResponse {
		return new JSONResponse(
			['group' => ConnectionAlertRecipientResolver::namedGroup(appConfig: $this->appConfig)]
		);
	}//end getConfig()

	/**
	 * Name the group, or clear it with an empty value, which puts `admin` back.
	 *
	 * @return JSONResponse `{group}`, or 400 naming a group that does not exist.
	 *
	 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-an-opened-alert-notifies-the-group-an-administrator-named-req-crun-005
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function setConfig(): JSONResponse {
		$group = trim((string)$this->request->getParam('group', ''));
		if ($group !== '' && $this->groupManager->get($group) === null) {
			return new JSONResponse(
				['error' => $this->l->t('There is no group called %s.', [$group])],
				Http::STATUS_BAD_REQUEST
			);
		}

		if ($group === '') {
			$this->appConfig->deleteKey(Application::APP_ID, ConnectionAlertRecipientResolver::CONFIG_KEY);
			return new JSONResponse(['group' => ConnectionAlertRecipientResolver::DEFAULT_GROUP]);
		}

		$this->appConfig->setValueString(Application::APP_ID, ConnectionAlertRecipientResolver::CONFIG_KEY, $group);

		return new JSONResponse(['group' => $group]);
	}//end setConfig()
}//end class

<?php

/**
 * Integriq Settings Controller
 *
 * Integriq's log retention on the admin page: the settings read and update in
 * the index and update shape of ADR-076, and "Apply to existing logs", which
 * queues a background job. All three are for administrators only; Nextcloud's
 * middleware refuses anyone else with 403 before a method runs.
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-an-administrator-sets-retention-on-the-admin-page-req-adef-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use InvalidArgumentException;
use OCA\Integriq\Service\SettingsServiceInterface;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Controller for integriq's retention settings.
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 *
 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-an-administrator-sets-retention-on-the-admin-page-req-adef-002
 */
class SettingsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                   $appName         The app identifier.
	 * @param IRequest                 $request         The current request.
	 * @param SettingsServiceInterface $settingsService Reads, changes and rebases the retention.
	 * @param LoggerInterface          $logger          Logger instance.
	 * @param IL10N                    $l               Translations.
	 *
	 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-an-administrator-sets-retention-on-the-admin-page-req-adef-002
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly SettingsServiceInterface $settingsService,
		private readonly LoggerInterface $logger,
		private readonly IL10N $l,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The retention every log kind gets now, its default and the last rebase.
	 *
	 * @return JSONResponse The settings.
	 *
	 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-an-administrator-sets-retention-on-the-admin-page-req-adef-002
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function index(): JSONResponse {
		return new JSONResponse($this->settingsService->getSettings());
	}//end index()

	/**
	 * Change the retention. A refused value answers 400 naming the field.
	 *
	 * @return JSONResponse The settings after the change, or the refusal.
	 *
	 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-an-administrator-sets-retention-on-the-admin-page-req-adef-002
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function update(): JSONResponse {
		try {
			return new JSONResponse(
				$this->settingsService->updateSettings(['retention' => $this->request->getParam('retention')])
			);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(['error' => $exception->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}//end update()

	/**
	 * Queue the job that applies the current retention to existing logs.
	 *
	 * @return JSONResponse What was queued, or why not.
	 *
	 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-changed-retention-can-be-applied-to-existing-logs-req-adef-003
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function rebase(): JSONResponse {
		$kinds = $this->request->getParam('kinds', []);
		if (is_array($kinds) === false) {
			$kinds = [$kinds];
		}

		try {
			return new JSONResponse($this->settingsService->rebase(array_values($kinds)), Http::STATUS_ACCEPTED);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(['error' => $exception->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (Throwable $exception) {
			return $this->rebaseFailed(exception: $exception);
		}
	}//end rebase()

	/**
	 * Log a rebase that could not be queued and answer 500.
	 *
	 * @param Throwable $exception What went wrong.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-changed-retention-can-be-applied-to-existing-logs-req-adef-003
	 */
	private function rebaseFailed(Throwable $exception): JSONResponse {
		$this->logger->error('Failed to queue the retention rebase', ['exception' => $exception->getMessage()]);
		return new JSONResponse(
			['error' => $this->l->t('Failed to perform rebase operation')],
			Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}//end rebaseFailed()
}//end class

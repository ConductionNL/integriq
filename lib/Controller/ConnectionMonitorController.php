<?php

/**
 * Integriq ConnectionMonitorController: the connection monitor report for one source.
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
 * @spec openspec/specs/connection-run-monitoring/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use DateTimeImmutable;
use OCA\Integriq\Service\ConnectionMonitorServiceInterface;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Administrators only: the report names a connection's error pattern and certificate state.
 *
 * @spec openspec/specs/connection-run-monitoring/spec.md
 */
class ConnectionMonitorController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                    $appName The app id.
	 * @param IRequest                  $request The request.
	 * @param ConnectionMonitorServiceInterface  $monitor Builds the report.
	 * @param IL10N                     $l       Translations.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly ConnectionMonitorServiceInterface $monitor,
		private readonly IL10N $l,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The last 24 hours of one source, named by uuid or slug in `?source=`.
	 *
	 * @return JSONResponse The report, 400 without a source, 404 for an unknown one.
	 *
	 * @spec openspec/changes/brp-connection-monitor-from-pipelinq/specs/connection-run-monitoring/spec.md#requirement-a-source-shows-how-it-performed-in-the-last-24-hours-req-crun-006
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function show(): JSONResponse {
		$source = trim((string)$this->request->getParam('source', ''));
		if ($source === '') {
			return new JSONResponse(['error' => $this->l->t('Name the source to monitor.')], Http::STATUS_BAD_REQUEST);
		}

		$report = $this->monitor->report(sourceRef: $source, now: new DateTimeImmutable());
		if ($report === null) {
			return new JSONResponse(['error' => $this->l->t('There is no such source.')], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($report);
	}//end show()
}//end class

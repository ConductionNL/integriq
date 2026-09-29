<?php

/**
 * Integriq RunSummaryController: a source's pulls per day.
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
 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-a-source-shows-its-pulls-per-day-req-crun-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Integriq\AppInfo\Application;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\RunSummaryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * GET /api/sources/{id}/run-summary, behind the action `source.logs`.
 *
 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-a-source-shows-its-pulls-per-day-req-crun-002
 */
class RunSummaryController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param RunSummaryService $runSummary Sums a source's runs per day.
	 * @param IUserSession $userSession The user session.
	 * @param ActionAuthService $actionAuth The action authorization service.
	 * @param IL10N $l The localization service.
	 */
	public function __construct(
		IRequest $request,
		private readonly RunSummaryService $runSummary,
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
		private readonly IL10N $l,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * A source's pulls per day, and its latest runs.
	 *
	 * `?from=Y-m-d&to=Y-m-d`; the window defaults to the last seven days and
	 * may be at most 31 days long.
	 *
	 * @param string $id The source.
	 *
	 * @return JSONResponse `{sourceId, from, to, days[], runs[]}`, or 400 naming what is wrong with the window.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-a-source-shows-its-pulls-per-day-req-crun-002
	 */
	#[NoAdminRequired]
	public function show(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: 'source.logs');

		try {
			[$from, $to] = $this->runSummary->window(
				from: $this->param(name: 'from'),
				to: $this->param(name: 'to'),
				today: new DateTimeImmutable('today')
			);
		} catch (InvalidArgumentException $exception) {
			$message = $this->l->t('Give the dates as year-month-day, for example 2026-09-28.');
			if ($exception->getCode() === RunSummaryService::WINDOW_TOO_LONG) {
				$message = $this->l->t('Choose a window of at most %s days that ends after it starts.', [(string)RunSummaryService::MAX_WINDOW_DAYS]);
			}

			return new JSONResponse(['error' => $message], Http::STATUS_BAD_REQUEST);
		}

		$summary = $this->runSummary->summarise(sourceId: $id, from: $from, to: $to);

		return new JSONResponse(
			[
				'sourceId' => $id,
				'from' => $from->format('Y-m-d'),
				'to' => $to->format('Y-m-d'),
				'days' => $summary['days'],
				'runs' => $summary['runs'],
			]
		);
	}//end show()

	/**
	 * A query parameter as a string, or null when it is absent.
	 *
	 * @param string $name The parameter.
	 *
	 * @return string|null
	 */
	private function param(string $name): ?string {
		$value = $this->request->getParam($name);
		if (is_scalar($value) === false) {
			return null;
		}

		return (string)$value;
	}//end param()
}//end class

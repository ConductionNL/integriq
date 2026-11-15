<?php

/**
 * Integriq dashboard data-source resolve controller.
 *
 * Exposes the read-only `dashboard-http-datasource` resolve façade at
 * `POST /api/datasource/{sourceId}/resolve` for authenticated dashboard/
 * widget hosts (LaunchPad's `live-data-tile-widget` is the first consumer).
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/dashboard-http-datasource/specs/dashboard-http-datasource/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Service\Datasource\DashboardDatasourceServiceInterface;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for the `dashboard-http-datasource` resolve endpoint.
 *
 * @spec openspec/changes/dashboard-http-datasource/specs/dashboard-http-datasource/spec.md
 */
class DatasourceController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app id.
	 * @param IRequest $request The request object.
	 * @param IL10N $l The localization service.
	 * @param LoggerInterface $logger Logger for unexpected resolve failures.
	 *
	 * @return void
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly IL10N $l,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Resolve a single value from a named source.
	 *
	 * Authenticated NC user only (enforced by `#[NoAdminRequired]`'s implicit
	 * "logged-in user required" — no `#[PublicPage]`). Honours the source's
	 * own read-authorization: a source the current user may not read yields
	 * 403, never a fetch. Any `url`/`host` in `params` is ignored — egress is
	 * always derived from the stored source, never the caller.
	 *
	 * @param DashboardDatasourceServiceInterface $service The resolve façade service.
	 * @param string $sourceId UUID of the source to resolve against.
	 *
	 * @return JSONResponse `{value, fetchedAt, stale}` on success; 400/403/404/500 with an `error` key otherwise.
	 *
	 * @spec openspec/changes/dashboard-http-datasource/specs/dashboard-http-datasource/spec.md#requirement-resolve-endpoint-returns-a-single-value-from-a-named-source
	 * @spec openspec/changes/dashboard-http-datasource/specs/dashboard-http-datasource/spec.md#requirement-egress-is-constrained-to-the-source-never-the-caller
	 * @spec openspec/changes/dashboard-http-datasource/specs/dashboard-http-datasource/spec.md#requirement-caller-authorization-honours-the-sources-read-access
	 */
	#[NoAdminRequired]
	public function resolve(DashboardDatasourceServiceInterface $service, string $sourceId): JSONResponse {
		$body = $this->request->getParams();
		$valueExpr = (string)($body['valueExpr'] ?? '');

		if ($valueExpr === '') {
			return $this->missingValueExpr();
		}

		try {
			$result = $service->resolve(sourceId: $sourceId, valueExpr: $valueExpr, params: $this->callerParams(body: $body), ttl: $this->ttlOf(body: $body));
		} catch (\Throwable $e) {
			return $this->resolveFailure(exception: $e);
		}

		return new JSONResponse($result);
	}//end resolve()

	/**
	 * Answer a request without a value expression.
	 *
	 * @return JSONResponse The 400 error.
	 */
	private function missingValueExpr(): JSONResponse {
		return new JSONResponse(
			['error' => $this->l->t('valueExpr is required')],
			Http::STATUS_BAD_REQUEST
		);
	}//end missingValueExpr()

	/**
	 * The caller's params, without any egress override.
	 *
	 * Egress guard, defence-in-depth: also strip url/host here, ahead of
	 * the service's own guard, so no caller-supplied override ever
	 * leaves the controller.
	 *
	 * @param array<string,mixed> $body The request body.
	 *
	 * @return array<string,mixed> The params (none unless the body carries a list), without `url` and `host`.
	 */
	private function callerParams(array $body): array {
		$params = ($body['params'] ?? []);
		if (is_array($params) === false) {
			$params = [];
		}

		unset($params['url'], $params['host']);
		return $params;
	}//end callerParams()

	/**
	 * The cache ttl the caller asks for.
	 *
	 * @param array<string,mixed> $body The request body.
	 *
	 * @return int|null The ttl in seconds, or null when the body carries no number.
	 */
	private function ttlOf(array $body): int|null {
		if (isset($body['ttl']) === true && is_numeric($body['ttl']) === true) {
			return (int)$body['ttl'];
		}

		return null;
	}//end ttlOf()

	/**
	 * Answer a resolve that failed: 403 for a source the user may not read,
	 * 404 for one that does not exist, else log it and answer 500.
	 *
	 * @param \Throwable $exception The failure.
	 *
	 * @return JSONResponse The error.
	 */
	private function resolveFailure(\Throwable $exception): JSONResponse {
		if ($exception instanceof NotAuthorizedException) {
			return new JSONResponse(['error' => $this->l->t('Not authorized to read this source')], Http::STATUS_FORBIDDEN);
		}

		if ($exception instanceof DoesNotExistException) {
			return new JSONResponse(['error' => $this->l->t('Source not found')], Http::STATUS_NOT_FOUND);
		}

		$this->logger->error('dashboard-http-datasource: resolve failed unexpectedly: ' . $exception->getMessage(), ['exception' => $exception]);
		return new JSONResponse(['error' => $this->l->t('Failed to resolve value')], Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end resolveFailure()
}//end class

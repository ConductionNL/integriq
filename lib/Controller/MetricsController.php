<?php

/**
 * OpenConnector Metrics Controller — AppHost adapter with OpenRegister guard.
 *
 * Thin app-namespace metrics endpoint (URL `/api/metrics`, route name
 * `metrics#index`, both unchanged). It carries no metric logic of its own:
 * when OpenRegister is present it delegates to the OpenRegister AppHost
 * {@see \OCA\OpenRegister\AppHost\Controller\GenericMetricsController}, which
 * reads openconnector's `src/manifest.json` `observability.metrics` block
 * (resolved by `appName`) and renders Prometheus text exposition 0.0.4.
 *
 * This class deliberately extends {@see \OCP\AppFramework\Controller} rather
 * than the OpenRegister generic. A parent class must be loaded before the
 * child can be declared, and Nextcloud's router `ReflectionClass`es EVERY
 * controller while matching a route — so extending a class from an app that
 * may be absent turns a missing optional dependency into an HTTP 500 on every
 * route in openconnector, not just on `/api/metrics`. Injecting the engine
 * controller as a nullable delegate avoids that: a nullable *parameter type*
 * is never autoloaded (only `extends`/`implements` are resolved at class
 * declaration time), and this service is built by an explicit factory in
 * {@see \OCA\OpenConnector\AppInfo\Application::registerAppHostObservability()},
 * so the container never autowires — and therefore never resolves — that
 * parameter.
 *
 * This mirrors the guard already used by the sibling
 * {@see \OCA\OpenConnector\Controller\HealthController}.
 *
 * The metrics endpoint stays admin-only: the method declares no
 * `#[NoAdminRequired]`, so the Nextcloud SecurityMiddleware requires an admin
 * session — the intended ADR-006 posture, owned by the engine and preserved
 * here.
 *
 * @category Controller
 * @package  OCA\OpenConnector\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Controller;

use OCA\OpenRegister\AppHost\Controller\GenericMetricsController;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TextPlainResponse;
use OCP\IRequest;

/**
 * Admin-only declarative Prometheus metrics endpoint, delegated to the engine.
 *
 * @spec openspec/specs/apphost-adoption/spec.md
 */
class MetricsController extends Controller {

	/**
	 * The required dependency app id.
	 *
	 * @var string
	 */
	private const REQUIRED_APP = 'openregister';

	/**
	 * Prometheus text exposition content type (mirrors the engine's renderer).
	 *
	 * @var string
	 */
	private const CONTENT_TYPE = 'text/plain; version=0.0.4; charset=utf-8';

	/**
	 * Constructor.
	 *
	 * @param string $appName Calling app id (openconnector).
	 * @param IRequest $request HTTP request.
	 * @param IAppManager $appManager App-enablement query service (never touches OpenRegister classes).
	 * @param GenericMetricsController|null $delegate Engine metrics controller, or null when OpenRegister is absent.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IAppManager $appManager,
		private readonly ?GenericMetricsController $delegate = null,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * GET /api/metrics — declarative Prometheus metrics (admin-only, ADR-006).
	 *
	 * Admin-only by the deliberate absence of `#[NoAdminRequired]`.
	 *
	 * Returns HTTP 503 with a Prometheus comment line when the AppHost engine
	 * is unavailable (OpenRegister absent or disabled) — never a 500, and
	 * without referencing any OpenRegister class on that path.
	 *
	 * @return TextPlainResponse Prometheus text exposition 0.0.4.
	 *
	 * @spec openspec/specs/apphost-adoption/spec.md — Requirement: Declarative Metrics Parity
	 */
	#[NoCSRFRequired]
	public function index(): TextPlainResponse {
		if ($this->appManager->isEnabledForAnyone(self::REQUIRED_APP) === false || $this->delegate === null) {
			$response = new TextPlainResponse(
				'# metrics unavailable: OpenConnector requires the OpenRegister app — install and enable it.' . "\n",
				Http::STATUS_SERVICE_UNAVAILABLE
			);
			$response->addHeader('Content-Type', self::CONTENT_TYPE);

			return $response;
		}

		return $this->delegate->index();
	}//end index()
}//end class

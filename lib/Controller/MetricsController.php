<?php

/**
 * OpenConnector Metrics Controller — AppHost adapter.
 *
 * Thin app-namespace subclass of the OpenRegister AppHost
 * {@see \OCA\OpenRegister\AppHost\Controller\GenericMetricsController}. It
 * carries no metric logic of its own: the engine reads openconnector's
 * `src/manifest.json` `observability.metrics` block (resolved by `appName`)
 * and renders Prometheus text exposition 0.0.4. This class exists only so the
 * `metrics#index` route name (URL `/api/metrics`, unchanged) resolves in
 * openconnector's namespace and so the admin-only posture is declared on a
 * concrete openconnector method (ADR-006 / ADR-016).
 *
 * The metrics endpoint stays admin-only: the method declares no
 * `#[NoAdminRequired]`, so the Nextcloud SecurityMiddleware requires an admin
 * session — the intended ADR-006 posture, owned by the engine and preserved
 * here. The constructor + service wiring (resolving `MetricsEngine` from
 * OpenRegister's app container, with `appName = openconnector`) is registered
 * in {@see \OCA\OpenConnector\AppInfo\Application::registerAppHostObservability()}.
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
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TextPlainResponse;

/**
 * Admin-only declarative Prometheus metrics endpoint, delegated to the engine.
 *
 * @spec openspec/specs/apphost-adoption/spec.md
 */
class MetricsController extends GenericMetricsController
{
    /**
     * GET /api/metrics — declarative Prometheus metrics (admin-only, ADR-006).
     *
     * Delegates entirely to the engine. No `#[NoAdminRequired]` — admin
     * session required (engine-owned posture).
     *
     * @return TextPlainResponse Prometheus text exposition 0.0.4.
     *
     * @spec openspec/specs/apphost-adoption/spec.md — Requirement: Declarative Metrics Parity
     */
    #[NoCSRFRequired]
    public function index(): TextPlainResponse
    {
        return parent::index();
    }//end index()
}//end class

<?php

/**
 * OpenConnector Health Controller — AppHost adapter.
 *
 * Thin app-namespace subclass of the OpenRegister AppHost
 * {@see \OCA\OpenRegister\AppHost\Controller\GenericHealthController}. It
 * carries no health logic of its own: the engine reads openconnector's
 * `src/manifest.json` `observability.health` block (resolved by `appName`)
 * and renders the ADR-006 `{status, app, version, checks}` shape. This class
 * exists only so the `health#index` route name (URL `/api/health`, unchanged)
 * resolves in openconnector's namespace and so the public auth posture is
 * declared on a concrete openconnector method (ADR-006 / ADR-016).
 *
 * The constructor + service wiring (resolving the engine collaborators from
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

use OCA\OpenRegister\AppHost\Controller\GenericHealthController;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Public declarative health endpoint, delegated to the AppHost engine.
 *
 * @spec openspec/changes/adopt-apphost/specs/apphost-adoption/spec.md
 */
class HealthController extends GenericHealthController
{
    /**
     * GET /api/health — declarative health check (ADR-006, public).
     *
     * Delegates entirely to the engine; the posture (public, no CSRF) is
     * re-declared here so it is owned by a concrete openconnector route
     * target, fixing the pre-existing admin-only-health defect.
     *
     * @return JSONResponse `{status, app, version, checks}` with HTTP code per statusCodePolicy.
     *
     * @spec openspec/changes/adopt-apphost/specs/apphost-adoption/spec.md — Requirement: Declarative Health per ADR-006
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function index(): JSONResponse
    {
        return parent::index();
    }//end index()
}//end class

<?php

/**
 * OpenConnector Health Controller — AppHost adapter with OpenRegister guard.
 *
 * Thin app-namespace health endpoint (URL `/api/health`, route name
 * `health#index`, both unchanged). It carries no health logic of its own for
 * the healthy path: when OpenRegister is present it delegates to the
 * OpenRegister AppHost {@see \OCA\OpenRegister\AppHost\Controller\GenericHealthController},
 * which reads openconnector's `src/manifest.json` `observability.health` block
 * and renders the ADR-006 `{status, app, version, checks}` shape.
 *
 * OpenRegister is a hard runtime dependency (all entities are OpenRegister
 * objects). When it is absent the delegate cannot be built, so this controller
 * detects the missing dependency using `IAppManager` ONLY — it never references
 * an `OCA\OpenRegister\*` class in that path — and returns HTTP 503 naming the
 * missing dependency instead of the bare DI 500 the app would otherwise emit
 * (REQ-ADM-003). The `?GenericHealthController` delegate is injected as null
 * when OpenRegister is disabled, which does not trigger autoloading of the
 * OpenRegister class.
 *
 * The constructor + delegate wiring is registered in
 * {@see \OCA\OpenConnector\AppInfo\Application::registerAppHostObservability()}.
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
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Public declarative health endpoint with an OpenRegister dependency guard.
 *
 * @spec openspec/specs/apphost-adoption/spec.md
 */
class HealthController extends Controller {

	/**
	 * The required dependency app id.
	 *
	 * @var string
	 */
	private const REQUIRED_APP = 'openregister';

	/**
	 * Constructor.
	 *
	 * @param string $appName Calling app id (openconnector).
	 * @param IRequest $request HTTP request.
	 * @param IAppManager $appManager App-enablement query service (never touches OpenRegister classes).
	 * @param GenericHealthController|null $delegate Engine health controller, or null when OpenRegister is absent.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IAppManager $appManager,
		private readonly ?GenericHealthController $delegate = null,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * GET /api/health — declarative health check (ADR-006, public).
	 *
	 * When OpenRegister is disabled/absent, returns HTTP 503 naming the missing
	 * dependency (REQ-ADM-003) without referencing any OpenRegister class.
	 * Otherwise delegates to the AppHost engine.
	 *
	 * @return JSONResponse `{status, app, version, checks}` per statusCodePolicy, or 503 when OpenRegister is missing.
	 *
	 * @spec openspec/specs/app-distribution-metadata/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function index(): JSONResponse {
		if ($this->appManager->isEnabledForAnyone(self::REQUIRED_APP) === false || $this->delegate === null) {
			return new JSONResponse(
				[
					'status' => 'unhealthy',
					'app' => $this->appName,
					'checks' => [
						[
							'name' => 'openregister-dependency',
							'status' => 'unhealthy',
							'message' => 'OpenConnector requires the OpenRegister app — install and enable it.',
						],
					],
				],
				Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		return $this->delegate->index();
	}//end index()
}//end class

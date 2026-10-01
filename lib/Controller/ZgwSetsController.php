<?php

/**
 * Admin routes that list the packaged ZGW sets and install one against a schema.
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md#requirement-a-set-binds-to-an-operator-chosen-register-and-schema-req-zgwc-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\AppInfo\Application;
use OCA\Integriq\Service\Zgw\ZgwSetCatalogue;
use OCA\Integriq\Service\Zgw\ZgwSetInstaller;
use OCA\Integriq\Service\Zgw\ZgwSetInstallRefusedException;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * GET /api/zgw-sets and POST /api/zgw-sets/{slug}/install, admin only.
 *
 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md#requirement-a-set-binds-to-an-operator-chosen-register-and-schema-req-zgwc-002
 */
class ZgwSetsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest        $request   The request.
	 * @param ZgwSetInstaller $installer Installs a set and reads the bindings.
	 *
	 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md#requirement-a-set-binds-to-an-operator-chosen-register-and-schema-req-zgwc-002
	 */
	public function __construct(
		IRequest $request,
		private readonly ZgwSetInstaller $installer,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Every packaged set, whether it writes back, and the schema it is bound to.
	 *
	 * @return JSONResponse {results: list<{slug, title, writesBack, binding}>}
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ZgwSetCatalogue is a final table of constants with pure lookups; there is nothing to inject.
	 *
	 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md#requirement-a-set-binds-to-an-operator-chosen-register-and-schema-req-zgwc-002
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function index(): JSONResponse {
		$boundTo = array_flip($this->installer->bindings());
		$results = [];
		foreach (ZgwSetCatalogue::SETS as $slug => $title) {
			$results[] = [
				'slug'       => $slug,
				'title'      => $title,
				'writesBack' => ZgwSetCatalogue::writesBack(slug: $slug),
				'binding'    => ($boundTo[$slug] ?? null),
			];
		}

		return new JSONResponse(['results' => $results]);
	}//end index()

	/**
	 * Install a set against the register and schema in the request body.
	 *
	 * @param string $slug The set slug.
	 *
	 * @return JSONResponse The binding, or 409 with the refusal.
	 *
	 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md#requirement-a-set-binds-to-an-operator-chosen-register-and-schema-req-zgwc-002
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function install(string $slug): JSONResponse {
		try {
			$result = $this->installer->install(
				slug: $slug,
				register: (string)$this->request->getParam('register', ''),
				schema: (string)$this->request->getParam('schema', '')
			);
		} catch (ZgwSetInstallRefusedException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
		}

		return new JSONResponse($result);
	}//end install()
}//end class

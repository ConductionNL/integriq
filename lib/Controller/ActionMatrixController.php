<?php

/**
 * ActionMatrixController
 *
 * Admin-only API for reading and writing the ADR-023 action-authorization
 * matrix. Both endpoints are gated at the middleware layer via
 * #[AuthorizedAdminSetting], so no in-body authorization is required.
 *
 * @category Controller
 * @package  OCA\OpenConnector\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/architecture/adr-023-action-authorization.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Controller;

use OCA\OpenConnector\AppInfo\Application;
use OCA\OpenConnector\Service\ActionAuthService;
use OCA\OpenConnector\Settings\OpenConnectorAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;

/**
 * Admin-only controller exposing the action-authorization matrix (ADR-023).
 *
 * @spec openspec/architecture/adr-023-action-authorization.md
 */
class ActionMatrixController extends Controller
{
    private const SEED_PATH = __DIR__.'/../actions.seed.json';

    /**
     * Constructor.
     *
     * @param IRequest          $request      The request.
     * @param ActionAuthService $actionAuth   The action authorization service.
     * @param IGroupManager     $groupManager The group manager.
     */
    public function __construct(
        IRequest $request,
        private readonly ActionAuthService $actionAuth,
        private readonly IGroupManager $groupManager,
    ) {
        parent::__construct(
            appName: Application::APP_ID,
            request: $request
        );
    }//end __construct()

    /**
     * Get the full action matrix, the complete action key list, and all groups.
     *
     * The action key list is the union of the keys currently in the matrix and
     * the keys declared in the seed file, so the admin sees every declared
     * action even before any customization.
     *
     * @return JSONResponse The matrix, action keys, and group IDs.
     *
     * @spec openspec/architecture/adr-023-action-authorization.md
     */
    #[AuthorizedAdminSetting(OpenConnectorAdmin::class)]
    public function getMatrix(): JSONResponse
    {
        $matrix = $this->actionAuth->getMatrix();

        $actionKeys = array_keys($matrix);
        foreach ($this->seedActionKeys() as $key) {
            if (in_array($key, $actionKeys, true) === false) {
                $actionKeys[] = $key;
            }
        }

        sort($actionKeys);

        $groups = [];
        foreach ($this->groupManager->search('') as $group) {
            $groups[] = $group->getGID();
        }

        return new JSONResponse(
            [
                'matrix'  => $matrix,
                'actions' => $actionKeys,
                'groups'  => $groups,
            ]
        );

    }//end getMatrix()

    /**
     * Persist the action matrix.
     *
     * Reads the `matrix` parameter from the request body and writes it through
     * the action authorization service (which normalizes the shape).
     *
     * @return JSONResponse The normalized matrix after the write.
     *
     * @spec openspec/architecture/adr-023-action-authorization.md
     */
    #[AuthorizedAdminSetting(OpenConnectorAdmin::class)]
    public function setMatrix(): JSONResponse
    {
        $matrix = $this->request->getParam('matrix');
        if (is_array($matrix) === false) {
            $matrix = [];
        }

        try {
            $this->actionAuth->setMatrix($matrix);
        } catch (\JsonException $e) {
            return new JSONResponse(
                ['error' => 'Could not encode the action matrix: '.$e->getMessage()],
                \OCP\AppFramework\Http::STATUS_BAD_REQUEST
            );
        }

        return new JSONResponse(['matrix' => $this->actionAuth->getMatrix()]);

    }//end setMatrix()

    /**
     * Read the action keys declared in the seed file.
     *
     * @return array<int, string>
     */
    private function seedActionKeys(): array
    {
        if (file_exists(self::SEED_PATH) === false) {
            return [];
        }

        $raw = file_get_contents(self::SEED_PATH);
        if ($raw === false) {
            return [];
        }

        try {
            $parsed = json_decode($raw, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return [];
        }

        $actions = ($parsed['actions'] ?? null);
        if (is_array($actions) === false) {
            return [];
        }

        $keys = [];
        foreach (array_keys($actions) as $key) {
            if (is_string($key) === true) {
                $keys[] = $key;
            }
        }

        return $keys;

    }//end seedActionKeys()
}//end class

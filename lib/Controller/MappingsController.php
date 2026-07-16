<?php
/**
 * OpenConnector MappingsController.
 *
 * Controller for mapping pages, mapping execution tests, and persistence helpers
 * exposed to the OpenConnector frontend.
 *
 * @category Controller
 * @package  OCA\OpenConnector\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 */

namespace OCA\OpenConnector\Controller;

use Exception;
use OCA\OpenConnector\Service\ActionAuthService;
use OCA\OpenConnector\Service\SourceMappingService;
use OCA\OpenConnector\Service\MappingService;
use OCA\OpenConnector\Settings\OpenConnectorAdmin;
use OCA\OpenRegister\Db\RegisterMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Controller for mapping execution tests and persistence helpers.
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.MissingImport)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
 */
class MappingsController extends Controller
{
    /**
     * Constructor for the MappingsController.
     *
     * @param string               $appName        The name of the app.
     * @param IRequest             $request        The request object.
     * @param MappingService       $mappingService The mapping service.
     * @param SourceMappingService $objectService  The object service (OC).
     * @param IL10N                $l              The localization service.
     * @param IUserSession         $userSession    The user session.
     * @param ActionAuthService    $actionAuth     The action authorization service.
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly MappingService $mappingService,
        private readonly SourceMappingService $objectService,
        private readonly IL10N $l,
        private readonly IUserSession $userSession,
        private readonly ActionAuthService $actionAuth,
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()

    /**
     * Tests a mapping.
     *
     * This method tests a mapping with provided input data and optional schema validation.
     *
     * @param SourceMappingService $objectService Source mapping service used to access OpenRegister.
     * @param IURLGenerator        $urlGenerator  URL generator used to resolve schema URLs during validation.
     *
     * @return JSONResponse A JSON response containing the test results.
     *
     * @throws ContainerExceptionInterface Container resolution failure.
     * @throws NotFoundExceptionInterface  Container lookup miss.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/specs/mapping-and-search/spec.md
     *
     * @example
     * Request:
     * {
     *     "inputObject": "{\"name\":\"John Doe\",\"age\":30,\"email\":\"john@example.com\"}",
     *     "mapping": {
     *            "mapping": {
     *                "fullName":"{{name}}",
     *                "userAge":"{{age}}",
     *                "contactEmail":"{{email}}"
     *            }
     *       },
     *     "schema": "user_schema_id",
     *     "validation": true
     * }
     *
     * Response:
     * {
     *     "resultObject": {
     *         "fullName": "John Doe",
     *         "userAge": 30,
     *         "contactEmail": "john@example.com"
     *     },
     *     "isValid": true,
     *     "validationErrors": []
     * }
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function test(SourceMappingService $objectService, IURLGenerator $urlGenerator): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l->t('Not authenticated')], \OCP\AppFramework\Http::STATUS_UNAUTHORIZED);
        }

        $this->actionAuth->requireAction(user: $user, action: 'mapping.test');

        $openRegisters = $objectService->getOpenRegisters();

        // Get all parameters from the request.
        $data = $this->request->getParams();

        // Validate that required parameters are present. Missing params are a client
        // error, so return a clean 400 rather than letting an exception escape as a 500.
        if (isset($data['inputObject']) === false || isset($data['mapping']) === false) {
            return new JSONResponse(
                data: [
                    'error'   => $this->l->t('Bad request'),
                    'message' => $this->l->t('Both `inputObject` and `mapping` are required'),
                ],
                statusCode: 400
            );
        }

        // Decode the input object from JSON.
        $inputObject = $data['inputObject'];

        // Decode the mapping from JSON.
        $mapping = $data['mapping'];

        // Initialize schema and validation flags.
        $schema     = false;
        $validation = false;

        // If a schema is provided, retrieve it.
        if (empty($data['schema']) === false) {
            if ($openRegisters === null) {
                return new JSONResponse(
                data: [
                    'error'   => $this->l->t('Setup error'),
                    'message' => $this->l->t('OpenRegisters must be installed to validate schema.'),
                ],
                statusCode: 412
                );
            }

            $schemaId = $data['schema'];
            try {
                $schema = $openRegisters->getMapper('schema')->find($schemaId);
            } catch (DoesNotExistException $exception) {
                return new JSONResponse(
                data: [
                    'error'   => $this->l->t('Not found'),
                    'message' => $this->l->t('The specified schema could not be found.'),
                ],
                statusCode: 404
                );
            }
        }//end if

        // Check if validation is requested.
        if (empty($data['validation']) === false) {
            $validation = $data['validation'];
        }

        // Build a polymorphic mapping payload accepted directly by
        // MappingService::executeMapping(): post chain-C the service hydrates
        // arrays into an \OCA\OpenRegister\Db\Mapping value object internally,
        // so the controller no longer constructs a typed entity here.
        if (is_array($mapping) === true) {
            $mappingPayload = $mapping;
        } else {
            $mappingPayload = ['mapping' => $mapping];
        }

        // Perform the mapping operation.
        try {
            $resultObject = $this->mappingService->executeMapping(mapping: $mappingPayload, input: $inputObject);
        } catch (Exception $e) {
            // If mapping fails, return an error response.
            return new JSONResponse(
                    [
                        'error'   => $this->l->t('Mapping error'),
                        'message' => $e->getMessage(),
                    ],
                    400
                    );
        }

        // Initialize validation variables.
        $isValid          = true;
        $validationErrors = [];

        // Perform schema validation if both schema and validation are provided.
        if ($schema !== false && $validation !== false && $openRegisters !== null) {
            $result = $openRegisters->validateObject(object: $resultObject, schemaObject: $schema->getSchemaObject($urlGenerator));

            $isValid = $result->isValid();

            if ($result->hasError() === true) {
                // Class imported without use because it only exists when OpenRegisters is installed.
                $validationErrors = (new \Opis\JsonSchema\Errors\ErrorFormatter())->format(error: $result->error());
            }
        }

        // Return the result as a JSON response.
        return new JSONResponse(
                [
                    'resultObject'     => $resultObject,
                    'isValid'          => $isValid,
                    'validationErrors' => $validationErrors,
                ]
                );

    }//end test()

    /**
     * Saves a mapping object.
     *
     * Admin-only: gated at the middleware layer via #[AuthorizedAdminSetting].
     *
     * @return JSONResponse|null The saved mapping JSON or an error response, null when no error.
     *
     * @throws ContainerExceptionInterface Container resolution failure.
     * @throws NotFoundExceptionInterface  Container lookup miss.
     *
     * @spec openspec/specs/mapping-and-search/spec.md
     */
    #[AuthorizedAdminSetting(OpenConnectorAdmin::class)]
    public function saveObject(): ?JSONResponse
    {
        // Check if the OpenRegister service is available.
        $openRegisters = $this->objectService->getOpenRegisters();
        if ($openRegisters === null) {
            return new JSONResponse(['error' => $this->l->t('OpenRegister is not installed')], 412);
        }

        $data = $this->request->getParams();
        if (isset($data['object']) === false) {
            return new JSONResponse(['error' => $this->l->t('Missing required `object` field')], 400);
        }

        // OR's ObjectService::saveObject signature is `(object, register?,
        // schema?)`. Prior code passed the register slug as the first arg
        // — a TypeError under the new signature, which surfaced as 500.
        $saved = $openRegisters->saveObject(
            object:   $data['object'],
            register: ($data['register'] ?? 'openconnector'),
            schema:   ($data['schema'] ?? 'mapping')
        );

        return new JSONResponse($saved->getObject());

    }//end saveObject()

    /**
     * Retrieves a list of objects to map to.
     *
     * Admin-only: gated at the middleware layer via #[AuthorizedAdminSetting].
     *
     * @return JSONResponse
     *
     * @throws ContainerExceptionInterface Container resolution failure.
     * @throws NotFoundExceptionInterface  Container lookup miss.
     *
     * @spec openspec/specs/mapping-and-search/spec.md
     */
    #[AuthorizedAdminSetting(OpenConnectorAdmin::class)]
    public function getObjects(): JSONResponse
    {
        // Check if the OpenRegister service is available.
        $openRegisters = $this->objectService->getOpenRegisters();
        $data          = [];
        $data['openRegisters'] = false;
        if ($openRegisters !== null) {
            $data['openRegisters'] = true;
            // OpenRegister's ObjectService no longer exposes getRegisters();
            // fetch the register list via the mapper directly.
            try {
                $registerMapper = \OC::$server->get(RegisterMapper::class);
                $data['availableRegisters'] = $registerMapper->findAll();
            } catch (\Throwable $e) {
                $data['availableRegisters'] = [];
            }
        }

        return new JSONResponse($data);

    }//end getObjects()
}//end class

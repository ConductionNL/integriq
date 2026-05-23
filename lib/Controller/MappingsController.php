<?php

namespace OCA\OpenConnector\Controller;

use Exception;
use InvalidArgumentException;
use OCA\OpenConnector\Service\ObjectService;
use OCA\OpenConnector\Service\MappingService;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
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
     * Constructor for the MappingsController
     *
     * @param string         $appName        The name of the app
     * @param IRequest       $request        The request object
     * @param IAppConfig     $config         The app configuration object
     * @param MappingService $mappingService The mapping service
     * @param ObjectService  $objectService  The object service (OC)
     * @param IL10N          $l              The localization service
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly IAppConfig $config,
        private readonly MappingService $mappingService,
        private readonly ObjectService $objectService,
        private readonly IL10N $l
    ) {
        parent::__construct($appName, $request);
    }//end __construct()

    /**
     * Returns the template of the main app's page
     *
     * This method renders the main page of the application, adding any necessary data to the template.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return TemplateResponse The rendered template response
     */
    public function page(): TemplateResponse
    {
        return new TemplateResponse(
            'openconnector',
            'index',
            []
        );
    }//end page()

    /**
     * Tests a mapping
     *
     * This method tests a mapping with provided input data and optional schema validation.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param ObjectService $objectService
     * @param IURLGenerator $urlGenerator
     *
     * @return JSONResponse A JSON response containing the test results
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
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
    public function test(ObjectService $objectService, IURLGenerator $urlGenerator): JSONResponse
    {
        $openRegisters = $objectService->getOpenRegisters();

        // Get all parameters from the request
        $data = $this->request->getParams();

        // Validate that required parameters are present
        if (isset($data['inputObject']) === false || isset($data['mapping']) === false) {
            throw new InvalidArgumentException('Both `inputObject` and `mapping` are required');
        }

        // Decode the input object from JSON
        $inputObject = $data['inputObject'];

        // Decode the mapping from JSON
        $mapping = $data['mapping'];

        // Initialize schema and validation flags
        $schema     = false;
        $validation = false;

        // If a schema is provided, retrieve it
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

        // Check if validation is requested
        if (empty($data['validation']) === false) {
            $validation = $data['validation'];
        }

        // Create a new ObjectEntity representing the mapping configuration
        $mappingObject = new ObjectEntity();
        $mappingObject->hydrate(is_array($mapping) ? $mapping : ['mapping' => $mapping]);

        // Perform the mapping operation
        try {
            $resultObject = $this->mappingService->executeMapping(mapping: $mappingObject, input: $inputObject);
        } catch (Exception $e) {
            // If mapping fails, return an error response
            return new JSONResponse(
                    [
                        'error'   => $this->l->t('Mapping error'),
                        'message' => $e->getMessage(),
                    ],
                    400
                    );
        }

        // Initialize validation variables
        $isValid          = true;
        $validationErrors = [];

        // Perform schema validation if both schema and validation are provided
        if ($schema !== false && $validation !== false && $openRegisters !== null) {
            $result = $openRegisters->validateObject(object: $resultObject, schemaObject: $schema->getSchemaObject($urlGenerator));

            $isValid = $result->isValid();

            if ($result->hasError() === true) {
                // Class imported without use because it only exists when OpenRegisters is installed.
                $validationErrors = (new \Opis\JsonSchema\Errors\ErrorFormatter())->format(error: $result->error());
            }
        }

        // Return the result as a JSON response
        return new JSONResponse(
                [
                    'resultObject'     => $resultObject,
                    'isValid'          => $isValid,
                    'validationErrors' => $validationErrors,
                ]
                );
    }//end test()

    /**
     * Saves a mapping object
     *
     * This method saves a mapping object based on POST data.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse|null
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function saveObject(): ?JSONResponse
    {
        // Check if the OpenRegister service is available
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
            register: $data['register'] ?? 'openconnector',
            schema:   $data['schema'] ?? 'mapping'
        );

        return new JSONResponse($saved->getObject());
    }//end saveObject()

    /**
     * Retrieves a list of objects to map to
     *
     * This method retrieves a list of objects to map to based on GET data.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function getObjects(): JSONResponse
    {
        // Check if the OpenRegister service is available
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

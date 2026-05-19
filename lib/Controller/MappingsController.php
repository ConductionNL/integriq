<?php

namespace OCA\OpenConnector\Controller;

use Exception;
use InvalidArgumentException;
use OCA\OpenConnector\Service\ObjectService;
use OCA\OpenConnector\Service\SearchService;
use OCA\OpenConnector\Service\MappingService;
use OCA\OpenConnector\Db\Mapping;
use OCA\OpenConnector\Db\MappingMapper;
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
     * @param string $appName The name of the app
     * @param IRequest $request The request object
     * @param IAppConfig $config The app configuration object
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly IAppConfig $config,
        private readonly MappingMapper $mappingMapper,
        private readonly MappingService $mappingService,
        private readonly ObjectService $objectService,
        private readonly IL10N $l
    )
    {
        parent::__construct($appName, $request);
    }

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
    }

    /**
     * Retrieves a list of all mappings
     *
     * This method returns a JSON response containing an array of all mappings in the system.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse A JSON response containing the list of mappings
     */
    public function index(ObjectService $objectService, SearchService $searchService): JSONResponse
    {
        $filters = $this->request->getParams();
        $fieldsToSearch = ['name', 'description'];

        $searchParams = $searchService->createMySQLSearchParams(filters: $filters);
        $searchConditions = $searchService->createMySQLSearchConditions(filters: $filters, fieldsToSearch:  $fieldsToSearch);
        $filters = $searchService->unsetSpecialQueryParams(filters: $filters);

        return new JSONResponse(['results' => $this->mappingMapper->findAll(limit: null, offset: null, filters: $filters, searchConditions: $searchConditions, searchParams: $searchParams)]);
    }

    /**
     * Retrieves a single mapping by its ID
     *
     * This method returns a JSON response containing the details of a specific mapping.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $id The ID of the mapping to retrieve
     * @return JSONResponse A JSON response containing the mapping details
     */
    public function show(string $id): JSONResponse
    {
        try {
            return new JSONResponse($this->mappingMapper->find(id: (int) $id));
        } catch (DoesNotExistException $exception) {
            return new JSONResponse(data: ['error' => $this->l->t('Not Found')], statusCode: 404);
        }
    }

    /**
     * Creates a new mapping
     *
     * This method creates a new mapping based on POST data.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse A JSON response containing the created mapping
     */
    public function create(): JSONResponse
    {
        $data = $this->request->getParams();

        foreach ($data as $key => $value) {
            if (str_starts_with($key, '_')) {
                unset($data[$key]);
            }
        }

        if (isset($data['id'])) {
            unset($data['id']);
        }

        return new JSONResponse($this->mappingMapper->createFromArray(object: $data));
    }

	/**
	 * Updates an existing mapping
	 *
	 * This method updates an existing mapping based on its ID.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param int $id The ID of the mapping to update
	 * @return JSONResponse A JSON response containing the updated mapping details
	 */
    public function update(int $id): JSONResponse
    {
        $data = $this->request->getParams();

        foreach ($data as $key => $value) {
            if (str_starts_with($key, '_')) {
                unset($data[$key]);
            }
        }
        if (isset($data['id'])) {
            unset($data['id']);
        }
        return new JSONResponse($this->mappingMapper->updateFromArray(id: (int) $id, object: $data));
    }

	/**
	 * Deletes a mapping
	 *
	 * This method deletes a mapping based on its ID.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param int $id The ID of the mapping to delete
	 * @return JSONResponse An empty JSON response
	 * @throws \OCP\DB\Exception
	 */
    public function destroy(int $id): JSONResponse
    {
        $this->mappingMapper->delete($this->mappingMapper->find((int) $id));

        return new JSONResponse([]);
    }

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
        $schema = false;
        $validation = false;

        // If a schema is provided, retrieve it
        if (empty($data['schema']) === false) {
			if ($openRegisters === null) {
				return new JSONResponse(data: [
					'error'   => $this->l->t('Setup error'),
					'message' => $this->l->t('OpenRegisters must be installed to validate schema.')
				],statusCode: 412);
			}

            $schemaId = $data['schema'];
			try {
				$schema = $openRegisters->getMapper('schema')->find($schemaId);
			} catch (DoesNotExistException $exception) {
				return new JSONResponse(data: [
					'error' => $this->l->t('Not found'),
					'message' => $this->l->t('The specified schema could not be found.'),
				], statusCode: 404);
			}
        }

        // Check if validation is requested
        if (empty($data['validation']) === false) {
            $validation = $data['validation'];
        }

        // Create a new Mapping object and hydrate it with the provided mapping
        $mappingObject = new Mapping();
        $mappingObject->hydrate($mapping);

        // Perform the mapping operation
        try {
            $resultObject = $this->mappingService->executeMapping(mapping: $mappingObject, input: $inputObject);
        } catch (Exception $e) {
            // If mapping fails, return an error response
            return new JSONResponse([
                'error' => $this->l->t('Mapping error'),
                'message' => $e->getMessage()
            ], 400);
        }

        // Initialize validation variables
        $isValid = true;
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
        return new JSONResponse([
            'resultObject' => $resultObject,
            'isValid' => $isValid,
            'validationErrors' => $validationErrors
        ]);
    }

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
		if ($openRegisters !== null) {
            $data = $this->request->getParams();
            return new JSONResponse($openRegisters->saveObject($data['register'], $data['schema'], $data['object']));
		}

		return null;
    }

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
        $data = [];
		$data['openRegisters'] = false;
		if ($openRegisters !== null) {
			$data['openRegisters'] = true;
			$data['availableRegisters'] = $openRegisters->getRegisters();
		}

        return new JSONResponse($data);

    }
}

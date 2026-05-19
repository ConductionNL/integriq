<?php

namespace OCA\OpenConnector\Controller;

use Exception;
use OCA\OpenConnector\Service\ObjectService;
use OCA\OpenConnector\Service\SearchService;
use OCA\OpenConnector\Db\Rule;
use OCA\OpenConnector\Db\RuleMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Class RuleController
 * 
 * Controller for managing rules in the OpenConnector app
 *
 * @package OCA\OpenConnector\Controller
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
 */
class RulesController extends Controller
{
    /**
     * Constructor for the RuleController
     *
     * @param string $appName The name of the app
     * @param IRequest $request The request object
     * @param IAppConfig $config The app configuration object
     * @param RuleMapper $ruleMapper The rule mapper object
     */
    public function __construct(
        $appName,
        IRequest $request,
        private IAppConfig $config,
        private RuleMapper $ruleMapper,
        private IL10N $l
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
     * Retrieves a list of all rules
     *
     * This method returns a JSON response containing an array of all rules in the system.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse A JSON response containing the list of rules
     */
    public function index(ObjectService $objectService, SearchService $searchService): JSONResponse
    {
        $filters = $this->request->getParams();
        $fieldsToSearch = ['name', 'description'];

        $searchParams = $searchService->createMySQLSearchParams(filters: $filters);
        $searchConditions = $searchService->createMySQLSearchConditions(filters: $filters, fieldsToSearch: $fieldsToSearch);
        $filters = $searchService->unsetSpecialQueryParams(filters: $filters);

        return new JSONResponse(['results' => $this->ruleMapper->findAll(limit: null, offset: null, filters: $filters, searchConditions: $searchConditions, searchParams: $searchParams)]);
    }

    /**
     * Retrieves a single rule by its ID
     *
     * This method returns a JSON response containing the details of a specific rule.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $id The ID of the rule to retrieve
     * @return JSONResponse A JSON response containing the rule details
     */
    public function show(string $id): JSONResponse
    {
        try {
            return new JSONResponse($this->ruleMapper->find(id: (int) $id));
        } catch (DoesNotExistException $exception) {
            return new JSONResponse(data: ['error' => $this->l->t('Not Found')], statusCode: 404);
        }
    }

    /**
     * Creates a new rule
     *
     * This method creates a new rule based on POST data.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse A JSON response containing the created rule
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

        // Create the rule
        $rule = $this->ruleMapper->createFromArray(object: $data);

        return new JSONResponse($rule);
    }

    /**
     * Updates an existing rule
     *
     * This method updates an existing rule based on its ID.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $id The ID of the rule to update
     * @return JSONResponse A JSON response containing the updated rule details
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

        // Update the rule
        $rule = $this->ruleMapper->updateFromArray(id: (int) $id, object: $data);

        return new JSONResponse($rule);
    }

    /**
     * Deletes a rule
     *
     * This method deletes a rule based on its ID.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $id The ID of the rule to delete
     * @return JSONResponse An empty JSON response
     */
    public function destroy(int $id): JSONResponse
    {
        $this->ruleMapper->delete($this->ruleMapper->find((int) $id));

        return new JSONResponse([]);
    }
}

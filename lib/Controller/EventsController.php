<?php

namespace OCA\OpenConnector\Controller;

use Exception;
use OCA\OpenConnector\Service\ObjectService;
use OCA\OpenConnector\Service\SearchService;
use OCA\OpenConnector\Db\EventMapper;
use OCA\OpenConnector\Db\EventMessageMapper;
use OCA\OpenConnector\Db\EventSubscriptionMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCA\OpenConnector\Service\EventService;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Controller for managing events and their subscriptions
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
 */
class EventsController extends Controller
{
    /**
     * Constructor for the EventsController
     *
     * @param string $appName The name of the app
     * @param IRequest $request The request object
     * @param IAppConfig $config The app configuration object
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly IAppConfig $config,
        private readonly EventMapper $eventMapper,
//        private readonly EventLogMapper $eventLogMapper, // @todo
        private readonly EventService $eventService,
        private readonly EventMessageMapper $messageMapper,
        private readonly EventSubscriptionMapper $subscriptionMapper,
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
     * Retrieves a list of all events
     *
     * This method returns a JSON response containing an array of all events in the system.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse A JSON response containing the list of events
     */
    public function index(ObjectService $objectService, SearchService $searchService): JSONResponse
    {
        $filters = $this->request->getParams();
        $fieldsToSearch = ['name', 'description'];

        $searchParams = $searchService->createMySQLSearchParams(filters: $filters);
        $searchConditions = $searchService->createMySQLSearchConditions(filters: $filters, fieldsToSearch: $fieldsToSearch);
        $filters = $searchService->unsetSpecialQueryParams(filters: $filters);

        return new JSONResponse(['results' => $this->eventMapper->findAll(limit: null, offset: null, filters: $filters, searchConditions: $searchConditions, searchParams: $searchParams)]);
    }

    /**
     * Retrieves a single event by its ID
     *
     * This method returns a JSON response containing the details of a specific event.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $id The ID of the event to retrieve
     * @return JSONResponse A JSON response containing the event details
     */
    public function show(string $id): JSONResponse
    {
        try {
            return new JSONResponse($this->eventMapper->find(id: (int) $id));
        } catch (DoesNotExistException $exception) {
            return new JSONResponse(data: ['error' => $this->l->t('Not Found')], statusCode: 404);
        }
    }

    /**
     * Creates a new event
     *
     * This method creates a new event based on POST data.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse A JSON response containing the created event
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

        // Create the event
        $event = $this->eventMapper->createFromArray(object: $data);

        return new JSONResponse($event);
    }

    /**
     * Updates an existing event
     *
     * This method updates an existing event based on its ID.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $id The ID of the event to update
     * @return JSONResponse A JSON response containing the updated event details
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

        // Update the event
        $event = $this->eventMapper->updateFromArray(id: (int) $id, object: $data);

        return new JSONResponse($event);
    }

    /**
     * Deletes an event
     *
     * This method deletes an event based on its ID.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $id The ID of the event to delete
     * @return JSONResponse An empty JSON response
     */
    public function destroy(int $id): JSONResponse
    {
        $this->eventMapper->delete($this->eventMapper->find((int) $id));

        return new JSONResponse([]);
    }

    /**
     * Get all messages generated by an event
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int $id Event ID
     * @return JSONResponse List of messages
     */
    public function messages(int $id): JSONResponse
    {
        try {
            // Verify event exists
            $event = $this->eventMapper->find($id);

            // Get all messages for this event
            $messages = $this->messageMapper->findAll(
                filters: ['eventId' => $id],
                limit: $this->request->getParam('limit', 50),
                offset: $this->request->getParam('offset', 0)
            );

            return new JSONResponse([
                'event' => $event,
                'messages' => $messages
            ]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => $this->l->t('Event not found')], 404);
        }
    }

    /**
     * Create a new subscription for events
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse The created subscription
     */
    public function subscribe(): JSONResponse
    {
        try {
            $data = $this->request->getParams();

            // Remove internal fields
            foreach ($data as $key => $value) {
                if (str_starts_with($key, '_')) {
                    unset($data[$key]);
                }
            }

            // Create subscription
            $subscription = $this->subscriptionMapper->createFromArray($data);

            return new JSONResponse($subscription);
        } catch (Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Update an existing subscription
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int $subscriptionId Subscription ID
     * @return JSONResponse The updated subscription
     */
    public function updateSubscription(int $subscriptionId): JSONResponse
    {
        try {
            $data = $this->request->getParams();

            // Remove internal fields
            foreach ($data as $key => $value) {
                if (str_starts_with($key, '_')) {
                    unset($data[$key]);
                }
            }

            // Update subscription
            $subscription = $this->subscriptionMapper->updateFromArray($subscriptionId, $data);

            return new JSONResponse($subscription);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => $this->l->t('Subscription not found')], 404);
        } catch (Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Delete a subscription
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int $subscriptionId Subscription ID
     * @return JSONResponse Empty response
     */
    public function unsubscribe(int $subscriptionId): JSONResponse
    {
        try {
            $subscription = $this->subscriptionMapper->find($subscriptionId);
            $this->subscriptionMapper->delete($subscription);

            return new JSONResponse([]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => $this->l->t('Subscription not found')], 404);
        }
    }

    /**
     * List all subscriptions
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse List of subscriptions
     */
    public function subscriptions(): JSONResponse
    {
        $filters = $this->request->getParams();

        // Remove internal fields
        foreach ($filters as $key => $value) {
            if (str_starts_with($key, '_')) {
                unset($filters[$key]);
            }
        }

        $subscriptions = $this->subscriptionMapper->findAll(
            limit: $this->request->getParam('limit', 50),
            offset: $this->request->getParam('offset', 0),
            filters: $filters
        );

        return new JSONResponse(['results' => $subscriptions]);
    }

    /**
     * Get messages for a specific subscription
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int $subscriptionId Subscription ID
     * @return JSONResponse List of messages
     */
    public function subscriptionMessages(int $subscriptionId): JSONResponse
    {
        try {
            // Verify subscription exists
            $subscription = $this->subscriptionMapper->find($subscriptionId);

            // Get messages for this subscription
            $messages = $this->messageMapper->findAll(
                limit: $this->request->getParam('limit', 50),
				offset: $this->request->getParam('offset', 0),
				filters: ['subscriptionId' => $subscriptionId]
            );

            return new JSONResponse([
                'subscription' => $subscription,
                'messages' => $messages
            ]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => $this->l->t('Subscription not found')], 404);
        }
    }

    /**
     * Pull events for a subscription (for pull-based subscriptions)
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int $subscriptionId Subscription ID
     * @return JSONResponse List of pending messages
     */
    public function pull(int $subscriptionId): JSONResponse
    {
        try {
            $subscription = $this->subscriptionMapper->find($subscriptionId);

            if ($subscription->getStyle() !== 'pull') {
                return new JSONResponse(['error' => $this->l->t('Subscription is not pull-based')], 400);
            }

            $result = $this->eventService->pullEvents(
                subscription: $subscription,
                limit: $this->request->getParam('limit', 100),
                cursor: $this->request->getParam('cursor')
            );

            return new JSONResponse($result);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => $this->l->t('Subscription not found')], 404);
        }
    }
}

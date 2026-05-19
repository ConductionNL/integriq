<?php

namespace OCA\OpenConnector\Service;

use DateTime;
use Exception;
use OCA\OpenConnector\Db\Event;
use OCA\OpenConnector\Db\EventMapper;
use OCA\OpenConnector\Db\EventMessage;
use OCA\OpenConnector\Db\EventMessageMapper;
use OCA\OpenConnector\Db\EventSubscription;
use OCA\OpenConnector\Db\EventSubscriptionMapper;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

/**
 * Service class for managing events and their delivery
 *
 * @package OCA\OpenConnector\Service
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 */
class EventService
{
    /**
     * @param EventMapper $eventMapper
     * @param EventMessageMapper $messageMapper
     * @param EventSubscriptionMapper $subscriptionMapper
     * @param IClientService $clientService
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly EventMapper $eventMapper,
        private readonly EventMessageMapper $messageMapper,
        private readonly EventSubscriptionMapper $subscriptionMapper,
        private readonly IClientService $clientService,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Process a new event and create messages for all matching subscriptions
     *
     * @param Event $event The event to process
     * @return array<EventMessage> Array of created messages
     * @throws Exception
     */
    public function processEvent(Event $event): array
    {
        try {
            // Find all active subscriptions
            $subscriptions = $this->subscriptionMapper->findAll(filters: ['status' => 'active']);
            $messages = [];

            foreach ($subscriptions as $subscription) {
                if ($this->doesEventMatchSubscription($event, $subscription)) {
                    $message = $this->createEventMessage($event, $subscription);
                    $messages[] = $message;

                    // If it's a push subscription, attempt immediate delivery
                    if ($subscription->getStyle() === 'push') {
                        $this->deliverMessage($message);
                    }
                }
            }

            return $messages;
        } catch (Exception $e) {
            $this->logger->error('Failed to process event: ' . $e->getMessage(), [
                'exception' => $e,
                'event' => $event->jsonSerialize()
            ]);
            throw $e;
        }
    }

    /**
     * Check if an event matches a subscription's criteria
     *
     * @param Event $event
     * @param EventSubscription $subscription
     * @return bool
     */
    private function doesEventMatchSubscription(Event $event, EventSubscription $subscription): bool
    {
        // Check event type matches
        if (empty($subscription->getTypes() === false) &&
            in_array($event->getType(), $subscription->getTypes()) === false) {
            return false;
        }

        // Check source matches
        if ($subscription->getSource() &&
            $event->getSource() !== $subscription->getSource()) {
            return false;
        }

        // Process filters if any exist
        if (empty($subscription->getFilters()) === false) {
            return $this->evaluateFilters($event, $subscription->getFilters());
        }

        return true;
    }

    /**
     * Evaluate filter conditions against an event
     *
     * @param Event $event
     * @param array $filters
     * @return bool
     */
    private function evaluateFilters(Event $event, array $filters): bool
    {
        $expressionLanguage = new ExpressionLanguage();

        foreach ($filters as $filter) {
            foreach ($filter as $dialect => $condition) {
                switch ($dialect) {
                    case 'exact':
                        foreach ($condition as $field => $value) {
                            if ($event->{'get' . ucfirst($field)}() !== $value) {
                                return false;
                            }
                        }
                        break;

                    case 'prefix':
                        foreach ($condition as $field => $value) {
                            if (str_starts_with($event->{'get' . ucfirst($field)}(), $value) === false) {
                                return false;
                            }
                        }
                        break;

                    case 'suffix':
                        foreach ($condition as $field => $value) {
                            if (str_ends_with($event->{'get' . ucfirst($field)}(), $value) === false) {
                                return false;
                            }
                        }
                        break;

                    case 'expression':
                        $variables = $event->jsonSerialize();
                        if ($expressionLanguage->evaluate($condition, $variables) === false) {
                            return false;
                        }
                        break;
                }
            }
        }

        return true;
    }

    /**
     * Create a new event message
     *
     * @param Event $event
     * @param EventSubscription $subscription
     * @return EventMessage
     */
    private function createEventMessage(Event $event, EventSubscription $subscription): EventMessage
    {
        return $this->messageMapper->createFromArray([
            'eventId' => $event->getId(),
            'consumerId' => $subscription->getConsumerId(),
            'subscriptionId' => $subscription->getId(),
            'status' => 'pending',
            'payload' => $event->jsonSerialize(),
            'created' => new DateTime(),
            'updated' => new DateTime()
        ]);
    }

    /**
     * Attempt to deliver a message
     *
     * @param EventMessage $message
     * @return bool
     */
    public function deliverMessage(EventMessage $message): bool
    {
        try {
            $subscription = $this->subscriptionMapper->find($message->getSubscriptionId());

            if ($subscription->getStyle() !== 'push') {
                return false;
            }

            $client = $this->clientService->newClient();
            $response = $client->post($subscription->getSink(), [
                'body' => json_encode($message->getPayload()),
                'headers' => [
                    'Content-Type' => 'application/cloudevents+json',
                    ...$subscription->getProtocolSettings()['headers'] ?? []
                ],
                'timeout' => 30
            ]);

            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                $this->messageMapper->markDelivered($message->getId(), [
                    'statusCode' => $response->getStatusCode(),
                    'body' => $response->getBody()
                ]);
                return true;
            }

            throw new Exception('Delivery failed with status code: ' . $response->getStatusCode());

        } catch (Exception $e) {
            $this->logger->error('Failed to deliver message: ' . $e->getMessage(), [
                'exception' => $e,
                'message' => $message->jsonSerialize()
            ]);

            $this->messageMapper->markFailed($message->getId(), [
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    /**
     * Process pending message retries
     *
     * @param int $maxRetries Maximum number of retry attempts
     * @return int Number of successfully delivered messages
     */
    public function processRetries(int $maxRetries = 5): int
    {
        $messages = $this->messageMapper->findPendingRetries($maxRetries);
        $successCount = 0;

        foreach ($messages as $message) {
            if ($this->deliverMessage($message)) {
                $successCount++;
            }
        }

        return $successCount;
    }

    /**
     * Get events for a pull-based subscription
     *
     * @param EventSubscription $subscription
     * @param int|null $limit
     * @param string|null $cursor
     * @return array{messages: EventMessage[], cursor: string|null}
     */
    public function pullEvents(EventSubscription $subscription, ?int $limit = 100, ?string $cursor = null): array
    {
        $filters = ['subscriptionId' => $subscription->getId(), 'status' => 'pending'];

        if ($cursor) {
            $filters['id'] = ['>' => $cursor];
        }

        $messages = $this->messageMapper->findAll($limit, 0, $filters);
        $lastCursor = end($messages) ? (string)end($messages)->getId() : null;

        return [
            'messages' => $messages,
            'cursor' => $lastCursor
        ];
    }

	/**
	 * Handle object creation by creating and processing a CloudEvent
	 *
	 * @param Object $object The created object
	 * @return EventMessage[] The created CloudEvent
	 * @throws Exception
	 */
    public function handleObjectCreated(Object $object): array
	{
        $event = $this->eventMapper->createFromArray([
            'source' => '/objects/' . $object->getType(),
            'type' => 'com.nextcloud.openregister.object.created',
            'time' => new DateTime(),
            'subject' => $object->getId(),
            'data' => [
                'type' => $object->getType(),
                'id' => $object->getId(),
                'attributes' => $object->getAttributes()
            ],
            'userId' => $object->getUserId()
        ]);

        return $this->processEvent($event);
    }

	/**
	 * Handle object update by creating and processing a CloudEvent
	 *
	 * @param Object $oldObject The previous state of the object
	 * @param Object $newObject The new state of the object
	 * @return EventMessage[] The created CloudEvent
	 * @throws Exception
	 */
    public function handleObjectUpdated(Object $oldObject, Object $newObject): array
	{
        $event = $this->eventMapper->createFromArray([
            'source' => '/objects/' . $newObject->getType(),
            'type' => 'com.nextcloud.openregister.object.updated',
            'time' => new DateTime(),
            'subject' => $newObject->getId(),
            'data' => [
                'type' => $newObject->getType(),
                'id' => $newObject->getId(),
                'attributes' => $newObject->getAttributes(),
                'previous' => [
                    'attributes' => $oldObject->getAttributes()
                ]
            ],
            'userId' => $newObject->getUserId()
        ]);

        return $this->processEvent($event);
    }

	/**
	 * Handle object deletion by creating and processing a CloudEvent
	 *
	 * @param Object $object The deleted object
	 * @return EventMessage[] The created CloudEvent
	 * @throws Exception
	 */
    public function handleObjectDeleted(Object $object): array
	{
        $event = $this->eventMapper->createFromArray([
            'source' => '/objects/' . $object->getType(),
            'type' => 'com.nextcloud.openregister.object.deleted',
            'time' => new DateTime(),
            'subject' => $object->getId(),
            'data' => [
                'type' => $object->getType(),
                'id' => $object->getId()
            ],
            'userId' => $object->getUserId()
        ]);

        return $this->processEvent($event);
    }
}

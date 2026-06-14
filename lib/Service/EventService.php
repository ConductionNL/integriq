<?php
/**
 * OpenConnector EventService.
 *
 * Service class for managing events and their delivery.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 */

namespace OCA\OpenConnector\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use OCA\OpenConnector\Service\WebhookSignatureService;

/**
 * Service class for managing events and their delivery.
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 *
 * @spec openspec/changes/openconnector-webhook-signing/tasks.md#task-2
 */
class EventService
{
    /**
     * Constructor.
     *
     * @param ORObjectService         $objectService    The OR ObjectService for data access.
     * @param IClientService          $clientService    HTTP client service used to deliver push messages.
     * @param LoggerInterface         $logger           Logger for delivery successes and failures.
     * @param WebhookSignatureService $signatureService Signs outbound deliveries when configured.
     */
    public function __construct(
        private readonly ORObjectService $objectService,
        private readonly IClientService $clientService,
        private readonly LoggerInterface $logger,
        private readonly WebhookSignatureService $signatureService
    ) {

    }//end __construct()

    /**
     * Process a new event and create messages for all matching subscriptions.
     *
     * @param ObjectEntity $event The event ObjectEntity to process.
     *
     * @return array<ObjectEntity> Array of created message ObjectEntities.
     *
     * @throws Exception On failure to process the event.
     *
     * @spec openspec/changes/retrofit-2026-05-24-events-cloudevents/tasks.md#task-1
     */
    public function processEvent(ObjectEntity $event): array
    {
        try {
            // Find all active subscriptions.
            $matches       = $this->objectService->findAll(
                    config: [
                        'filters' => [
                            'register' => 'openconnector',
                            'schema'   => 'event_subscription',
                            'status'   => 'active',
                        ],
                    ]
                    );
            $subscriptions = ($matches['results'] ?? $matches);
            $messages      = [];

            foreach ($subscriptions as $subscription) {
                if ($this->doesEventMatchSubscription(event: $event, subscription: $subscription) === true) {
                    $message    = $this->createEventMessage(event: $event, subscription: $subscription);
                    $messages[] = $message;

                    $subscriptionData = $subscription->getObject();
                    // If it's a push subscription, attempt immediate delivery.
                    if (($subscriptionData['style'] ?? '') === 'push') {
                        $this->deliverMessage(message: $message);
                    }
                }
            }

            return $messages;
        } catch (Exception $e) {
            $this->logger->error(
                    'Failed to process event: '.$e->getMessage(),
                    [
                        'exception' => $e,
                        'event'     => $event->jsonSerialize(),
                    ]
                    );
            throw $e;
        }//end try

    }//end processEvent()

    /**
     * Check if an event matches a subscription's criteria.
     *
     * @param ObjectEntity $event        The event to evaluate.
     * @param ObjectEntity $subscription The subscription whose filters/types are checked.
     *
     * @return boolean
     *
     * @spec openspec/changes/retrofit-2026-05-24-events-cloudevents/tasks.md#task-1
     */
    private function doesEventMatchSubscription(ObjectEntity $event, ObjectEntity $subscription): bool
    {
        $eventData        = $event->getObject();
        $subscriptionData = $subscription->getObject();

        // Check event type matches.
        $types = ($subscriptionData['types'] ?? []);
        if (empty($types) === false
            && in_array(($eventData['type'] ?? ''), $types) === false
        ) {
            return false;
        }

        // Check source matches.
        $subscriptionSource = ($subscriptionData['source'] ?? null);
        if ($subscriptionSource !== null
            && ($eventData['source'] ?? null) !== $subscriptionSource
        ) {
            return false;
        }

        // Process filters if any exist.
        $filters = ($subscriptionData['filters'] ?? []);
        if (empty($filters) === false) {
            return $this->evaluateFilters(eventData: $eventData, filters: $filters);
        }

        return true;

    }//end doesEventMatchSubscription()

    /**
     * Evaluate filter conditions against an event data array.
     *
     * @param array $eventData The event data as plain array.
     * @param array $filters   Subscription filters.
     *
     * @return boolean
     *
     * @spec openspec/changes/retrofit-2026-05-24-events-cloudevents/tasks.md#task-1
     */
    private function evaluateFilters(array $eventData, array $filters): bool
    {
        $expressionLanguage = new ExpressionLanguage();

        foreach ($filters as $filter) {
            foreach ($filter as $dialect => $condition) {
                switch ($dialect) {
                    case 'exact':
                        foreach ($condition as $field => $value) {
                            if (($eventData[$field] ?? null) !== $value) {
                                return false;
                            }
                        }
                        break;

                    case 'prefix':
                        foreach ($condition as $field => $value) {
                            if (str_starts_with(($eventData[$field] ?? ''), $value) === false) {
                                return false;
                            }
                        }
                        break;

                    case 'suffix':
                        foreach ($condition as $field => $value) {
                            if (str_ends_with(($eventData[$field] ?? ''), $value) === false) {
                                return false;
                            }
                        }
                        break;

                    case 'expression':
                        if ($expressionLanguage->evaluate($condition, $eventData) === false) {
                            return false;
                        }
                        break;
                }//end switch
            }//end foreach
        }//end foreach

        return true;

    }//end evaluateFilters()

    /**
     * Create a new event message.
     *
     * @param ObjectEntity $event        The event to materialise.
     * @param ObjectEntity $subscription The subscription owning the message.
     *
     * @return ObjectEntity
     *
     * @throws \OCP\DB\Exception On persistence failure.
     *
     * @spec openspec/changes/retrofit-2026-05-24-events-cloudevents/tasks.md#task-1
     */
    private function createEventMessage(ObjectEntity $event, ObjectEntity $subscription): ObjectEntity
    {
        $eventData        = $event->getObject();
        $subscriptionData = $subscription->getObject();

        return $this->objectService->saveObject(
            object: [
                'eventId'        => $event->getUuid(),
                'consumerId'     => ($subscriptionData['consumerId'] ?? null),
                'subscriptionId' => $subscription->getUuid(),
                'status'         => 'pending',
                'payload'        => $event->jsonSerialize(),
                'created'        => (new DateTime())->format('c'),
                'updated'        => (new DateTime())->format('c'),
            ],
            register: 'openconnector',
            schema: 'event_message'
        );

    }//end createEventMessage()

    /**
     * Attempt to deliver a message.
     *
     * @param ObjectEntity $message The pending message to deliver.
     *
     * @return boolean True when delivered successfully.
     *
     * @spec openspec/changes/openconnector-webhook-signing/tasks.md#task-2
     */
    public function deliverMessage(ObjectEntity $message): bool
    {
        try {
            $messageData    = $message->getObject();
            $subscriptionId = ($messageData['subscriptionId'] ?? null);

            if ($subscriptionId === null) {
                return false;
            }

            $subscription = $this->objectService->find(
                id: $subscriptionId,
                register: 'openconnector',
                schema: 'event_subscription'
            );

            if ($subscription === null) {
                return false;
            }

            $subscriptionData = $subscription->getObject();

            if (($subscriptionData['style'] ?? '') !== 'push') {
                return false;
            }

            // Serialize the body exactly once; the signature (when configured)
            // covers these exact bytes.
            $rawBody = json_encode($messageData['payload'] ?? []);

            $headers = [
                'Content-Type' => 'application/cloudevents+json',
                ...($subscriptionData['protocolSettings']['headers'] ?? []),
            ];

            $signingSecret = ($subscriptionData['protocolSettings']['signingSecret'] ?? null);
            if ($signingSecret !== null && $signingSecret !== '') {
                // A signing failure must surface as a failed attempt, not an
                // unsigned send: let any exception propagate to the failure path.
                $previousSecret = null;
                if ($this->signatureService->isRotationGraceActive(
                    secretRotatedAt: ($subscriptionData['protocolSettings']['secretRotatedAt'] ?? null)
                ) === true
                ) {
                    $previousSecret = ($subscriptionData['protocolSettings']['previousSigningSecret'] ?? null);
                }

                $headers['X-OpenConnector-Signature'] = $this->signatureService->sign(
                    rawBody: $rawBody,
                    secret: $signingSecret,
                    previousSecret: $previousSecret
                );
                $headers['X-OpenConnector-Event-Id']  = $message->getUuid();
            }

            $client   = $this->clientService->newClient();
            $response = $client->post(
                    $subscriptionData['sink'],
                    [
                        'body'    => $rawBody,
                        'headers' => $headers,
                        'timeout' => 30,
                    ]
                    );

            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                $messageData['status']           = 'delivered';
                $messageData['deliveredAt']      = (new DateTime())->format('c');
                $messageData['deliveryResponse'] = [
                    'statusCode' => $response->getStatusCode(),
                    'body'       => $response->getBody(),
                ];
                $this->objectService->saveObject(
                    object: $messageData,
                    register: 'openconnector',
                    schema: 'event_message',
                    uuid: $message->getUuid()
                );
                return true;
            }

            throw new Exception('Delivery failed with status code: '.$response->getStatusCode());
        } catch (Exception $e) {
            $this->logger->error(
                    'Failed to deliver message: '.$e->getMessage(),
                    [
                        'exception' => $e,
                        'message'   => $message->jsonSerialize(),
                    ]
                    );

            $messageDataFail           = $message->getObject();
            $messageDataFail['status'] = 'failed';
            $messageDataFail['error']  = $e->getMessage();
            $this->objectService->saveObject(
                object: $messageDataFail,
                register: 'openconnector',
                schema: 'event_message',
                uuid: $message->getUuid()
            );

            return false;
        }//end try

    }//end deliverMessage()

    /**
     * Process pending message retries.
     *
     * @param integer $maxRetries Maximum number of retry attempts.
     *
     * @return integer Number of successfully delivered messages.
     *
     * @spec openspec/changes/retrofit-2026-05-24-events-cloudevents/tasks.md#task-2
     */
    public function processRetries(int $maxRetries=5): int
    {
        $matches      = $this->objectService->findAll(
                config: [
                    'filters' => [
                        'register' => 'openconnector',
                        'schema'   => 'event_message',
                        'status'   => 'pending',
                    ],
                ]
                );
        $messages     = ($matches['results'] ?? $matches);
        $successCount = 0;

        foreach ($messages as $message) {
            $messageData = $message->getObject();
            $retryCount  = (int) ($messageData['retryCount'] ?? 0);
            if ($retryCount < $maxRetries && $this->deliverMessage(message: $message) === true) {
                $successCount++;
            }
        }

        return $successCount;

    }//end processRetries()

    /**
     * Get events for a pull-based subscription.
     *
     * @param ObjectEntity $subscription The owning subscription.
     * @param integer|null $limit        Maximum number of messages to return.
     * @param string|null  $cursor       Pagination cursor from the previous call.
     *
     * @return array{messages: ObjectEntity[], cursor: string|null}
     *
     * @spec openspec/changes/retrofit-2026-05-24-events-cloudevents/tasks.md#task-3
     */
    public function pullEvents(ObjectEntity $subscription, ?int $limit=100, ?string $cursor=null): array
    {
        $filters = [
            'register'       => 'openconnector',
            'schema'         => 'event_message',
            'subscriptionId' => $subscription->getUuid(),
            'status'         => 'pending',
        ];

        if ($cursor !== null) {
            $filters['id'] = ['>' => $cursor];
        }

        $matches  = $this->objectService->findAll(
                config: [
                    'filters' => $filters,
                    'limit'   => ($limit ?? 100),
                ]
                );
        $messages = ($matches['results'] ?? $matches);
        if (count($messages) > 0) {
            $lastCursor = end($messages)->getUuid();
        } else {
            $lastCursor = null;
        }

        return [
            'messages' => $messages,
            'cursor'   => $lastCursor,
        ];

    }//end pullEvents()

    /**
     * Handle object creation by creating and processing a CloudEvent.
     *
     * @param ObjectEntity $object The created object.
     *
     * @return ObjectEntity[] The created CloudEvent messages.
     *
     * @throws Exception        On event processing failure.
     * @throws \OCP\DB\Exception On persistence failure.
     *
     * @spec openspec/changes/retrofit-2026-05-24-events-cloudevents/tasks.md#task-4
     */
    public function handleObjectCreated(ObjectEntity $object): array
    {
        $objectData = $object->getObject();
        $event      = $this->objectService->saveObject(
            object: [
                'source'  => ('/objects/'.($objectData['type'] ?? '')),
                'type'    => 'com.nextcloud.openregister.object.created',
                'time'    => (new DateTime())->format('c'),
                'subject' => $object->getUuid(),
                'data'    => [
                    'type'       => ($objectData['type'] ?? null),
                    'id'         => $object->getUuid(),
                    'attributes' => $objectData,
                ],
                'userId'  => ($objectData['userId'] ?? null),
            ],
            register: 'openconnector',
            schema: 'event'
        );

        return $this->processEvent(event: $event);

    }//end handleObjectCreated()

    /**
     * Handle object update by creating and processing a CloudEvent.
     *
     * @param ObjectEntity $oldObject The previous state of the object.
     * @param ObjectEntity $newObject The new state of the object.
     *
     * @return ObjectEntity[] The created CloudEvent messages.
     *
     * @throws Exception        On event processing failure.
     * @throws \OCP\DB\Exception On persistence failure.
     *
     * @spec openspec/changes/retrofit-2026-05-24-events-cloudevents/tasks.md#task-4
     */
    public function handleObjectUpdated(ObjectEntity $oldObject, ObjectEntity $newObject): array
    {
        $oldData = $oldObject->getObject();
        $newData = $newObject->getObject();

        $event = $this->objectService->saveObject(
            object: [
                'source'  => ('/objects/'.($newData['type'] ?? '')),
                'type'    => 'com.nextcloud.openregister.object.updated',
                'time'    => (new DateTime())->format('c'),
                'subject' => $newObject->getUuid(),
                'data'    => [
                    'type'       => ($newData['type'] ?? null),
                    'id'         => $newObject->getUuid(),
                    'attributes' => $newData,
                    'previous'   => [
                        'attributes' => $oldData,
                    ],
                ],
                'userId'  => ($newData['userId'] ?? null),
            ],
            register: 'openconnector',
            schema: 'event'
        );

        return $this->processEvent(event: $event);

    }//end handleObjectUpdated()

    /**
     * Handle object deletion by creating and processing a CloudEvent.
     *
     * @param ObjectEntity $object The deleted object.
     *
     * @return ObjectEntity[] The created CloudEvent messages.
     *
     * @throws Exception        On event processing failure.
     * @throws \OCP\DB\Exception On persistence failure.
     *
     * @spec openspec/changes/retrofit-2026-05-24-events-cloudevents/tasks.md#task-4
     */
    public function handleObjectDeleted(ObjectEntity $object): array
    {
        $objectData = $object->getObject();

        $event = $this->objectService->saveObject(
            object: [
                'source'  => ('/objects/'.($objectData['type'] ?? '')),
                'type'    => 'com.nextcloud.openregister.object.deleted',
                'time'    => (new DateTime())->format('c'),
                'subject' => $object->getUuid(),
                'data'    => [
                    'type' => ($objectData['type'] ?? null),
                    'id'   => $object->getUuid(),
                ],
                'userId'  => ($objectData['userId'] ?? null),
            ],
            register: 'openconnector',
            schema: 'event'
        );

        return $this->processEvent(event: $event);

    }//end handleObjectDeleted()
}//end class

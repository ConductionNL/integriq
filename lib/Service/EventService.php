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
use OCA\OpenConnector\Exception\InvalidMessageStateException;
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
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) -- emitCloudEvent() (peppol-access-point-connector)
 * generalises handleObjectCreated/Updated/Deleted for connectors that need a domain-specific
 * CloudEvent `type`; splitting the class would fragment the single owner of `event` OR-object
 * construction + fan-out without reducing real complexity.
 *
 * @spec openspec/changes/openconnector-webhook-signing/tasks.md#task-2
 * @spec openspec/changes/openconnector-event-retry-hardening/tasks.md#task-2
 * @spec openspec/changes/peppol-access-point-connector/specs/peppol-access-point-connector/spec.md#requirement-delivery-status-cloudevents-on-every-state-change-req-004
 */
class EventService
{
    /**
     * Base backoff interval in seconds for the first retry.
     *
     * @var integer
     */
    private const RETRY_BASE_SECONDS = 60;

    /**
     * Exponential growth factor between retries.
     *
     * @var integer
     */
    private const RETRY_FACTOR = 4;

    /**
     * Maximum backoff interval in seconds (6 hours).
     *
     * @var integer
     */
    private const RETRY_CAP_SECONDS = 21600;

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
     * @spec openspec/changes/openconnector-event-retry-hardening/tasks.md#task-2
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
                $now           = (new DateTime())->format('c');
                $priorAttempts = (array) ($messageData['attempts'] ?? []);
                $messageData['status']           = 'delivered';
                $messageData['deliveredAt']      = $now;
                $messageData['lastAttempt']      = $now;
                $messageData['nextAttempt']      = null;
                $messageData['deliveryResponse'] = [
                    'statusCode' => $response->getStatusCode(),
                    'body'       => $response->getBody(),
                ];
                $messageData['attempts']         = $this->appendAttempt(
                    attempts: $priorAttempts,
                    at: $now,
                    statusCode: $response->getStatusCode(),
                    error: null
                );
                $this->objectService->saveObject(
                    object: $messageData,
                    register: 'openconnector',
                    schema: 'event_message',
                    uuid: $message->getUuid()
                );
                return true;
            }//end if

            $statusCode = $response->getStatusCode();
            $retryAfter = $this->parseRetryAfter(header: $response->getHeader('Retry-After'));
            $this->recordFailure(
                message: $message,
                error: 'Delivery failed with status code: '.$statusCode,
                statusCode: $statusCode,
                retryAfter: $retryAfter
            );

            return false;
        } catch (Exception $e) {
            $this->logger->error(
                    'Failed to deliver message: '.$e->getMessage(),
                    [
                        'exception' => $e,
                        'message'   => $message->jsonSerialize(),
                    ]
                    );

            $this->recordFailure(
                message: $message,
                error: $e->getMessage(),
                statusCode: null,
                retryAfter: null
            );

            return false;
        }//end try

    }//end deliverMessage()

    /**
     * Record a failed delivery attempt: increment retryCount, append an audit
     * entry, schedule the next backoff (or transition to terminal abandoned).
     *
     * @param ObjectEntity $message    The message being delivered.
     * @param string       $error      The human-readable failure reason.
     * @param integer|null $statusCode The HTTP status code, or null on a transport exception.
     * @param integer|null $retryAfter A Retry-After delay in seconds, or null when absent.
     * @param integer      $maxRetries Maximum number of attempts before abandoning.
     *
     * @return void
     *
     * @throws \OCP\DB\Exception On persistence failure.
     *
     * @spec openspec/changes/openconnector-event-retry-hardening/tasks.md#task-2
     */
    private function recordFailure(
        ObjectEntity $message,
        string $error,
        ?int $statusCode,
        ?int $retryAfter,
        int $maxRetries=5
    ): void {
        $messageData   = $message->getObject();
        $retryCount    = ((int) ($messageData['retryCount'] ?? 0) + 1);
        $priorAttempts = (array) ($messageData['attempts'] ?? []);
        $now           = new DateTime();
        $nowIso        = $now->format('c');

        // Transport exceptions (no statusCode) record the error on the attempt;
        // HTTP-level failures record only the statusCode.
        if ($statusCode === null) {
            $attemptError = $error;
        } else {
            $attemptError = null;
        }

        $messageData['retryCount']  = $retryCount;
        $messageData['lastAttempt'] = $nowIso;
        $messageData['error']       = $error;
        $messageData['attempts']    = $this->appendAttempt(
            attempts: $priorAttempts,
            at: $nowIso,
            statusCode: $statusCode,
            error: $attemptError
        );

        if ($retryCount >= $maxRetries) {
            // Terminal: no further attempts will be scheduled.
            $messageData['status']      = 'abandoned';
            $messageData['nextAttempt'] = null;
        } else {
            $messageData['status']      = 'failed';
            $messageData['nextAttempt'] = $this->computeNextAttempt(
                base: $now,
                retryCount: $retryCount,
                retryAfter: $retryAfter
            );
        }

        $this->objectService->saveObject(
            object: $messageData,
            register: 'openconnector',
            schema: 'event_message',
            uuid: $message->getUuid()
        );

    }//end recordFailure()

    /**
     * Compute the next-attempt timestamp from the exponential backoff schedule,
     * never earlier than a Retry-After hint when one is present.
     *
     * @param DateTime     $base       The attempt timestamp the backoff is measured from.
     * @param integer      $retryCount The (already incremented) retry count.
     * @param integer|null $retryAfter A Retry-After delay in seconds, or null.
     *
     * @return string ISO 8601 timestamp of the next scheduled attempt.
     *
     * @spec openspec/changes/openconnector-event-retry-hardening/tasks.md#task-2
     */
    private function computeNextAttempt(DateTime $base, int $retryCount, ?int $retryAfter): string
    {
        $backoff = (int) min(
            (self::RETRY_BASE_SECONDS * (self::RETRY_FACTOR ** ($retryCount - 1))),
            self::RETRY_CAP_SECONDS
        );

        // Retry-After may delay the retry but never hasten it.
        $delay = max($backoff, ($retryAfter ?? 0));

        $next = (clone $base);
        $next->modify('+'.$delay.' seconds');
        return $next->format('c');

    }//end computeNextAttempt()

    /**
     * Parse a Retry-After header (seconds form or HTTP-date form) into a
     * non-negative number of seconds from now.
     *
     * @param string $header The raw Retry-After header value (may be empty).
     *
     * @return integer|null Seconds to wait, or null when the header is absent/unparseable.
     *
     * @spec openspec/changes/openconnector-event-retry-hardening/tasks.md#task-2
     */
    private function parseRetryAfter(string $header): ?int
    {
        $header = trim($header);
        if ($header === '') {
            return null;
        }

        // Delta-seconds form.
        if (ctype_digit($header) === true) {
            return (int) $header;
        }

        // HTTP-date form.
        $timestamp = strtotime($header);
        if ($timestamp === false) {
            return null;
        }

        $seconds = ($timestamp - time());
        return max(0, $seconds);

    }//end parseRetryAfter()

    /**
     * Append a single attempt entry to the audit trail.
     *
     * @param array        $attempts   The existing attempts array.
     * @param string       $at         ISO 8601 timestamp of the attempt.
     * @param integer|null $statusCode HTTP status code, or null on transport failure.
     * @param string|null  $error      Transport/error message, or null on HTTP-level outcome.
     *
     * @return array The attempts array with the new entry appended.
     *
     * @spec openspec/changes/openconnector-event-retry-hardening/tasks.md#task-2
     */
    private function appendAttempt(array $attempts, string $at, ?int $statusCode, ?string $error): array
    {
        $attempts[] = [
            'at'         => $at,
            'statusCode' => $statusCode,
            'error'      => $error,
        ];
        return $attempts;

    }//end appendAttempt()

    /**
     * Replay a dead-lettered message back into the delivery machine.
     *
     * Resets a `failed`/`abandoned` message to `pending`, clears its retry
     * counter, schedules an immediate next attempt, preserves the existing
     * `attempts[]` history, stamps the acting operator, and triggers one
     * immediate delivery attempt. The message then re-enters the standard
     * retry/backoff/abandon machine.
     *
     * @param string $id       The message UUID.
     * @param string $actorUid The acting operator's user id.
     *
     * @return ObjectEntity The updated message.
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException When the message does not exist.
     * @throws InvalidMessageStateException               When the message is not in a replayable state.
     * @throws \OCP\DB\Exception                          On persistence failure.
     *
     * @spec openspec/changes/openconnector-dead-letter-replay/tasks.md#task-2
     */
    public function replayMessage(string $id, string $actorUid): ObjectEntity
    {
        $message     = $this->objectService->find(
            id: $id,
            register: 'openconnector',
            schema: 'event_message'
        );
        $messageData = $message->getObject();
        $status      = ($messageData['status'] ?? '');

        if (in_array($status, ['failed', 'abandoned'], true) === false) {
            throw new InvalidMessageStateException(
                message: 'Cannot replay a message in state "'.$status.'"; only failed or abandoned messages are replayable.'
            );
        }

        $now = (new DateTime())->format('c');

        $messageData['status']      = 'pending';
        $messageData['retryCount']  = 0;
        $messageData['nextAttempt'] = $now;
        $messageData['replayedBy']  = $actorUid;
        $messageData['replayedAt']  = $now;
        // Attempts[] is deliberately preserved across the replay campaign.
        $saved = $this->objectService->saveObject(
            object: $messageData,
            register: 'openconnector',
            schema: 'event_message',
            uuid: $message->getUuid()
        );

        // Re-enter the delivery machine immediately.
        $this->deliverMessage(message: $saved);

        return $this->objectService->find(
            id: $message->getUuid(),
            register: 'openconnector',
            schema: 'event_message'
        );

    }//end replayMessage()

    /**
     * Discard a dead-lettered message into the terminal `discarded` state.
     *
     * Marks a `failed`/`abandoned` message `discarded` with an operator audit
     * stamp. Discarded messages are never swept and never hard-deleted; they
     * remain queryable for audit.
     *
     * @param string $id       The message UUID.
     * @param string $actorUid The acting operator's user id.
     *
     * @return ObjectEntity The updated message.
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException When the message does not exist.
     * @throws InvalidMessageStateException               When the message is not in a discardable state.
     * @throws \OCP\DB\Exception                          On persistence failure.
     *
     * @spec openspec/changes/openconnector-dead-letter-replay/tasks.md#task-2
     */
    public function discardMessage(string $id, string $actorUid): ObjectEntity
    {
        $message     = $this->objectService->find(
            id: $id,
            register: 'openconnector',
            schema: 'event_message'
        );
        $messageData = $message->getObject();
        $status      = ($messageData['status'] ?? '');

        if (in_array($status, ['failed', 'abandoned'], true) === false) {
            throw new InvalidMessageStateException(
                message: 'Cannot discard a message in state "'.$status.'"; only failed or abandoned messages are discardable.'
            );
        }

        $now = (new DateTime())->format('c');

        $messageData['status']      = 'discarded';
        $messageData['nextAttempt'] = null;
        $messageData['discardedBy'] = $actorUid;
        $messageData['discardedAt'] = $now;

        return $this->objectService->saveObject(
            object: $messageData,
            register: 'openconnector',
            schema: 'event_message',
            uuid: $message->getUuid()
        );

    }//end discardMessage()

    /**
     * Process pending message retries.
     *
     * @param integer $maxRetries Maximum number of retry attempts.
     *
     * @return integer Number of successfully delivered messages.
     *
     * @spec openspec/changes/openconnector-event-retry-hardening/tasks.md#task-3
     */
    public function processRetries(int $maxRetries=5): int
    {
        // Terminal states (delivered, abandoned, discarded) are never selected.
        // Both 'pending' (crash-stranded or never-attempted) and 'failed'
        // messages are eligible.
        $matches      = $this->objectService->findAll(
                config: [
                    'filters' => [
                        'register' => 'openconnector',
                        'schema'   => 'event_message',
                        'status'   => ['pending', 'failed'],
                    ],
                ]
                );
        $messages     = ($matches['results'] ?? $matches);
        $successCount = 0;
        $now          = time();

        foreach ($messages as $message) {
            $messageData = $message->getObject();
            $status      = ($messageData['status'] ?? '');
            if (in_array($status, ['pending', 'failed'], true) === false) {
                continue;
            }

            $retryCount = (int) ($messageData['retryCount'] ?? 0);
            if ($retryCount >= $maxRetries) {
                continue;
            }

            // Honour the scheduled backoff: skip messages not yet due.
            $nextAttempt = ($messageData['nextAttempt'] ?? null);
            if ($nextAttempt !== null && $nextAttempt !== '') {
                $due = strtotime((string) $nextAttempt);
                if ($due !== false && $due > $now) {
                    continue;
                }
            }

            if ($this->deliverMessage(message: $message) === true) {
                $successCount++;
            }
        }//end foreach

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
     * Construct and process an arbitrary CloudEvent.
     *
     * Generalises the `handleObjectCreated`/`Updated`/`Deleted` shape (build a
     * CloudEvents-shaped `event` OR object, then fan it out via
     * {@see processEvent}) for connectors that need to emit a domain-specific
     * event `type` — e.g. `nl.conduction.peppol.delivery.status` — rather than
     * one of the fixed `com.nextcloud.openregister.object.*` types.
     *
     * @param string      $type    The CloudEvents `type` attribute (e.g. `nl.conduction.peppol.delivery.status`).
     * @param string      $source  The CloudEvents `source` attribute (the producing component identity).
     * @param string|null $subject The CloudEvents `subject` attribute, or null when not applicable.
     * @param array       $data    The CloudEvents `data` payload.
     * @param string|null $userId  The Nextcloud user id that produced this event, or null for system-produced.
     *
     * @return ObjectEntity[] The created CloudEvent messages.
     *
     * @throws Exception         On event processing failure.
     * @throws \OCP\DB\Exception On persistence failure.
     *
     * @spec openspec/changes/peppol-access-point-connector/specs/peppol-access-point-connector/spec.md#requirement-delivery-status-cloudevents-on-every-state-change-req-004
     */
    public function emitCloudEvent(string $type, string $source, ?string $subject, array $data, ?string $userId=null): array
    {
        $event = $this->objectService->saveObject(
            object: [
                'source'  => $source,
                'type'    => $type,
                'time'    => (new DateTime())->format('c'),
                'subject' => $subject,
                'data'    => $data,
                'userId'  => $userId,
            ],
            register: 'openconnector',
            schema: 'event'
        );

        return $this->processEvent(event: $event);

    }//end emitCloudEvent()

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

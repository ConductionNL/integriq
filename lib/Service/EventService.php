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
use JWadhams\JsonLogic;
use OCA\OpenConnector\Exception\FormsFeatureDisabledException;
use OCA\OpenConnector\Exception\InvalidMessageStateException;
use OCA\OpenConnector\Service\Forms\FormsAnswerResolver;
use OCA\OpenConnector\Service\Forms\FormsSyncAdapter;
use OCA\OpenConnector\Service\Helper\ExecutionTraceContext;
use OCA\OpenConnector\Service\Security\SensitiveFieldRegistry;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use OCA\OpenConnector\Service\ExecutionTraceService;
use OCA\OpenConnector\Service\WebhookSignatureService;
use OCA\OpenConnector\Service\JobService;
use OCA\OpenConnector\Service\MappingService;
use OCA\OpenConnector\Service\SynchronizationService;

/**
 * Service class for managing events and their delivery.
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)     -- emitCloudEvent() (peppol-access-point-connector)
 * generalises handleObjectCreated/Updated/Deleted for connectors that need a domain-specific
 * CloudEvent `type`; splitting the class would fragment the single owner of `event` OR-object
 * construction + fan-out without reducing real complexity.
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     -- nextcloud-event-hub added the
 * action-dispatch (webhook/synchronization/job) + retryPolicy machinery to this
 * single owner of the `event`/`event_message` state machine; keeping delivery,
 * dispatch, retry, and dead-letter/replay in one class is the deliberate reuse
 * constraint (design.md), not sprawl.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   -- the SynchronizationService +
 * JobService deps are the two new action-dispatch targets (REQ-008); they cannot
 * be delivered without referencing them.
 * @SuppressWarnings(PHPMD.LongVariable)             -- $synchronizationService reads clearer
 * than an abbreviation for the ADR-023 action target.
 * @SuppressWarnings(PHPMD.TooManyMethods)           -- dispatchNotificatiesAction() (REQ-010,
 * notificaties-api-subscriber) and dispatchMappingAction() (REQ-012, nextcloud-forms-connector)
 * are the fourth and fifth action-dispatch branches (webhook/synchronization/job/notificaties/
 * mapping), the same single-owner reuse constraint as the ExcessiveClassLength
 * suppression above; splitting it into a sibling class would duplicate the retry/backoff/
 * dead-letter bookkeeping it exists to reuse.
 * @SuppressWarnings(PHPMD.StaticAccess)             -- NotificatiesSubscriberService::
 * buildNotificationBody() is deliberately static (pure function, no service dependencies)
 * so this class can call it directly without a constructor dependency on
 * NotificatiesSubscriberService — that class already depends on EventService for inbound
 * normalization, so a two-way constructor dependency would be circular (see that class's
 * docblock).
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
     * Default maximum number of delivery attempts before a message reaches
     * terminal `abandoned`, used when a subscription declares no
     * `retryPolicy.maxRetries` override.
     *
     * @var integer
     *
     * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-subscriptions-retrybackoff-policy-must-be-independently-configurable-req-009
     */
    private const DEFAULT_MAX_RETRIES = 5;

    /**
     * Source prefix used by every `nextcloud-event-triggers` NC-native
     * producer (`/nextcloud/files`, `/nextcloud/calendar`, `/nextcloud/tables`,
     * `/nextcloud/forms`) — distinct from the pre-existing `/objects/<type>`
     * prefix OR-object events use, so provenance filtering never collides
     * with the shared `com.nextcloud.` TYPE prefix both namespaces use.
     *
     * @var string
     *
     * @spec openspec/specs/dead-letter-replay/spec.md#requirement-dead-letter-listing-and-detail-must-surface-action-kind-and-nextcloud-event-provenance-req-dlr-013
     */
    public const NEXTCLOUD_SOURCE_PREFIX = '/nextcloud/';

    /**
     * Constructor.
     *
     * @param ORObjectService         $objectService          The OR ObjectService for data access.
     * @param IClientService          $clientService          HTTP client service used to deliver push messages.
     * @param LoggerInterface         $logger                 Logger for delivery successes and failures.
     * @param WebhookSignatureService $signatureService       Signs outbound deliveries when configured.
     * @param SynchronizationService  $synchronizationService Runs `action.kind = 'synchronization'` dispatches (REQ-008).
     * @param JobService              $jobService             Runs `action.kind = 'job'` dispatches (REQ-008).
     * @param CallService             $callService            Runs `action.kind = 'notificaties'`/`'mapping'`
     *                                                        dispatches against a `Source` (REQ-010/REQ-012).
     * @param FlowRunnerService       $flowRunnerService      Runs `action.kind = 'flow'`
     *                                                        dispatches (visual-flow-orchestration,
     *                                                        event-triggered flows).
     * @param MappingService          $mappingService         Runs the answer->target transform for `action.kind = 'mapping'` (REQ-012).
     * @param FormsAnswerResolver     $formsAnswerResolver    Answer-by-question resolution for `action.kind = 'mapping'` (REQ-012).
     * @param FormsSyncAdapter        $formsSyncAdapter       Feature detection + submission/form fetch for `action.kind = 'mapping'`
     *                                                        (nullable, mirrors `SynchronizationService`'s `?TablesSyncAdapter` pattern — REQ-012).
     * @param ExecutionTraceService|null $executionTraceService Persists the traced delivery attempt's
     *                                                        execution_trace (execution-trace REQ-001/REQ-004).
     *                                                        Nullable + defaulted so pre-existing positional
     *                                                        test instantiations keep working unmodified.
     *
     * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-subscriptions-action-dispatch-must-support-webhook-synchronization-or-job-kinds-req-008
     * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-subscriptions-action-dispatch-must-support-a-notificaties-kind-for-zgw-notificaties-api-publishing-req-010
     * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-subscriptions-action-dispatch-may-additionally-support-a-mapping-kind-req-012
     * @spec openspec/specs/flow-orchestration/spec.md#requirement-a-flow-runs-via-cron-endpoint-rule-event-or-manual-trigger-req-007
     */
    public function __construct(
        private readonly ORObjectService $objectService,
        private readonly IClientService $clientService,
        private readonly LoggerInterface $logger,
        private readonly WebhookSignatureService $signatureService,
        private readonly SynchronizationService $synchronizationService,
        private readonly JobService $jobService,
        private readonly CallService $callService,
        private readonly FlowRunnerService $flowRunnerService,
        private readonly ?MappingService $mappingService=null,
        private readonly ?FormsAnswerResolver $formsAnswerResolver=null,
        private readonly ?FormsSyncAdapter $formsSyncAdapter=null,
        private readonly ?ExecutionTraceService $executionTraceService=null,
    ) {

    }//end __construct()

    /**
     * Cheap existence check: is there at least one active `event_subscription`
     * anywhere on this instance?
     *
     * Used as a firehose gate by {@see \OCA\OpenConnector\EventListener\CloudEventListener}
     * so that an install with zero configured subscriptions (the common case —
     * outbound webhooks are opt-in) pays no persistence cost at all for OR
     * object mutations fleet-wide: no `event` record is written, no matching
     * logic runs. An install that HAS at least one active subscription falls
     * through to the full, already-specified {@see processEvent} contract
     * (REQ-001) unchanged.
     *
     * @return boolean True when at least one `event_subscription` with
     *                  `status = 'active'` exists.
     *
     * @spec openspec/changes/outbound-webhooks-activation/tasks.md#task-3
     */
    public function hasActiveSubscriptions(): bool
    {
        $matches = $this->objectService->findAll(
                config: [
                    'filters' => [
                        'register' => 'openconnector',
                        'schema'   => 'event_subscription',
                        'status'   => 'active',
                    ],
                    'limit'   => 1,
                ]
                );
        $results = ($matches['results'] ?? $matches);

        return (count($results) > 0);

    }//end hasActiveSubscriptions()

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
     * @spec openspec/specs/events-cloudevents/spec.md#requirement-cloudevent-fan-out-to-matching-subscriptions-req-001
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
                    // If it's a push subscription, attempt immediate delivery via
                    // the subscription's resolved action (webhook by default —
                    // see REQ-008).
                    if (($subscriptionData['style'] ?? '') === 'push') {
                        $this->attemptDelivery(message: $message, subscription: $subscription);
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
     * @SuppressWarnings(PHPMD.StaticAccess) -- JWadhams\JsonLogic exposes only a
     * static `apply()` entry point (same call convention EndpointService's
     * rule-condition engine already uses); there is no instance API to inject.
     *
     * @spec openspec/changes/retrofit-2026-05-24-events-cloudevents/tasks.md#task-1
     * @spec openspec/specs/events-cloudevents/spec.md#requirement-cloudevent-fan-out-to-matching-subscriptions-req-001
     */
    private function evaluateFilters(array $eventData, array $filters): bool
    {
        // Lazily instantiated: symfony/expression-language is not this app's
        // OWN direct composer dependency (it is transitively bundled by NC
        // core in production, masking the gap there) — eagerly constructing
        // it here for every evaluateFilters() call, even when no `expression`
        // filter is present, made every OTHER dialect (including this
        // change's `jsonlogic`) fail with "Class ... ExpressionLanguage not
        // found" in the standalone composer/PHPUnit environment. Pre-existing
        // bug, uncovered while adding jsonlogic test coverage — no prior test
        // exercised this method at all.
        $expressionLanguage = null;

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
                        if ($expressionLanguage === null) {
                            $expressionLanguage = new ExpressionLanguage();
                        }

                        if ($expressionLanguage->evaluate($condition, $eventData) === false) {
                            return false;
                        }
                        break;

                    case 'jsonlogic':
                        // Same jwadhams/json-logic-php library EndpointService's rule-condition
                        // engine already uses. JsonLogic::apply can return non-boolean truthy/
                        // falsy values (e.g. `""`, `0`, an empty array); a `(bool)` cast applies
                        // PHP's standard truthiness so the filter gates correctly without a
                        // loose `==` comparison.
                        if ((bool) JsonLogic::apply(logic: $condition, data: $eventData) === false) {
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
     * @param ObjectEntity               $message The pending message to deliver.
     * @param ExecutionTraceContext|null $trace   The active execution trace context. `deliverMessage()` dispatches
     *                                            via `IClientService` directly (not `CallService`), so — unlike
     *                                            every other outbound-call path — this method redacts and
     *                                            appends its own `call` step (execution-trace REQ-002/REQ-003).
     *
     * @return boolean True when delivered successfully.
     *
     * @spec openspec/changes/openconnector-webhook-signing/tasks.md#task-2
     * @spec openspec/changes/openconnector-event-retry-hardening/tasks.md#task-2
     * @spec openspec/specs/events-cloudevents/spec.md#requirement-push-delivery-with-status-tracking-and-retry-sweep-req-002
     */
    public function deliverMessage(ObjectEntity $message, ?ExecutionTraceContext $trace=null): bool
    {
        $callStepStart = microtime(true);

        try {
            $messageData    = $message->getObject();
            $subscriptionId = ($messageData['subscriptionId'] ?? null);

            if ($subscriptionId === null) {
                return false;
            }

            // System context (ocon#147): `protocolSettings` is `writeOnly`, so a
            // rendered read returns the subscription WITHOUT its signingSecret and
            // headers. This is the delivery engine reading a subscription in order
            // to sign an outbound push — not a user reading it — so it reads in
            // system context exactly as CallService reads a source's credential.
            // Without `_rbac: false` every push would silently go out UNSIGNED.
            $subscription = $this->objectService->find(
                id: $subscriptionId,
                register: 'openconnector',
                schema: 'event_subscription',
                _rbac: false,
                _multitenancy: false
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

            // Execution-trace REQ-002/REQ-003: this dispatch bypasses
            // CallService (it uses IClientService directly), so it must
            // redact and record its own `call` step rather than relying on
            // CallService's already-redacted output.
            if ($trace !== null) {
                $sensitiveFieldRegistry = new SensitiveFieldRegistry();

                $callStepStatus = 'success';
                if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                    $callStepStatus = 'error';
                }

                $callStepInput  = $sensitiveFieldRegistry->redactArray(
                    data: [
                        'url'     => ($subscriptionData['sink'] ?? null),
                        'method'  => 'POST',
                        'headers' => $headers,
                    ]
                );
                $callStepOutput = $sensitiveFieldRegistry->redactArray(
                    data: [
                        'statusCode' => $response->getStatusCode(),
                        'headers'    => $response->getHeaders(),
                    ]
                );

                $trace->addStep(
                    type: 'call',
                    name: ($subscriptionData['name'] ?? $subscriptionData['sink'] ?? 'webhook'),
                    timing: null,
                    status: $callStepStatus,
                    input: $callStepInput,
                    output: $callStepOutput,
                    startedAtMicrotime: $callStepStart,
                    finishedAtMicrotime: microtime(true),
                );
            }//end if

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
                retryAfter: $retryAfter,
                retryPolicy: $this->resolveRetryPolicy(subscriptionData: $subscriptionData)
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

            // $subscriptionData may be undefined when the exception was thrown
            // before it was resolved (e.g. signing failure occurs after the
            // subscription lookup, so it is always available here in practice,
            // but defend against a future early-throw regardless).
            $this->recordFailure(
                message: $message,
                error: $e->getMessage(),
                statusCode: null,
                retryAfter: null,
                retryPolicy: $this->resolveRetryPolicy(subscriptionData: ($subscriptionData ?? []))
            );

            return false;
        }//end try

    }//end deliverMessage()

    /**
     * Resolve a subscription's effective retry policy, falling back to the
     * class defaults for any key it does not declare.
     *
     * Each `retryPolicy` key overrides independently — a subscription that
     * sets only `maxRetries` still uses the default `baseSeconds`/`factor`/
     * `capSeconds` (REQ-009).
     *
     * @param array $subscriptionData The subscription's OR object array.
     *
     * @return array{baseSeconds: integer, factor: integer, capSeconds: integer, maxRetries: integer}
     *
     * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-subscriptions-retrybackoff-policy-must-be-independently-configurable-req-009
     */
    private function resolveRetryPolicy(array $subscriptionData): array
    {
        $policy = $subscriptionData['retryPolicy'] ?? [];
        if (is_array($policy) === false) {
            $policy = [];
        }

        return [
            'baseSeconds' => (int) ($policy['baseSeconds'] ?? self::RETRY_BASE_SECONDS),
            'factor'      => (int) ($policy['factor'] ?? self::RETRY_FACTOR),
            'capSeconds'  => (int) ($policy['capSeconds'] ?? self::RETRY_CAP_SECONDS),
            'maxRetries'  => (int) ($policy['maxRetries'] ?? self::DEFAULT_MAX_RETRIES),
        ];

    }//end resolveRetryPolicy()

    /**
     * Record a failed delivery attempt: increment retryCount, append an audit
     * entry, schedule the next backoff (or transition to terminal abandoned).
     *
     * @param ObjectEntity $message     The message being delivered.
     * @param string       $error       The human-readable failure reason.
     * @param integer|null $statusCode  The HTTP status code, or null on a transport exception.
     * @param integer|null $retryAfter  A Retry-After delay in seconds, or null when absent.
     * @param array        $retryPolicy Resolved {baseSeconds,factor,capSeconds,maxRetries}; empty uses
     *                                  the class defaults (see {@see resolveRetryPolicy}).
     *
     * @return void
     *
     * @throws \OCP\DB\Exception On persistence failure.
     *
     * @spec openspec/changes/openconnector-event-retry-hardening/tasks.md#task-2
     * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-subscriptions-retrybackoff-policy-must-be-independently-configurable-req-009
     */
    private function recordFailure(
        ObjectEntity $message,
        string $error,
        ?int $statusCode,
        ?int $retryAfter,
        array $retryPolicy=[]
    ): void {
        $messageData   = $message->getObject();
        $retryCount    = ((int) ($messageData['retryCount'] ?? 0) + 1);
        $priorAttempts = (array) ($messageData['attempts'] ?? []);
        $now           = new DateTime();
        $nowIso        = $now->format('c');
        $maxRetries    = (int) ($retryPolicy['maxRetries'] ?? self::DEFAULT_MAX_RETRIES);

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
                retryAfter: $retryAfter,
                retryPolicy: $retryPolicy
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
     * @param DateTime     $base        The attempt timestamp the backoff is measured from.
     * @param integer      $retryCount  The (already incremented) retry count.
     * @param integer|null $retryAfter  A Retry-After delay in seconds, or null.
     * @param array        $retryPolicy Resolved {baseSeconds,factor,capSeconds}; empty uses the class defaults.
     *
     * @return string ISO 8601 timestamp of the next scheduled attempt.
     *
     * @spec openspec/changes/openconnector-event-retry-hardening/tasks.md#task-2
     * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-subscriptions-retrybackoff-policy-must-be-independently-configurable-req-009
     */
    private function computeNextAttempt(DateTime $base, int $retryCount, ?int $retryAfter, array $retryPolicy=[]): string
    {
        $baseSeconds = (int) ($retryPolicy['baseSeconds'] ?? self::RETRY_BASE_SECONDS);
        $factor      = (int) ($retryPolicy['factor'] ?? self::RETRY_FACTOR);
        $capSeconds  = (int) ($retryPolicy['capSeconds'] ?? self::RETRY_CAP_SECONDS);

        $backoff = (int) min(
            ($baseSeconds * ($factor ** ($retryCount - 1))),
            $capSeconds
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
     * Resolve a message's subscription (when not already known) and dispatch
     * the delivery attempt through the subscription's effective `action.kind`
     * — `webhook` (default, unchanged `deliverMessage` behaviour),
     * `synchronization`, or `job`. The single dispatch point used by
     * {@see processEvent}, {@see processRetries}, and {@see replayMessage} so
     * the sweep and a replay re-attempt the SAME kind of action that
     * originally ran/failed (`dead-letter-replay` REQ-DLR-003).
     *
     * @param ObjectEntity               $message      The message to attempt delivery for.
     * @param ObjectEntity|null          $subscription The already-resolved subscription, when the
     *                                                 caller has it in hand (avoids a redundant
     *                                                 find()); resolved from
     *                                                 `message.subscriptionId` when null.
     * @param ExecutionTraceContext|null $trace        The active execution trace context. When null (the common
     *                                                 publish/retry-sweep case), a fresh `event`-entryPoint
     *                                                 context is minted here and its persistence owned by this
     *                                                 method (execution-trace REQ-001/REQ-004).
     *
     * @return boolean True when the attempt succeeded.
     *
     * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-subscriptions-action-dispatch-must-support-webhook-synchronization-or-job-kinds-req-008
     * @spec openspec/specs/execution-trace/spec.md#requirement-execution-id-minted-at-every-entry-point-and-propagated-through-the-pipeline-req-001
     */
    private function attemptDelivery(ObjectEntity $message, ?ObjectEntity $subscription=null, ?ExecutionTraceContext $trace=null): bool
    {
        $ownsEventTrace = ($trace === null);
        if ($ownsEventTrace === true) {
            $trace = new ExecutionTraceContext(entryPoint: 'event', entryPointId: $message->getUuid(), triggeredBy: 'http');
        }

        $result = $this->attemptDeliveryDispatch(message: $message, subscription: $subscription, trace: $trace);

        if ($ownsEventTrace === true) {
            $attemptDeliveryTraceStatus = 'failed';
            if ($result === true) {
                $attemptDeliveryTraceStatus = 'success';
            }

            try {
                $this->executionTraceService?->persist(trace: $trace, status: $attemptDeliveryTraceStatus);
            } catch (\Throwable $exception) {
                $this->logger->warning(
                    'EventService: failed to persist execution_trace.',
                    ['traceId' => $trace->getTraceId(), 'exception' => $exception->getMessage()]
                );
            }
        }

        return $result;

    }//end attemptDelivery()

    /**
     * The action.kind switch extracted from {@see attemptDelivery()} so the
     * trace mint/persist wrapper stays a single, uncluttered choke point.
     *
     * @param ObjectEntity          $message      The message to attempt delivery for.
     * @param ObjectEntity|null     $subscription The already-resolved subscription, when known.
     * @param ExecutionTraceContext $trace        The active execution trace context.
     *
     * @return boolean True when the attempt succeeded.
     *
     * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-subscriptions-action-dispatch-must-support-webhook-synchronization-or-job-kinds-req-008
     * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-subscriptions-action-dispatch-may-additionally-support-a-mapping-kind-req-012
     */
    private function attemptDeliveryDispatch(ObjectEntity $message, ?ObjectEntity $subscription, ExecutionTraceContext $trace): bool
    {
        if ($subscription === null) {
            $messageData    = $message->getObject();
            $subscriptionId = ($messageData['subscriptionId'] ?? null);
            if ($subscriptionId === null) {
                return false;
            }

            // System context (ocon#147): see deliverMessage(). The engine dispatches
            // this subscription's action; it must see the whole subscription, and
            // `protocolSettings` is stripped from any rendered (`_rbac: true`) read.
            $subscription = $this->objectService->find(
                id: $subscriptionId,
                register: 'openconnector',
                schema: 'event_subscription',
                _rbac: false,
                _multitenancy: false
            );
            if ($subscription === null) {
                return false;
            }
        }//end if

        $subscriptionData = $subscription->getObject();
        $action           = ($subscriptionData['action'] ?? null);
        $kind = 'webhook';
        if (is_array($action) === true && empty($action['kind'] ?? '') === false) {
            $kind = (string) $action['kind'];
        }

        $actionArray = [];
        if (is_array($action) === true) {
            $actionArray = $action;
        }

        switch ($kind) {
            case 'webhook':
                // Unchanged REQ-002 behaviour: deliverMessage resolves the
                // subscription itself (accepted minor redundant find()).
                return $this->deliverMessage(message: $message, trace: $trace);

            case 'synchronization':
                return $this->dispatchSynchronizationAction(
                    message: $message,
                    subscriptionData: $subscriptionData,
                    action: $actionArray,
                    trace: $trace
                );

            case 'job':
                return $this->dispatchJobAction(
                    message: $message,
                    subscriptionData: $subscriptionData,
                    action: $actionArray,
                    trace: $trace
                );

            case 'notificaties':
                return $this->dispatchNotificatiesAction(
                    message: $message,
                    subscriptionData: $subscriptionData,
                    action: $actionArray
                );

            case 'flow':
                return $this->dispatchFlowAction(
                    message: $message,
                    subscriptionData: $subscriptionData,
                    action: $actionArray
                );

            case 'mapping':
                return $this->dispatchMappingAction(
                    message: $message,
                    subscriptionData: $subscriptionData,
                    action: $actionArray
                );

            default:
                // Unrecognised action.kind: a configuration error, not a
                // transient failure — fails once without entering the retry loop.
                $this->recordConfigurationError(
                    message: $message,
                    error: sprintf("Unrecognised action.kind '%s'", $kind)
                );
                return false;
        }//end switch

    }//end attemptDeliveryDispatch()

    /**
     * Dispatch an `action.kind = 'synchronization'` message: resolve the
     * target synchronization and run it in place of an HTTP POST. Success/
     * failure bookkeeping is identical to {@see deliverMessage}'s (REQ-002),
     * so the message is subject to the same retry/backoff/dead-letter/replay
     * machinery as a webhook delivery.
     *
     * @param ObjectEntity               $message          The message being dispatched.
     * @param array                      $subscriptionData The owning subscription's OR object array.
     * @param array                      $action           The resolved `action` block (`{kind, synchronizationId}`).
     * @param ExecutionTraceContext|null $trace            The active execution trace context, forwarded into
     *                                                     `SynchronizationService::synchronize()`
     *                                                     (execution-trace REQ-001/REQ-002).
     *
     * @return boolean True when the synchronization ran successfully.
     *
     * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-subscriptions-action-dispatch-must-support-webhook-synchronization-or-job-kinds-req-008
     */
    private function dispatchSynchronizationAction(
        ObjectEntity $message,
        array $subscriptionData,
        array $action,
        ?ExecutionTraceContext $trace=null
    ): bool {
        $retryPolicy       = $this->resolveRetryPolicy(subscriptionData: $subscriptionData);
        $synchronizationId = (string) ($action['synchronizationId'] ?? '');

        if ($synchronizationId === '') {
            $this->recordFailure(
                message: $message,
                error: 'synchronization not found',
                statusCode: null,
                retryAfter: null,
                retryPolicy: $retryPolicy
            );
            return false;
        }

        try {
            $synchronization = $this->objectService->find(
                id: $synchronizationId,
                register: 'openconnector',
                schema: 'synchronization'
            );
        } catch (\Throwable $e) {
            $synchronization = null;
        }

        if ($synchronization === null) {
            $this->recordFailure(
                message: $message,
                error: 'synchronization not found',
                statusCode: null,
                retryAfter: null,
                retryPolicy: $retryPolicy
            );
            return false;
        }

        try {
            $this->synchronizationService->synchronize(synchronization: $synchronization, trace: $trace);
        } catch (\Throwable $e) {
            $this->logger->error(
                    'Failed to run synchronization action for event message: '.$e->getMessage(),
                    [
                        'exception' => $e,
                        'message'   => $message->jsonSerialize(),
                    ]
                    );
            $this->recordFailure(
                message: $message,
                error: $e->getMessage(),
                statusCode: null,
                retryAfter: null,
                retryPolicy: $retryPolicy
            );
            return false;
        }

        $this->recordDeliverySuccess(message: $message);
        return true;

    }//end dispatchSynchronizationAction()

    /**
     * Dispatch an `action.kind = 'job'` message: resolve the target job and
     * run it (`forceRun: true`) in place of an HTTP POST. `JobService::executeJob`
     * does not throw on a job-action failure; it persists a `job_log` entry
     * and returns it, so success/failure here is read from that log entry's
     * `level` field (`SUCCESS` vs anything else).
     *
     * @param ObjectEntity               $message          The message being dispatched.
     * @param array                      $subscriptionData The owning subscription's OR object array.
     * @param array                      $action           The resolved `action` block (`{kind, jobId}`).
     * @param ExecutionTraceContext|null $trace            The active execution trace context, forwarded into
     *                                                     `JobService::executeJob()` (execution-trace
     *                                                     REQ-001/REQ-002).
     *
     * @return boolean True when the job ran successfully.
     *
     * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-subscriptions-action-dispatch-must-support-webhook-synchronization-or-job-kinds-req-008
     */
    private function dispatchJobAction(ObjectEntity $message, array $subscriptionData, array $action, ?ExecutionTraceContext $trace=null): bool
    {
        $retryPolicy = $this->resolveRetryPolicy(subscriptionData: $subscriptionData);
        $jobId       = (string) ($action['jobId'] ?? '');

        if ($jobId === '') {
            $this->recordFailure(
                message: $message,
                error: 'job not found',
                statusCode: null,
                retryAfter: null,
                retryPolicy: $retryPolicy
            );
            return false;
        }

        try {
            $job = $this->objectService->find(id: $jobId, register: 'openconnector', schema: 'job');
        } catch (\Throwable $e) {
            $job = null;
        }

        if ($job === null) {
            $this->recordFailure(
                message: $message,
                error: 'job not found',
                statusCode: null,
                retryAfter: null,
                retryPolicy: $retryPolicy
            );
            return false;
        }

        try {
            $log = $this->jobService->executeJob(job: $job, forceRun: true, trace: $trace);
        } catch (\Throwable $e) {
            $this->logger->error(
                    'Failed to run job action for event message: '.$e->getMessage(),
                    [
                        'exception' => $e,
                        'message'   => $message->jsonSerialize(),
                    ]
                    );
            $this->recordFailure(
                message: $message,
                error: $e->getMessage(),
                statusCode: null,
                retryAfter: null,
                retryPolicy: $retryPolicy
            );
            return false;
        }

        $level = 'SUCCESS';
        if ($log !== null) {
            $level = (string) ($log->getObject()['level'] ?? 'SUCCESS');
        }

        if ($level !== 'SUCCESS') {
            $this->recordFailure(
                message: $message,
                error: 'job execution reported level '.$level,
                statusCode: null,
                retryAfter: null,
                retryPolicy: $retryPolicy
            );
            return false;
        }

        $this->recordDeliverySuccess(message: $message);
        return true;

    }//end dispatchJobAction()

    /**
     * Dispatch an `action.kind = 'flow'` message: resolve the target flow
     * and run it via `FlowRunnerService::run(..., triggerSource: 'event')`
     * in place of an HTTP POST — this is the event-triggered surface of
     * the four flow-orchestration triggers (REQ-007c). Success/failure
     * bookkeeping mirrors {@see dispatchSynchronizationAction}/
     * {@see dispatchJobAction} exactly, so an event-triggered flow is
     * subject to the same retry/backoff/dead-letter/replay machinery as a
     * webhook delivery. Extending an `event_subscription`'s existing
     * `action` dispatch block (the same additive extension point
     * `notificaties-api-subscriber` REQ-010 already used for `kind:
     * 'notificaties'`) is how a flow's "trigger on a CloudEvent matching a
     * configured type/source/subject" (design.md) is expressed — the
     * subscription's own `types`/`source`/`filters` fields already do that
     * matching in {@see doesEventMatchSubscription}; `action.flowId`
     * selects which flow runs once matched.
     *
     * @param ObjectEntity $message          The message being dispatched.
     * @param array        $subscriptionData The owning subscription's OR object array.
     * @param array        $action           The resolved `action` block (`{kind, flowId}`).
     *
     * @return boolean True when the flow run ended in a non-error terminal status.
     *
     * @spec openspec/specs/flow-orchestration/spec.md#requirement-a-flow-runs-via-cron-endpoint-rule-event-or-manual-trigger-req-007
     */
    private function dispatchFlowAction(ObjectEntity $message, array $subscriptionData, array $action): bool
    {
        $retryPolicy = $this->resolveRetryPolicy(subscriptionData: $subscriptionData);
        $flowId      = (string) ($action['flowId'] ?? '');

        if ($flowId === '') {
            $this->recordFailure(
                message: $message,
                error: 'flow not found',
                statusCode: null,
                retryAfter: null,
                retryPolicy: $retryPolicy
            );
            return false;
        }

        try {
            $flow = $this->flowRunnerService->findFlow(id: $flowId);
        } catch (\Throwable $e) {
            $flow = null;
        }

        if ($flow === null) {
            $this->recordFailure(
                message: $message,
                error: 'flow not found',
                statusCode: null,
                retryAfter: null,
                retryPolicy: $retryPolicy
            );
            return false;
        }

        try {
            $flowRun = $this->flowRunnerService->run(
                flow: $flow,
                input: ($message->getObject()['payload'] ?? []),
                triggerSource: 'event'
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                    'Failed to run flow action for event message: '.$e->getMessage(),
                    [
                        'exception' => $e,
                        'message'   => $message->jsonSerialize(),
                    ]
                    );
            $this->recordFailure(
                message: $message,
                error: $e->getMessage(),
                statusCode: null,
                retryAfter: null,
                retryPolicy: $retryPolicy
            );
            return false;
        }//end try

        $status = (string) ($flowRun->getObject()['status'] ?? '');
        if ($status === 'failed' || $status === 'stopped') {
            $this->recordFailure(
                message: $message,
                error: 'flow run ended with status '.$status,
                statusCode: null,
                retryAfter: null,
                retryPolicy: $retryPolicy
            );
            return false;
        }

        $this->recordDeliverySuccess(message: $message);
        return true;

    }//end dispatchFlowAction()

    /**
     * Dispatch an `action.kind = 'notificaties'` message: resolve the target
     * Source and the matched `event`, build the ZGW notification body via
     * {@see NotificatiesSubscriberService::buildNotificationBody()}, and POST
     * it via {@see CallService::call()} in place of an HTTP webhook —
     * inheriting REQ-002's exact success/failure/retry/backoff/dead-letter
     * bookkeeping, the same way {@see dispatchSynchronizationAction} and
     * {@see dispatchJobAction} do. `deliverMessage`/`webhook-signing` are NOT
     * invoked for this kind (no direct webhook POST — the notification body
     * IS the request).
     *
     * @param ObjectEntity $message          The message being dispatched.
     * @param array        $subscriptionData The owning subscription's OR object array.
     * @param array        $action           The resolved `action` block (`{kind, sourceId, kanaal,
     *                                       hoofdObjectField?, resourceField?, actieMap?, kenmerken?}`).
     *
     * @return boolean True when the notification was published successfully.
     *
     * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-subscriptions-action-dispatch-must-support-a-notificaties-kind-for-zgw-notificaties-api-publishing-req-010
     * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-a-publish-action-missing-kanaal-is-a-configuration-error-not-a-transient-failure-req-006
     */
    private function dispatchNotificatiesAction(ObjectEntity $message, array $subscriptionData, array $action): bool
    {
        $retryPolicy = $this->resolveRetryPolicy(subscriptionData: $subscriptionData);
        $kanaal      = (string) ($action['kanaal'] ?? '');

        if ($kanaal === '') {
            // Configuration error (REQ-006), NOT a transient failure — fails
            // once without entering the retry loop, mirroring the
            // "unrecognised action.kind" treatment above.
            $this->recordConfigurationError(
                message: $message,
                error: "action.kanaal is required for action.kind='notificaties'"
            );
            return false;
        }

        $source = $this->findNotificatiesSource(sourceId: (string) ($action['sourceId'] ?? ''));
        if ($source === null) {
            // Unresolvable sourceId is retryable (the Source may be created
            // or corrected later) — same treatment as an unresolvable
            // synchronizationId/jobId above.
            $this->recordFailure(
                message: $message,
                error: 'source not found',
                statusCode: null,
                retryAfter: null,
                retryPolicy: $retryPolicy
            );
            return false;
        }

        $messageData = $message->getObject();
        $event       = $this->findNotificatiesEvent(eventId: ($messageData['eventId'] ?? null));
        if ($event === null) {
            $this->recordFailure(
                message: $message,
                error: 'event not found',
                statusCode: null,
                retryAfter: null,
                retryPolicy: $retryPolicy
            );
            return false;
        }

        $body = NotificatiesSubscriberService::buildNotificationBody(event: $event, action: $action);

        try {
            $callLog = $this->callService->call(
                source: $source,
                endpoint: '/notificaties',
                method: 'POST',
                config: ['json' => $body]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                    'Failed to publish notificaties action for event message: '.$e->getMessage(),
                    [
                        'exception' => $e,
                        'message'   => $message->jsonSerialize(),
                    ]
                    );
            $this->recordFailure(
                message: $message,
                error: $e->getMessage(),
                statusCode: null,
                retryAfter: null,
                retryPolicy: $retryPolicy
            );
            return false;
        }//end try

        $callLogData = $callLog->getObject();
        $statusCode  = (int) ($callLogData['statusCode'] ?? 0);

        if ($statusCode >= 200 && $statusCode < 300) {
            $this->recordDeliverySuccess(message: $message);
            return true;
        }

        $this->recordFailure(
            message: $message,
            error: 'Notificaties publish failed with status code: '.$statusCode,
            statusCode: $statusCode,
            retryAfter: null,
            retryPolicy: $retryPolicy
        );
        return false;

    }//end dispatchNotificatiesAction()

    /**
     * Resolve a `notificaties` action's target `Source`, tolerating a
     * missing/invalid/unresolvable id.
     *
     * @param string $sourceId The `action.sourceId`.
     *
     * @return ObjectEntity|null The resolved Source, or null when not found.
     *
     * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-subscriptions-action-dispatch-must-support-a-notificaties-kind-for-zgw-notificaties-api-publishing-req-010
     */
    private function findNotificatiesSource(string $sourceId): ?ObjectEntity
    {
        if ($sourceId === '') {
            return null;
        }

        try {
            // System context (ocon#147): the resolved Source is handed straight to
            // CallService::call(), which authenticates from the entity it is GIVEN
            // ($source->getObject()) and never re-reads it. A rendered read strips
            // the `writeOnly` credential fields (apikey/secret/password/jwt/
            // authenticationConfig), so a rendered Source would publish to the
            // notificaties API with NO credentials at all.
            return $this->objectService->find(
                id: $sourceId,
                register: 'openconnector',
                schema: 'source',
                _rbac: false,
                _multitenancy: false
            );
        } catch (\Throwable $e) {
            return null;
        }

    }//end findNotificatiesSource()

    /**
     * Resolve the `event` OR-object a message was created for, tolerating a
     * missing/invalid/unresolvable id.
     *
     * @param string|null $eventId The message's `eventId`.
     *
     * @return ObjectEntity|null The resolved event, or null when not found.
     *
     * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-subscriptions-action-dispatch-must-support-a-notificaties-kind-for-zgw-notificaties-api-publishing-req-010
     */
    private function findNotificatiesEvent(?string $eventId): ?ObjectEntity
    {
        if ($eventId === null) {
            return null;
        }

        try {
            return $this->objectService->find(id: $eventId, register: 'openconnector', schema: 'event');
        } catch (\Throwable $e) {
            return null;
        }

    }//end findNotificatiesEvent()

    /**
     * Dispatch an `action.kind = 'mapping'` message: resolve the target
     * `Mapping` + `Source`, fetch the full Forms submission independently
     * (the merged trigger's event payload alone does not carry `answers` —
     * design.md Decision 2, discovery.md Finding 3), resolve every answer
     * reference via {@see FormsAnswerResolver} (REQ-003), run
     * `MappingService::executeMapping()`, and call `CallService::call()`
     * against the resolved `Source`/`action.endpoint`. Success/failure
     * bookkeeping is identical to the sibling `synchronization`/`job`/
     * `notificaties` branches, so retry/backoff/dead-letter/replay all apply
     * unchanged. Never invokes `deliverMessage`/webhook-signing.
     *
     * @param ObjectEntity $message          The message being dispatched.
     * @param array        $subscriptionData The owning subscription's OR object array.
     * @param array        $action           The resolved `action` block
     *                                       (`{kind, mappingId, sourceId, endpoint, method?}`).
     *
     * @return boolean True when the mapped call succeeded.
     *
     * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-outbound-submission-to-call-mapping-dispatch-req-004
     * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-subscriptions-action-dispatch-may-additionally-support-a-mapping-kind-req-012
     */
    private function dispatchMappingAction(ObjectEntity $message, array $subscriptionData, array $action): bool
    {
        $retryPolicy = $this->resolveRetryPolicy(subscriptionData: $subscriptionData);

        $mappingId = (string) ($action['mappingId'] ?? '');
        $sourceId  = (string) ($action['sourceId'] ?? '');

        $mapping = null;
        if ($mappingId !== '') {
            $mapping = $this->findMappingActionObject(id: $mappingId, schema: 'mapping');
        }

        $source = null;
        if ($sourceId !== '') {
            $source = $this->findMappingActionObject(id: $sourceId, schema: 'source');
        }

        if ($mapping === null || $source === null) {
            // Unresolvable mappingId/sourceId is retryable — the Mapping or
            // Source may be created/corrected later — same treatment as an
            // unresolvable synchronizationId/jobId/notificaties sourceId
            // above (design.md Decision 4).
            $this->recordFailure(
                message: $message,
                error: 'mapping or source not found',
                statusCode: null,
                retryAfter: null,
                retryPolicy: $retryPolicy
            );
            return false;
        }

        if ($this->formsSyncAdapter === null || $this->formsAnswerResolver === null || $this->mappingService === null) {
            // No Forms adapter/answer-resolver/mapping-service wired at all —
            // this dispatch kind cannot run in this deployment. A
            // configuration problem, not a transient one.
            $this->recordConfigurationError(
                message: $message,
                error: "The Nextcloud Forms adapter is not available for action.kind='mapping' dispatch."
            );
            return false;
        }

        try {
            $this->formsSyncAdapter->assertEnabled();
        } catch (FormsFeatureDisabledException $exception) {
            // REQ-001 scenario 3: Forms disabled is a config error (retryCount
            // stays 0), never attempting a Forms HTTP call.
            $this->recordConfigurationError(message: $message, error: $exception->getMessage());
            return false;
        }

        $messageData = $message->getObject();
        $event       = $this->findNotificatiesEvent(eventId: ($messageData['eventId'] ?? null));
        if ($event === null) {
            $this->recordFailure(
                message: $message,
                error: 'event not found',
                statusCode: null,
                retryAfter: null,
                retryPolicy: $retryPolicy
            );
            return false;
        }

        $eventData    = (array) (($event->getObject()['data'] ?? []));
        $formId       = (int) ($eventData['formId'] ?? 0);
        $submissionId = (int) ($eventData['submission']['id'] ?? 0);

        if ($formId <= 0 || $submissionId <= 0) {
            // Design.md Decision 4's constraint: a subscription with
            // action.kind='mapping' matching a non-Forms event type has no
            // formId/submission.id — a configuration error naming the gap,
            // not a crash and not retryable (this subscription will never
            // succeed against this event type without being reconfigured).
            $this->recordConfigurationError(
                message: $message,
                error: "action.kind='mapping' requires event.data.formId and event.data.submission.id (non-Forms event?)"
            );
            return false;
        }

        try {
            $submission = $this->formsSyncAdapter->fetchSubmission(source: $source, formId: $formId, submissionId: $submissionId);
            $form       = $this->formsSyncAdapter->fetchForm(source: $source, formId: $formId);

            $resolvedAnswers = $this->resolveMappingAnswers(
                questions: $form['questions'],
                answers: $submission['answers']
            );

            $mappedResult = $this->mappingService->executeMapping(mapping: $mapping, input: $resolvedAnswers);

            $callLog = $this->callService->call(
                source: $source,
                endpoint: (string) ($action['endpoint'] ?? ''),
                method: (string) ($action['method'] ?? 'POST'),
                config: ['json' => $mappedResult]
            );
        } catch (\Throwable $exception) {
            // Any resolution/mapping/call failure (including an ambiguous
            // question-text FormsConfigException — REQ-004 scenario 2) is a
            // standard retryable failure: a data-shape problem in a specific
            // submission does not permanently misconfigure the subscription.
            $this->logger->error(
                    'Failed to dispatch mapping action for event message: '.$exception->getMessage(),
                    [
                        'exception' => $exception,
                        'message'   => $message->jsonSerialize(),
                    ]
                    );
            $this->recordFailure(
                message: $message,
                error: $exception->getMessage(),
                statusCode: null,
                retryAfter: null,
                retryPolicy: $retryPolicy
            );
            return false;
        }//end try

        $callLogData = $callLog->getObject();
        $statusCode  = (int) ($callLogData['statusCode'] ?? 0);

        if ($statusCode >= 200 && $statusCode < 300) {
            $this->recordDeliverySuccess(message: $message);
            return true;
        }

        $this->recordFailure(
            message: $message,
            error: 'Mapping action call failed with status code: '.$statusCode,
            statusCode: $statusCode,
            retryAfter: null,
            retryPolicy: $retryPolicy
        );
        return false;

    }//end dispatchMappingAction()

    /**
     * Resolve every answer in a form's `questions` list, keyed BOTH by
     * numeric question id (string-cast) and by question TEXT, so a `Mapping`
     * can reference either style. Text keys are resolved via
     * {@see FormsAnswerResolver}'s own text-resolution path, which throws
     * `FormsConfigException` when two-or-more questions share that exact
     * text (REQ-003) — surfaced to the caller as a standard dispatch failure
     * (REQ-004 scenario 2), never silently picking one.
     *
     * @param array $questions The form's fetched `questions` (FormsClientInterface::getForm()).
     * @param array $answers   The submission's fetched `answers` (FormsClientInterface::getSubmission()).
     *
     * @return array<string, mixed> `{"<id>": value, "<text>": value, ...}`, the `$input`
     *         passed to `MappingService::executeMapping()`.
     *
     * @throws \OCA\OpenConnector\Exception\FormsConfigException When a question text is ambiguous.
     *
     * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-answer-by-question-resolution-and-type-coercion-req-003
     * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-outbound-submission-to-call-mapping-dispatch-req-004
     */
    private function resolveMappingAnswers(array $questions, array $answers): array
    {
        $resolved = [];

        foreach ($questions as $question) {
            if (is_array($question) === false) {
                continue;
            }

            $id = (int) ($question['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $resolved[(string) $id] = $this->formsAnswerResolver->resolve(
                questions: $questions,
                answers: $answers,
                questionRef: $id
            );
        }

        $seenTexts = [];
        foreach ($questions as $question) {
            if (is_array($question) === false) {
                continue;
            }

            $text = (string) ($question['text'] ?? '');
            if ($text === '' || in_array($text, $seenTexts, true) === true) {
                continue;
            }

            $seenTexts[]     = $text;
            $resolved[$text] = $this->formsAnswerResolver->resolve(
                questions: $questions,
                answers: $answers,
                questionRef: $text
            );
        }

        return $resolved;

    }//end resolveMappingAnswers()

    /**
     * Resolve a `mapping`/`source` action reference by id, tolerating a
     * missing/invalid/unresolvable id — the generic counterpart to
     * {@see findNotificatiesSource} for `action.kind = 'mapping'`'s two id
     * references.
     *
     * @param string $id     The OR object id/uuid to resolve.
     * @param string $schema The OpenRegister schema slug (`'mapping'` or `'source'`).
     *
     * @return ObjectEntity|null The resolved object, or null when not found.
     *
     * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-outbound-submission-to-call-mapping-dispatch-req-004
     */
    private function findMappingActionObject(string $id, string $schema): ?ObjectEntity
    {
        if ($id === '') {
            return null;
        }

        try {
            // System context (ocon#147): with `$schema = 'source'` the resolved
            // object is handed to FormsOcsClient and CallService::call(), both of
            // which authenticate from the entity they are given. See
            // findNotificatiesSource() — a rendered read would strip the Source's
            // `writeOnly` credential fields and the outbound call would go out
            // unauthenticated.
            return $this->objectService->find(
                id: $id,
                register: 'openconnector',
                schema: $schema,
                _rbac: false,
                _multitenancy: false
            );
        } catch (\Throwable $exception) {
            return null;
        }

    }//end findMappingActionObject()

    /**
     * Persist a non-webhook delivery success (`action.kind = 'synchronization'`
     * or `'job'`) using the same success-path fields {@see deliverMessage} sets
     * on a 2xx HTTP response, minus the HTTP-specific `deliveryResponse` block.
     *
     * @param ObjectEntity $message The message that was successfully dispatched.
     *
     * @return void
     *
     * @throws \OCP\DB\Exception On persistence failure.
     *
     * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-subscriptions-action-dispatch-must-support-webhook-synchronization-or-job-kinds-req-008
     */
    private function recordDeliverySuccess(ObjectEntity $message): void
    {
        $messageData   = $message->getObject();
        $now           = (new DateTime())->format('c');
        $priorAttempts = (array) ($messageData['attempts'] ?? []);

        $messageData['status']      = 'delivered';
        $messageData['deliveredAt'] = $now;
        $messageData['lastAttempt'] = $now;
        $messageData['nextAttempt'] = null;
        $messageData['attempts']    = $this->appendAttempt(
            attempts: $priorAttempts,
            at: $now,
            statusCode: null,
            error: null
        );

        $this->objectService->saveObject(
            object: $messageData,
            register: 'openconnector',
            schema: 'event_message',
            uuid: $message->getUuid()
        );

    }//end recordDeliverySuccess()

    /**
     * Persist a configuration-error failure (e.g. an unrecognised
     * `action.kind`): `status='failed'` with a descriptive error, WITHOUT
     * incrementing `retryCount` — a config error will not self-resolve on
     * retry, so it must not count toward the subscription's retry budget,
     * yet still surfaces in the dead-letter view for an operator to fix.
     *
     * @param ObjectEntity $message The message that failed configuration resolution.
     * @param string       $error   The human-readable failure reason.
     *
     * @return void
     *
     * @throws \OCP\DB\Exception On persistence failure.
     *
     * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-subscriptions-action-dispatch-must-support-webhook-synchronization-or-job-kinds-req-008
     */
    private function recordConfigurationError(ObjectEntity $message, string $error): void
    {
        $messageData   = $message->getObject();
        $now           = (new DateTime())->format('c');
        $priorAttempts = (array) ($messageData['attempts'] ?? []);

        $messageData['status']      = 'failed';
        $messageData['error']       = $error;
        $messageData['lastAttempt'] = $now;
        $messageData['nextAttempt'] = null;
        $messageData['attempts']    = $this->appendAttempt(
            attempts: $priorAttempts,
            at: $now,
            statusCode: null,
            error: $error
        );

        $this->objectService->saveObject(
            object: $messageData,
            register: 'openconnector',
            schema: 'event_message',
            uuid: $message->getUuid()
        );

    }//end recordConfigurationError()

    /**
     * Replay a dead-lettered message back into the delivery machine.
     *
     * Resets a `failed`/`abandoned` message to `pending`, clears its retry
     * counter, schedules an immediate next attempt, preserves the existing
     * `attempts[]` history, stamps the acting operator, and triggers one
     * immediate delivery attempt using the message's resolved `action.kind`
     * (webhook/synchronization/job — REQ-008). The message then re-enters the
     * standard retry/backoff/abandon machine.
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
     * @spec openspec/specs/dead-letter-replay/spec.md#requirement-audited-replay-returning-the-message-to-the-delivery-machine-req-dlr-003
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

        // Re-enter the delivery machine immediately, through the SAME action
        // kind that originally ran/failed (dead-letter-replay REQ-DLR-003) —
        // deliverMessage for webhook (unchanged), synchronize()/executeJob()
        // for synchronization/job.
        $this->attemptDelivery(message: $saved);

        return $this->objectService->find(
            id: $message->getUuid(),
            register: 'openconnector',
            schema: 'event_message'
        );

    }//end replayMessage()

    /**
     * Dry-run preview for an `execution-trace` replay of a `webhook`-kind
     * message: resolve the outbound request (URL, method, headers) that
     * WOULD be dispatched, WITHOUT invoking the network call. Used only by
     * `ExecutionTraceService::replay()` — never by the normal delivery
     * machine, which always dispatches for real (`deliverMessage()`).
     *
     * @param ObjectEntity $message The message that would be (re)delivered.
     *
     * @return array{url: string, method: string, headers: array} The resolved, redacted request.
     *
     * @spec openspec/specs/execution-trace/spec.md#requirement-dry-run-replay-performs-no-writes-req-005
     */
    public function previewWebhookDelivery(ObjectEntity $message): array
    {
        $messageData    = $message->getObject();
        $subscriptionId = ($messageData['subscriptionId'] ?? null);

        $headers = ['Content-Type' => 'application/cloudevents+json'];
        $sink    = '';

        if ($subscriptionId !== null) {
            $subscription = $this->objectService->find(
                id: $subscriptionId,
                register: 'openconnector',
                schema: 'event_subscription'
            );

            if ($subscription !== null) {
                $subscriptionData = $subscription->getObject();
                $sink    = (string) ($subscriptionData['sink'] ?? '');
                $headers = [
                    'Content-Type' => 'application/cloudevents+json',
                    ...($subscriptionData['protocolSettings']['headers'] ?? []),
                ];

                $signingSecret = ($subscriptionData['protocolSettings']['signingSecret'] ?? null);
                if ($signingSecret !== null && $signingSecret !== '') {
                    // Signature presence is meaningful for a preview even
                    // though the value itself is never resolvable without
                    // dispatching for real; redacted below regardless.
                    $headers['X-OpenConnector-Signature'] = '***REDACTED***';
                    $headers['X-OpenConnector-Event-Id']  = $message->getUuid();
                }
            }
        }//end if

        $sensitiveFieldRegistry = new SensitiveFieldRegistry();

        return [
            'url'     => $sink,
            'method'  => 'POST',
            'headers' => $sensitiveFieldRegistry->redactArray(data: $headers),
        ];

    }//end previewWebhookDelivery()

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
     * The `$maxRetries` parameter is a sweep-level pre-filter only (a coarse,
     * global safety cap) — the terminal `abandoned` decision for each message
     * is made by {@see recordFailure} using the resolved subscription's own
     * `retryPolicy.maxRetries`, so a message whose subscription sets
     * `maxRetries=3` correctly stops being swept once it reaches `abandoned`
     * even though this sweep runs with the default `$maxRetries=5` (REQ-002).
     *
     * @param integer $maxRetries Maximum number of retry attempts (sweep-level pre-filter).
     *
     * @return integer Number of successfully delivered messages.
     *
     * @spec openspec/changes/openconnector-event-retry-hardening/tasks.md#task-3
     * @spec openspec/specs/events-cloudevents/spec.md#requirement-push-delivery-with-status-tracking-and-retry-sweep-req-002
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

            // Action-aware: deliverMessage for webhook (unchanged), or the
            // REQ-008 synchronization/job handler for non-webhook messages.
            if ($this->attemptDelivery(message: $message) === true) {
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
     * Normalize a Nextcloud-native core event (files/calendar/Tables/Forms)
     * into the same CloudEvents `event` OR-object shape
     * {@see handleObjectCreated}/`Updated`/`Deleted` already write, then fan
     * it out via {@see processEvent} unchanged.
     *
     * Called by the `nextcloud-event-triggers` capability's `IEventListener`
     * classes (`NextcloudFileEventListener`, `NextcloudFileTagEventListener`,
     * `NextcloudCalendarEventListener`, `NextcloudTablesEventListener`,
     * `NextcloudFormsEventListener`) — never by the pre-existing OR-object
     * pipeline, so this is purely additive.
     *
     * @param string $type    The CloudEvents `type`, e.g. `com.nextcloud.files.node.created`.
     * @param array  $payload The normalized event fields: `source` (e.g. `/nextcloud/files`),
     *                        `subject` (string|null — the node/object id), `data` (array — the
     *                        normalized payload), and optional `userId` (the acting NC user, or
     *                        null for system-produced events).
     *
     * @return ObjectEntity[] The created CloudEvent messages.
     *
     * @throws Exception         On event processing failure.
     * @throws \OCP\DB\Exception On persistence failure.
     *
     * @spec openspec/specs/nextcloud-event-triggers/spec.md#requirement-file-events-must-be-normalized-to-cloudevents-req-001
     */
    public function handleNextcloudEvent(string $type, array $payload): array
    {
        $event = $this->objectService->saveObject(
            object: [
                'source'  => (string) ($payload['source'] ?? ''),
                'type'    => $type,
                'time'    => (new DateTime())->format('c'),
                'subject' => ($payload['subject'] ?? null),
                'data'    => ($payload['data'] ?? []),
                'userId'  => ($payload['userId'] ?? null),
            ],
            register: 'openconnector',
            schema: 'event'
        );

        return $this->processEvent(event: $event);

    }//end handleNextcloudEvent()

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

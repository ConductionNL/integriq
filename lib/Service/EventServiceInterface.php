<?php

/**
 * Contract of the EventService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use Exception;
use OCA\Integriq\Event\DeliveryRequestedEvent;
use OCA\Integriq\Exception\InvalidMessageStateException;
use OCA\Integriq\Service\Helper\ExecutionTraceContext;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Public operations of {@see EventService} that other components call.
 */
interface EventServiceInterface {
	/**
	 * Default maximum number of delivery attempts before a message reaches
	 * terminal `abandoned`, used when a subscription declares no
	 * `retryPolicy.maxRetries` override.
	 *
	 * @var integer
	 *
	 * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-subscription-s-retry-backoff-policy-must-be-independently-configurable-req-009
	 */
	public const DEFAULT_MAX_RETRIES = 5;

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
	 * Cheap existence check: is there at least one active `event_subscription`
	 * anywhere on this instance?
	 *
	 * Used as a firehose gate by {@see \OCA\Integriq\EventListener\CloudEventListener}
	 * so that an install with zero configured subscriptions (the common case —
	 * outbound webhooks are opt-in) pays no persistence cost at all for OR
	 * object mutations fleet-wide: no `event` record is written, no matching
	 * logic runs. An install that HAS at least one active subscription falls
	 * through to the full, already-specified {@see processEvent} contract
	 * (REQ-001) unchanged.
	 *
	 * @return boolean True when at least one `event_subscription` with
	 *                 `status = 'active'` exists.
	 *
	 * @spec openspec/changes/outbound-webhooks-activation/tasks.md#task-3
	 */
	public function hasActiveSubscriptions(): bool;

	/**
	 * Resolve the numeric schema ids of this app's own CloudEvent storage
	 * (`event`, `event_message`) within the `openconnector` register.
	 *
	 * {@see \OCA\Integriq\EventListener\CloudEventListener} needs these
	 * to recognise its own writes and not re-forward them. It cannot compare
	 * slugs, because `ObjectEntity::getSchema()` returns a numeric id.
	 *
	 * Resolved by reading one object per schema rather than through the schema
	 * mapper, so this stays inside the ObjectService boundary (ADR-022) — the
	 * ids are a property of whatever objects the register actually holds.
	 * A schema with zero objects contributes no id, which is correct: with no
	 * rows there is nothing to recurse on yet, and the id appears as soon as
	 * the first one is written.
	 *
	 * @return array<int, string> Schema ids as strings; empty when unresolvable.
	 *
	 * @spec openspec/changes/stop-cloudevent-recursion/specs/events/spec.md
	 */
	public function getSelfSchemaIds(): array;

	/**
	 * Process a new event and create messages for all matching subscriptions.
	 *
	 * @param ObjectEntity                  $event         The event ObjectEntity to process.
	 * @param array<int, ObjectEntity>|null $subscriptions The active subscriptions when the caller already
	 *                                                     holds them; null fetches them.
	 *
	 * @return array<ObjectEntity> Array of created message ObjectEntities.
	 *
	 * @throws Exception On failure to process the event.
	 *
	 * @spec openspec/specs/events-cloudevents/spec.md
	 * @spec openspec/specs/events-cloudevents/spec.md#requirement-cloudevent-fan-out-to-matching-subscriptions-req-001
	 */
	public function processEvent(ObjectEntity $event, ?array $subscriptions = null): array;

	/**
	 * Fan out queued CloudEvents, fetching the active subscriptions once.
	 *
	 * Runs on the cron worker ({@see ProcessEventJob}), never in the request
	 * that wrote the object. An event that is gone by now (purged, or deleted
	 * by an admin) is skipped.
	 *
	 * @param array<int, string> $eventIds Uuids of stored `event` objects.
	 *
	 * @return integer The number of `event_message` objects created.
	 *
	 * @spec openspec/changes/stop-cloudevent-recursion/specs/events/spec.md#requirement-event-fan-out-shall-not-run-inside-the-originating-write-request
	 * @spec openspec/changes/stop-cloudevent-recursion/specs/events/spec.md#requirement-active-subscriptions-shall-be-resolved-once-per-processing-run
	 */
	public function processQueuedEvents(array $eventIds): int;

	/**
	 * Attempt to deliver a message.
	 *
	 * @param ObjectEntity $message The pending message to deliver.
	 * @param ExecutionTraceContext|null $trace The active execution trace context. `deliverMessage()` dispatches
	 *                                          via `IClientService` directly (not `CallService`), so — unlike
	 *                                          every other outbound-call path — this method redacts and
	 *                                          appends its own `call` step (execution-trace REQ-002/REQ-003).
	 *
	 * @return boolean True when delivered successfully.
	 *
	 * @spec openspec/changes/openconnector-webhook-signing/tasks.md#task-2
	 * @spec openspec/changes/openconnector-event-retry-hardening/tasks.md#task-2
	 * @spec openspec/specs/events-cloudevents/spec.md#requirement-push-delivery-with-status-tracking-and-retry-sweep-req-002
	 */
	public function deliverMessage(ObjectEntity $message, ?ExecutionTraceContext $trace = null): bool;

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
	 * @param string $id The message UUID.
	 * @param string $actorUid The acting operator's user id.
	 *
	 * @return ObjectEntity The updated message.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When the message does not exist.
	 * @throws InvalidMessageStateException When the message is not in a replayable state.
	 * @throws \OCP\DB\Exception On persistence failure.
	 *
	 * @spec openspec/changes/openconnector-dead-letter-replay/tasks.md#task-2
	 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-audited-replay-returning-the-message-to-the-delivery-machine-req-dlr-003
	 */
	public function replayMessage(string $id, string $actorUid): ObjectEntity;

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
	public function previewWebhookDelivery(ObjectEntity $message): array;

	/**
	 * Discard a dead-lettered message into the terminal `discarded` state.
	 *
	 * Marks a `failed`/`abandoned` message `discarded` with an operator audit
	 * stamp. Discarded messages are never swept and never hard-deleted; they
	 * remain queryable for audit.
	 *
	 * @param string $id The message UUID.
	 * @param string $actorUid The acting operator's user id.
	 *
	 * @return ObjectEntity The updated message.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When the message does not exist.
	 * @throws InvalidMessageStateException When the message is not in a discardable state.
	 * @throws \OCP\DB\Exception On persistence failure.
	 *
	 * @spec openspec/changes/openconnector-dead-letter-replay/tasks.md#task-2
	 */
	public function discardMessage(string $id, string $actorUid): ObjectEntity;

	/**
	 * Process pending message retries.
	 *
	 * `$maxRetries` is a FLOOR, not a ceiling. The terminal `abandoned` decision
	 * belongs to {@see recordFailure}, which uses the subscription's own
	 * `retryPolicy.maxRetries`; this sweep takes whichever of the two is higher
	 * so it can never stop retrying a message its own policy still considers
	 * live.
	 *
	 * The previous behaviour treated it as a hard cap, which was right for a
	 * subscription setting `maxRetries=3` (recordFailure abandons first, at 3)
	 * and wrong for one setting `maxRetries=10`: at 5 this sweep stopped
	 * retrying, while recordFailure never abandoned because 10 was not reached.
	 * Such a message sat in `failed` permanently — never retried, never
	 * dead-lettered — and was re-loaded and re-skipped on every pass, so the
	 * sweep's cost grew with the number of dead messages while achieving
	 * nothing. A message that genuinely exhausts its ceiling here is now
	 * abandoned rather than skipped.
	 *
	 * @param integer $maxRetries Lower bound on the per-message retry ceiling.
	 *
	 * @return integer Number of successfully delivered messages.
	 *
	 * @spec openspec/changes/openconnector-event-retry-hardening/tasks.md#task-3
	 * @spec openspec/specs/events-cloudevents/spec.md#requirement-push-delivery-with-status-tracking-and-retry-sweep-req-002
	 */
	public function processRetries(int $maxRetries = self::DEFAULT_MAX_RETRIES): int;

	/**
	 * Get events for a pull-based subscription.
	 *
	 * @param ObjectEntity $subscription The owning subscription.
	 * @param integer|null $limit Maximum number of messages to return.
	 * @param string|null $cursor Pagination cursor from the previous call.
	 *
	 * @return array{messages: ObjectEntity[], cursor: string|null}
	 *
	 * @spec openspec/specs/events-cloudevents/spec.md
	 */
	public function pullEvents(ObjectEntity $subscription, ?int $limit = 100, ?string $cursor = null): array;

	/**
	 * Construct and process an arbitrary CloudEvent.
	 *
	 * Generalises the `handleObjectCreated`/`Updated`/`Deleted` shape (build a
	 * CloudEvents-shaped `event` OR object, then fan it out via
	 * {@see processEvent}) for connectors that need to emit a domain-specific
	 * event `type` — e.g. `nl.conduction.peppol.delivery.status` — rather than
	 * one of the fixed `com.nextcloud.openregister.object.*` types.
	 *
	 * @param string $type The CloudEvents `type` attribute (e.g. `nl.conduction.peppol.delivery.status`).
	 * @param string $source The CloudEvents `source` attribute (the producing component identity).
	 * @param string|null $subject The CloudEvents `subject` attribute, or null when not applicable.
	 * @param array $data The CloudEvents `data` payload.
	 * @param string|null $userId The Nextcloud user id that produced this event, or null for system-produced.
	 *
	 * @return ObjectEntity[] The created CloudEvent messages.
	 *
	 * @throws Exception On event processing failure.
	 * @throws \OCP\DB\Exception On persistence failure.
	 *
	 * @spec openspec/specs/peppol-access-point-connector/spec.md#requirement-delivery-status-cloudevents-on-every-state-change-req-004
	 */
	public function emitCloudEvent(string $type, string $source, ?string $subject, array $data, ?string $userId = null): array;

	/**
	 * Ingest an ADR-041 cross-app delivery request into the CloudEvents
	 * pipeline.
	 *
	 * Persists a {@see self::DELIVERY_REQUESTED_TYPE} `event` OR object whose
	 * `data.delivery` block carries the request's provenance (sourceApp,
	 * subject, channel, correlationId) and whose `data.payload` carries the
	 * caller-composed delivery payload, then fans it out via
	 * {@see processEvent} so admin-configured `event_subscription`s route it
	 * to a webhook / flow / synchronization / notificaties action with the
	 * pipeline's retry, dead-letter and replay semantics.
	 *
	 * The provenance block is what later gates the terminal
	 * {@see DeliveryConcludedEvent} dispatch in
	 * {@see dispatchDeliveryConcluded} — ordinary CloudEvent traffic carries
	 * no `data.delivery` and never produces one.
	 *
	 * @param DeliveryRequestedEvent $request The typed cross-app delivery request.
	 *
	 * A payload that names a personal `recipient` (a string, or `{address,
	 * channel}`) is asked of the opt-out list first, with the payload's
	 * `category` (default `service`). A refused delivery is stored with the
	 * decision under `data.delivery.optOut` and is not routed; the outbound
	 * log row says why. An allowed one carries the unsubscribe material in
	 * `data.payload.unsubscribe`, and in `data.payload.body` when that is text.
	 *
	 * @return array{event: ObjectEntity, messages: ObjectEntity[], refusal: array{code:string,reason:string}|null} The
	 *         persisted event, its created delivery messages, and the opt-out refusal when there was one.
	 *
	 * @throws Exception On event processing failure.
	 * @throws \OCP\DB\Exception On persistence failure.
	 *
	 * @spec openspec/changes/absorb-dossiq-deliveries/specs/delivery-intake/spec.md
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-every-integriq-sender-asks-the-opt-out-list-before-it-sends-req-ooa-001
	 */
	public function ingestDeliveryRequest(DeliveryRequestedEvent $request): array;

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
	 * @param string $type The CloudEvents `type`, e.g. `com.nextcloud.files.node.created`.
	 * @param array $payload The normalized event fields: `source` (e.g. `/nextcloud/files`),
	 *                       `subject` (string|null — the node/object id), `data` (array — the
	 *                       normalized payload), and optional `userId` (the acting NC user, or
	 *                       null for system-produced events).
	 *
	 * @return ObjectEntity[] The created CloudEvent messages.
	 *
	 * @throws Exception On event processing failure.
	 * @throws \OCP\DB\Exception On persistence failure.
	 *
	 * @spec openspec/specs/nextcloud-event-triggers/spec.md#requirement-file-events-must-be-normalized-to-cloudevents-req-001
	 */
	public function handleNextcloudEvent(string $type, array $payload): array;

	/**
	 * Handle object creation by creating and processing a CloudEvent.
	 *
	 * @param ObjectEntity $object The created object.
	 *
	 * @return ObjectEntity[] Empty: the fan-out is queued ({@see queueFanOut}).
	 *
	 * @throws Exception On event processing failure.
	 * @throws \OCP\DB\Exception On persistence failure.
	 *
	 * @spec openspec/specs/events-cloudevents/spec.md
	 * @spec openspec/changes/stop-cloudevent-recursion/specs/events/spec.md#requirement-event-fan-out-shall-not-run-inside-the-originating-write-request
	 */
	public function handleObjectCreated(ObjectEntity $object): array;

	/**
	 * Handle object update by creating and processing a CloudEvent.
	 *
	 * @param ObjectEntity $oldObject The previous state of the object.
	 * @param ObjectEntity $newObject The new state of the object.
	 *
	 * @return ObjectEntity[] Empty: the fan-out is queued ({@see queueFanOut}).
	 *
	 * @throws Exception On event processing failure.
	 * @throws \OCP\DB\Exception On persistence failure.
	 *
	 * @spec openspec/specs/events-cloudevents/spec.md
	 * @spec openspec/changes/stop-cloudevent-recursion/specs/events/spec.md#requirement-event-fan-out-shall-not-run-inside-the-originating-write-request
	 */
	public function handleObjectUpdated(ObjectEntity $oldObject, ObjectEntity $newObject): array;

	/**
	 * Handle object deletion by creating and processing a CloudEvent.
	 *
	 * @param ObjectEntity $object The deleted object.
	 *
	 * @return ObjectEntity[] Empty: the fan-out is queued ({@see queueFanOut}).
	 *
	 * @throws Exception On event processing failure.
	 * @throws \OCP\DB\Exception On persistence failure.
	 *
	 * @spec openspec/specs/events-cloudevents/spec.md
	 * @spec openspec/changes/stop-cloudevent-recursion/specs/events/spec.md#requirement-event-fan-out-shall-not-run-inside-the-originating-write-request
	 */
	public function handleObjectDeleted(ObjectEntity $object): array;
}

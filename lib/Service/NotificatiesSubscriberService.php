<?php

/**
 * OpenConnector NotificatiesSubscriberService.
 *
 * ZGW Notificaties API (Logius/VNG "API Notificatiestandaard voor ZGW APIs")
 * subscriber-side abonnement lifecycle + inbound notification normalization.
 * Publisher-side wire-shape mapping ({@see buildNotificationBody()}) is a
 * pure static helper so {@see EventService::dispatchNotificatiesAction()} can
 * call it without a constructor dependency on this class (this class already
 * depends on EventService for inbound normalization — a two-way constructor
 * dependency would be circular).
 *
 * @category Service
 * @package  OCA\OpenConnector\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/specs/notificaties-api-connector/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service;

use Adbar\Dot;
use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Abonnement CRUD against a remote Notificaties API + inbound notification
 * normalization into the existing CloudEvents pipe.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) -- single owner of the ZGW-domain-specific
 * abonnement lifecycle (create/update/delete against the remote API, per-abonnement consumer
 * provisioning/cascade-delete, inbound normalization, and the publish-side wire-shape mapping);
 * each concern is already extracted into its own small private helper (see
 * callRemoteAbonnement/settlementError/provisionConsumer/cascadeDeleteConsumer/
 * buildNotificationBody) — splitting further would fragment one capability's single OR-object
 * owner (ADR-008) across multiple classes without reducing real complexity.
 *
 * @spec openspec/specs/notificaties-api-connector/spec.md
 */
class NotificatiesSubscriberService {
	/**
	 * Abonnement lifecycle statuses (design.md Decision 5).
	 *
	 * @var string
	 */
	private const STATUS_PENDING = 'pending';

	private const STATUS_ACTIVE = 'active';

	private const STATUS_ERROR = 'error';

	private const STATUS_DELETED = 'deleted';

	/**
	 * Default `event.type` suffix -> ZGW `actie` mapping, matching the
	 * `com.nextcloud.openregister.object.*` convention (REQ-005).
	 *
	 * @var array<string,string>
	 */
	private const DEFAULT_ACTIE_MAP = [
		'created' => 'create',
		'updated' => 'update',
		'deleted' => 'destroy',
	];

	/**
	 * Constructor.
	 *
	 * @param ORObjectService $objectService The OR ObjectService for data access.
	 * @param CallService $callService Outbound HTTP calls against a `Source` (abonnement CRUD, no new client).
	 * @param EventService $eventService Normalizes inbound notifications into the CloudEvents pipe (REQ-003/REQ-011).
	 * @param WebhookSignatureService $signatureService Reused for its secret-generation algorithm only (Decision 2 — no `whsec_` prefix).
	 * @param IURLGenerator $urlGenerator Builds this app's absolute callback URL per abonnement.
	 * @param LoggerInterface $logger Logger for non-fatal diagnostics (cascade-delete failures, etc.).
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
		private readonly CallService $callService,
		private readonly EventService $eventService,
		private readonly WebhookSignatureService $signatureService,
		private readonly IURLGenerator $urlGenerator,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Register a new abonnement against a remote Notificaties API.
	 *
	 * Persists a `pending` record first (so a UUID exists for the callback
	 * URL), provisions a companion `consumer` (Decision 2), then calls the
	 * remote API. Settles to `active`/`error` — never left `pending`.
	 *
	 * @param array $config `{name, sourceId, kanalen: [{naam, filters}], authHeaderName?, authScheme?}`.
	 *
	 * @return ObjectEntity The persisted `notificaties_abonnement` record.
	 *
	 * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-abonnement-registration-update-and-deletion-against-the-remote-api-req-001
	 * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-abonnement-deletion-cascades-its-companion-consumer-req-004
	 * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-abonnement-lifecycle-status-is-observable-req-007
	 */
	public function createAbonnement(array $config): ObjectEntity {
		$sourceId = (string)($config['sourceId'] ?? '');
		$kanalen = (array)($config['kanalen'] ?? []);

		// Phase 1: persist the `pending` record first — the callback URL
		// needs a UUID to exist (REQ-001, REQ-007).
		$abonnement = $this->persistPendingAbonnement(config: $config);
		$abonnementId = $abonnement->getUuid();
		$callbackUrl = $this->urlGenerator->linkToRouteAbsolute(
			'openconnector.notificatiesSubscriber.callback',
			['abonnementId' => $abonnementId]
		);

		// Phase 2: provision the companion consumer (Decision 2) — the
		// secret is sent to the remote API as `auth` and matched on every
		// inbound callback delivery via the reused REQ-CON-001 apiKey path.
		$provisioned = $this->provisionConsumer(kanalen: $kanalen);

		$abonnementData = $abonnement->getObject();
		$abonnementData['consumerId'] = $provisioned['consumerId'];
		$abonnementData['callbackUrl'] = $callbackUrl;

		// Phase 3: register with the remote Notificaties API.
		$source = $this->resolveSource(sourceId: $sourceId);
		if ($source === null) {
			$abonnementData['status'] = self::STATUS_ERROR;
			$abonnementData['lastError'] = 'Source not found: ' . $sourceId;

			return $this->persistAbonnement(data: $abonnementData, id: $abonnementId);
		}

		$result = $this->registerAbonnementWithRemote(
			source: $source,
			callbackUrl: $callbackUrl,
			secret: $provisioned['secret'],
			kanalen: $kanalen
		);

		$abonnementData['status'] = self::STATUS_ERROR;
		$abonnementData['lastError'] = $result['error'];
		$abonnementData['url'] = $result['url'];
		if ($result['error'] === null) {
			$abonnementData['status'] = self::STATUS_ACTIVE;
			$abonnementData['lastError'] = null;
		}

		return $this->persistAbonnement(data: $abonnementData, id: $abonnementId);
	}//end createAbonnement()

	/**
	 * Update an existing abonnement's kanalen/filters against the remote API.
	 *
	 * @param string $id The abonnement UUID.
	 * @param array $config Any of `{name, kanalen, authHeaderName, authScheme}`.
	 *
	 * @return ObjectEntity The persisted `notificaties_abonnement` record.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When the abonnement does not exist.
	 *
	 * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-abonnement-registration-update-and-deletion-against-the-remote-api-req-001
	 */
	public function updateAbonnement(string $id, array $config): ObjectEntity {
		$abonnement = $this->objectService->find(id: $id, register: 'openconnector', schema: 'notificaties_abonnement');
		$data = $this->mergeAbonnementConfig(current: $abonnement->getObject(), config: $config);

		$source = $this->resolveSource(sourceId: (string)($data['sourceId'] ?? ''));
		if ($source === null) {
			$data['status'] = self::STATUS_ERROR;
			$data['lastError'] = 'Source not found: ' . ($data['sourceId'] ?? '');

			return $this->persistAbonnement(data: $data, id: $id);
		}

		$endpoint = $this->remoteAbonnementEndpoint(source: $source, remoteUrl: ($data['url'] ?? null));
		$call = $this->callRemoteAbonnement(
			source: $source,
			endpoint: $endpoint,
			method: 'PATCH',
			config: ['json' => ['kanalen' => ($data['kanalen'] ?? [])]],
			context: 'update'
		);

		$data['status'] = self::STATUS_ERROR;
		$data['lastError'] = $this->settlementError(call: $call, verb: 'update');
		if ($data['lastError'] === null) {
			$data['status'] = self::STATUS_ACTIVE;
			$data['lastError'] = null;
		}

		return $this->persistAbonnement(data: $data, id: $id);
	}//end updateAbonnement()

	/**
	 * Merge only the caller-supplied keys from `$config` onto the current
	 * abonnement data (a partial update — keys absent from `$config` are
	 * left untouched).
	 *
	 * @param array $current The abonnement's current OR object array.
	 * @param array $config The caller-supplied partial config.
	 *
	 * @return array The merged abonnement data.
	 */
	private function mergeAbonnementConfig(array $current, array $config): array {
		foreach (['name', 'kanalen', 'authHeaderName', 'authScheme'] as $key) {
			if (array_key_exists($key, $config) === true) {
				$current[$key] = $config[$key];
			}
		}

		return $current;
	}//end mergeAbonnementConfig()

	/**
	 * Persist the initial `pending` `notificaties_abonnement` record (Phase 1
	 * of {@see createAbonnement()}).
	 *
	 * @param array $config `{name, sourceId, kanalen, authHeaderName?, authScheme?}`.
	 *
	 * @return ObjectEntity The persisted, `pending` record.
	 */
	private function persistPendingAbonnement(array $config): ObjectEntity {
		return $this->objectService->saveObject(
			object: [
				'name' => (string)($config['name'] ?? ''),
				'sourceId' => (string)($config['sourceId'] ?? ''),
				'kanalen' => (array)($config['kanalen'] ?? []),
				'authHeaderName' => (string)($config['authHeaderName'] ?? 'Authorization'),
				'authScheme' => (string)($config['authScheme'] ?? ''),
				'status' => self::STATUS_PENDING,
			],
			register: 'openconnector',
			schema: 'notificaties_abonnement'
		);

	}//end persistPendingAbonnement()

	/**
	 * Persist a `notificaties_abonnement` record's current state.
	 *
	 * @param array $data The full abonnement data.
	 * @param string $id The abonnement UUID.
	 *
	 * @return ObjectEntity The persisted record.
	 */
	private function persistAbonnement(array $data, string $id): ObjectEntity {
		return $this->objectService->saveObject(
			object: $data,
			register: 'openconnector',
			schema: 'notificaties_abonnement',
			uuid: $id
		);

	}//end persistAbonnement()

	/**
	 * Provision the companion `consumer` OR-object for a newly-created
	 * abonnement (Decision 2).
	 *
	 * @param array $kanalen The abonnement's kanalen (used to build the consumer's display name).
	 *
	 * @return array{consumerId: string, secret: string} The created consumer's uuid and its generated secret.
	 */
	private function provisionConsumer(array $kanalen): array {
		$secret = substr(
			$this->signatureService->generateSecret(),
			strlen(WebhookSignatureService::SECRET_PREFIX)
		);

		$channelNames = array_values(
			array_filter(
				array_map(static fn ($channel) => (string)($channel['naam'] ?? ''), $kanalen)
			)
		);

		$consumer = $this->objectService->saveObject(
			object: [
				'name' => 'Notificaties abonnement: ' . implode(', ', $channelNames),
				'authorizationType' => 'apiKey',
				'authorizationConfiguration' => ['apiKey' => $secret],
			],
			register: 'openconnector',
			schema: 'consumer'
		);

		return ['consumerId' => $consumer->getUuid(), 'secret' => $secret];
	}//end provisionConsumer()

	/**
	 * Register a newly-provisioned abonnement against the remote Notificaties
	 * API (Phase 3 of {@see createAbonnement()}).
	 *
	 * @param ObjectEntity $source The resolved Source.
	 * @param string $callbackUrl This app's callback URL, sent as `callbackUrl`.
	 * @param string $secret The companion consumer's secret, sent as `auth`.
	 * @param array $kanalen The kanalen to register for.
	 *
	 * @return array{url: string, error: string|null} The remote-assigned url on success, or a
	 *                                                descriptive error (no exception ever propagates — REQ-001).
	 */
	private function registerAbonnementWithRemote(ObjectEntity $source, string $callbackUrl, string $secret, array $kanalen): array {
		$call = $this->callRemoteAbonnement(
			source: $source,
			endpoint: '/abonnement',
			method: 'POST',
			config: [
				'json' => [
					'callbackUrl' => $callbackUrl,
					'auth' => $secret,
					'kanalen' => $kanalen,
				],
			],
			context: 'registration'
		);

		$error = $this->settlementError(call: $call, verb: 'registration');
		if ($error !== null) {
			return ['url' => '', 'error' => $error];
		}

		$body = $this->getResponseBody(callLog: $call['callLog']);
		$url = '';
		if (is_array($body) === true) {
			$url = (string)($body['url'] ?? '');
		}

		return ['url' => $url, 'error' => null];
	}//end registerAbonnementWithRemote()

	/**
	 * Call the remote Notificaties API for an abonnement CRUD action,
	 * catching every transport/config exception so no exception ever
	 * propagates to the CRUD caller (REQ-001).
	 *
	 * @param ObjectEntity $source The resolved Source.
	 * @param string $endpoint The endpoint (relative to `$source`'s `location`) to call.
	 * @param string $method The HTTP method.
	 * @param array $config The `CallService::call()` config (e.g. `['json' => ...]`).
	 * @param string $context A short label for the log message (e.g. 'registration', 'update', 'delete').
	 *
	 * @return array{statusCode: integer, callLog: ObjectEntity|null, exceptionMessage: string|null}
	 */
	private function callRemoteAbonnement(ObjectEntity $source, string $endpoint, string $method, array $config, string $context): array {
		try {
			$callLog = $this->callService->call(source: $source, endpoint: $endpoint, method: $method, config: $config);
		} catch (Throwable $e) {
			$this->logger->error(
				'[NotificatiesSubscriberService] abonnement ' . $context . ' failed: ' . $e->getMessage(),
				['exception' => $e]
			);

			return ['statusCode' => 0, 'callLog' => null, 'exceptionMessage' => $e->getMessage()];
		}

		return ['statusCode' => $this->getStatusCode(callLog: $callLog), 'callLog' => $callLog, 'exceptionMessage' => null];
	}//end callRemoteAbonnement()

	/**
	 * Derive a descriptive error from a {@see callRemoteAbonnement()} result,
	 * or null when it settled with a 2xx status.
	 *
	 * @param array $call The result of {@see callRemoteAbonnement()}.
	 * @param string $verb A short label for the error message (e.g. 'registration', 'update').
	 *
	 * @return string|null The error, or null on 2xx success.
	 */
	private function settlementError(array $call, string $verb): ?string {
		if ($call['exceptionMessage'] !== null) {
			return $call['exceptionMessage'];
		}

		$statusCode = (int)$call['statusCode'];
		if ($statusCode >= 200 && $statusCode < 300) {
			return null;
		}

		return 'Remote API returned status ' . $statusCode . ' during ' . $verb;
	}//end settlementError()

	/**
	 * Delete an abonnement: DELETE the remote registration, then (on success
	 * only) cascade-delete its companion consumer (REQ-004).
	 *
	 * @param string $id The abonnement UUID.
	 *
	 * @return ObjectEntity The persisted `notificaties_abonnement` record —
	 *                      `status='deleted'` on success, otherwise unchanged
	 *                      with `lastError` updated (REQ-001's "does NOT
	 *                      proceed" failure-safety guarantee).
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When the abonnement does not exist.
	 *
	 * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-abonnement-registration-update-and-deletion-against-the-remote-api-req-001
	 * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-abonnement-deletion-cascades-its-companion-consumer-req-004
	 */
	public function deleteAbonnement(string $id): ObjectEntity {
		$abonnement = $this->objectService->find(id: $id, register: 'openconnector', schema: 'notificaties_abonnement');
		$data = $abonnement->getObject();

		if (($data['status'] ?? '') === self::STATUS_DELETED) {
			// Idempotent — already deleted.
			return $abonnement;
		}

		$source = $this->resolveSource(sourceId: (string)($data['sourceId'] ?? ''));
		if ($source === null) {
			$data['lastError'] = 'Source not found: ' . ($data['sourceId'] ?? '');

			return $this->persistAbonnement(data: $data, id: $id);
		}

		$endpoint = $this->remoteAbonnementEndpoint(source: $source, remoteUrl: ($data['url'] ?? null));
		$call = $this->callRemoteAbonnement(source: $source, endpoint: $endpoint, method: 'DELETE', config: [], context: 'delete');

		// 2xx OR 404 (already gone remotely) settles the local delete (REQ-001).
		$notFoundRemotely = ((int)$call['statusCode'] === 404);
		if ($notFoundRemotely === false && $this->settlementError(call: $call, verb: 'delete') !== null) {
			$data['lastError'] = $this->settlementError(call: $call, verb: 'delete');

			return $this->persistAbonnement(data: $data, id: $id);
		}

		$data['status'] = self::STATUS_DELETED;
		$data['lastError'] = null;

		$this->cascadeDeleteConsumer(consumerId: (string)($data['consumerId'] ?? ''), abonnementId: $id);

		return $this->persistAbonnement(data: $data, id: $id);
	}//end deleteAbonnement()

	/**
	 * Delete an abonnement's companion consumer (REQ-004). A delete failure
	 * MUST NOT block the abonnement's own `deleted` transition — logged only.
	 *
	 * @param string $consumerId The companion consumer's uuid (no-op when empty).
	 * @param string $abonnementId The owning abonnement's uuid (log context only).
	 *
	 * @return void
	 */
	private function cascadeDeleteConsumer(string $consumerId, string $abonnementId): void {
		if ($consumerId === '') {
			return;
		}

		try {
			$this->objectService->deleteObject(uuid: $consumerId);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[NotificatiesSubscriberService] failed to cascade-delete companion consumer ' . $consumerId . ': ' . $e->getMessage(),
				['exception' => $e, 'abonnementId' => $abonnementId]
			);
		}

	}//end cascadeDeleteConsumer()

	/**
	 * Resolve the `notificaties_abonnement` record for an abonnement id, or
	 * null when it does not exist. Used by the controller's callback auth
	 * gate to resolve the per-abonnement `authHeaderName`/`authScheme`
	 * (Decision 4) and the companion `consumerId` (defense-in-depth cross-
	 * check — REQ-002 requires *a* matching consumer; this additionally
	 * requires it to be *this abonnement's own* consumer).
	 *
	 * @param string $abonnementId The abonnement UUID.
	 *
	 * @return ObjectEntity|null The abonnement record, or null when not found.
	 *
	 * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-callback-authentication-reuses-consumer-management-apikey-verification-req-002
	 */
	public function findAbonnement(string $abonnementId): ?ObjectEntity {
		try {
			return $this->objectService->find(id: $abonnementId, register: 'openconnector', schema: 'notificaties_abonnement');
		} catch (Throwable $e) {
			return null;
		}

	}//end findAbonnement()

	/**
	 * Normalize an authenticated, well-formed inbound ZGW notification into
	 * the existing CloudEvents pipe via {@see EventService::emitCloudEvent()}.
	 *
	 * @param string $abonnementId The abonnement the notification arrived for (correlation only).
	 * @param array $notification The ZGW notification body: `{kanaal, hoofdObject, resource,
	 *                            resourceUrl, actie, aanmaakdatum, kenmerken}`.
	 *
	 * @return ObjectEntity[] The created CloudEvent messages (`events-cloudevents` REQ-001 fan-out).
	 *
	 * @throws \InvalidArgumentException When `kanaal`/`resource`/`actie` is missing — the caller
	 *                                   (controller) MUST translate this to HTTP 400 and MUST NOT
	 *                                   have persisted anything before this throws.
	 *
	 * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-inbound-notifications-are-normalized-into-the-existing-cloudevents-pipe-req-003
	 * @spec openspec/specs/events-cloudevents/spec.md#requirement-inbound-zgw-notificaties-api-notifications-are-normalized-to-cloudevents-via-emitcloudevent-req-011
	 */
	public function handleInboundNotification(string $abonnementId, array $notification): array {
		// STATUTORY WIRE SHAPE — an inbound ZGW notification carries `kanaal`,
		// not `channel`. Reading the English key made every conformant
		// producer's notification look malformed (see the error message below,
		// which always named `kanaal`).
		$channel = (string)($notification['kanaal'] ?? '');
		$resource = (string)($notification['resource'] ?? '');
		$action = (string)($notification['actie'] ?? '');

		if ($channel === '' || $resource === '' || $action === '') {
			throw new InvalidArgumentException('Malformed ZGW notification: kanaal, resource and actie are required');
		}

		$data = $notification;
		$data['abonnementId'] = $abonnementId;

		return $this->eventService->emitCloudEvent(
			type: 'nl.conduction.zgw.notificatie.' . $resource,
			source: '/notificaties-api/' . $channel,
			subject: ($notification['resourceUrl'] ?? null),
			data: $data
		);

	}//end handleInboundNotification()

	/**
	 * Derive the ZGW notification publish body from a matched CloudEvent and
	 * a `notificaties` action block (`events-cloudevents` REQ-010).
	 *
	 * A pure/static function (no service dependencies) so
	 * {@see EventService::dispatchNotificatiesAction()} can call it directly
	 * without a constructor dependency on this class (see class docblock for
	 * why: this class already depends on EventService).
	 *
	 * @param ObjectEntity $event The matched `event` OR-object.
	 * @param array $action The resolved `action` block: `{kind, sourceId, kanaal,
	 *                      hoofdObjectField?, resourceField?, actieMap?, kenmerken?}`.
	 *
	 * @return array{kanaal: string, hoofdObject: mixed, resource: mixed, resourceUrl: mixed,
	 *               actie: string, aanmaakdatum: mixed, kenmerken: array} The ZGW notification
	 *               body, in the statutory wire shape (these key names are the
	 *               Notificaties API contract and are exempt from the
	 *               English-vocabulary rule).
	 *
	 * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-zgw-notification-publish-body-shape-req-005
	 */
	public static function buildNotificationBody(ObjectEntity $event, array $action): array {
		$eventData = $event->getObject();
		$data = (array)($eventData['data'] ?? []);
		$dot = new Dot($data);

		$channel = (string)($action['channel'] ?? '');

		$mainObjectField = ($action['mainObjectField'] ?? null);
		$mainObject = ($eventData['subject'] ?? null);
		if ($mainObjectField !== null && $mainObjectField !== '') {
			$mainObject = $dot->get($mainObjectField);
		}

		$resourceField = ($action['resourceField'] ?? null);
		$resource = self::deriveResourceFromType(type: (string)($eventData['type'] ?? ''));
		if ($resourceField !== null && $resourceField !== '') {
			$resource = $dot->get($resourceField);
		}

		$resourceUrl = $dot->get('attributes.url');
		if ($resourceUrl === null) {
			$resourceUrl = ($eventData['subject'] ?? null);
		}

		$actionMap = (array)($action['actionMap'] ?? []);
		if (empty($actionMap) === true) {
			$actionMap = self::DEFAULT_ACTIE_MAP;
		}

		$suffix = self::typeSuffix(type: (string)($eventData['type'] ?? ''));
		$actionName = (string)($actionMap[$suffix] ?? $suffix);

		$staticCharacteristics = (array)($action['characteristics'] ?? []);
		$eventCharacteristics = (array)($dot->get('characteristics') ?? []);
		// Event-supplied values win on key collision (REQ-005).
		$characteristics = array_merge($staticCharacteristics, $eventCharacteristics);

		// STATUTORY WIRE SHAPE — these keys are the ZGW Notificaties API
		// contract, not our vocabulary. `$body` is POSTed verbatim as JSON by
		// EventService::publishNotificatiesAction(), so `kanaal`, `actie` and
		// `kenmerken` MUST NOT be translated to English. Internal variable
		// names stay English; only the wire keys are Dutch.
		return [
			'kanaal' => $channel,
			'hoofdObject' => $mainObject,
			'resource' => $resource,
			'resourceUrl' => $resourceUrl,
			'actie' => $actionName,
			'aanmaakdatum' => ($eventData['time'] ?? null),
			'kenmerken' => $characteristics,
		];

	}//end buildNotificationBody()

	/**
	 * Derive a resource label from a CloudEvents `type` when no
	 * `resourceField` override is configured: the dot-segment immediately
	 * preceding the trailing actie-verb segment (e.g.
	 * `com.nextcloud.openregister.object.updated` -> `object`).
	 *
	 * @param string $type The CloudEvents `type`.
	 *
	 * @return string The derived resource label.
	 *
	 * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-zgw-notification-publish-body-shape-req-005
	 */
	private static function deriveResourceFromType(string $type): string {
		$parts = array_values(array_filter(explode('.', $type), static fn ($part) => $part !== ''));

		if (count($parts) >= 2) {
			return $parts[count($parts) - 2];
		}

		if (count($parts) === 1) {
			return $parts[0];
		}

		return 'object';
	}//end deriveResourceFromType()

	/**
	 * The trailing dot-segment of a CloudEvents `type` (the actie-verb
	 * segment, e.g. `updated` for `com.nextcloud.openregister.object.updated`).
	 *
	 * @param string $type The CloudEvents `type`.
	 *
	 * @return string The trailing segment, or '' when `$type` is empty.
	 *
	 * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-zgw-notification-publish-body-shape-req-005
	 */
	private static function typeSuffix(string $type): string {
		if ($type === '') {
			return '';
		}

		$parts = explode('.', $type);

		return (string)end($parts);
	}//end typeSuffix()

	/**
	 * Resolve a `Source` OR-object by uuid, tolerating a missing/invalid id.
	 *
	 * @param string $sourceId The Source UUID.
	 *
	 * @return ObjectEntity|null The resolved Source, or null when not found.
	 */
	private function resolveSource(string $sourceId): ?ObjectEntity {
		if ($sourceId === '') {
			return null;
		}

		try {
			return $this->objectService->find(id: $sourceId, register: 'openconnector', schema: 'source');
		} catch (Throwable $e) {
			return null;
		}

	}//end resolveSource()

	/**
	 * Compute the endpoint (relative to `$source`'s `location`) to call for
	 * an abonnement update/delete, from the remote-assigned abonnement `url`
	 * captured at registration time (REQ-001). Falls back to `/abonnement`
	 * when no remote url was ever recorded (e.g. a `create` that failed
	 * before a `url` was assigned).
	 *
	 * @param ObjectEntity $source The resolved Source.
	 * @param string|null $remoteUrl The abonnement's remote-assigned `url`.
	 *
	 * @return string The endpoint to pass to {@see CallService::call()}.
	 */
	private function remoteAbonnementEndpoint(ObjectEntity $source, ?string $remoteUrl): string {
		if ($remoteUrl === null || $remoteUrl === '') {
			return '/abonnement';
		}

		$location = (string)($source->getObject()['location'] ?? '');
		if ($location !== '' && str_starts_with($remoteUrl, $location) === true) {
			return substr($remoteUrl, strlen($location));
		}

		return $remoteUrl;
	}//end remoteAbonnementEndpoint()

	/**
	 * Get the HTTP status code from a call log ObjectEntity.
	 *
	 * @param ObjectEntity|null $callLog The entity returned by {@see CallService::call()}.
	 *
	 * @return integer HTTP status code, 0 if `$callLog` is null or missing.
	 */
	private function getStatusCode(?ObjectEntity $callLog): int {
		if ($callLog === null) {
			return 0;
		}

		$data = $callLog->getObject();

		return (int)($data['statusCode'] ?? 0);
	}//end getStatusCode()

	/**
	 * Get the decoded response body from a call log ObjectEntity.
	 *
	 * @param ObjectEntity|null $callLog The entity returned by {@see CallService::call()}.
	 *
	 * @return mixed The decoded response body, or null.
	 */
	private function getResponseBody(?ObjectEntity $callLog): mixed {
		if ($callLog === null) {
			return null;
		}

		$data = $callLog->getObject();
		$body = ($data['response']['body'] ?? null);

		if (is_string($body) === true) {
			$decoded = json_decode($body, true);
			if ($decoded !== null) {
				return $decoded;
			}

			return $body;
		}

		return $body;
	}//end getResponseBody()
}//end class

<?php

/**
 * Contract of the NotificatiesSubscriberService.
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

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Public operations of {@see NotificatiesSubscriberService} that other components call.
 */
interface NotificatiesSubscriberServiceInterface {
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
	public function createAbonnement(array $config): ObjectEntity;

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
	public function updateAbonnement(string $id, array $config): ObjectEntity;

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
	public function deleteAbonnement(string $id): ObjectEntity;

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
	public function findAbonnement(string $abonnementId): ?ObjectEntity;

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
	public function handleInboundNotification(string $abonnementId, array $notification): array;
}

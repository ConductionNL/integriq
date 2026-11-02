<?php

/**
 * Contract of the PeppolTransmissionService.
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

use OCA\Integriq\Exception\PeppolProviderException;
use OCA\Integriq\Service\Peppol\PeppolAccessPointProviderInterface;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Public operations of {@see PeppolTransmissionService} that other components call.
 */
interface PeppolTransmissionServiceInterface {
	/**
	 * Validate a Peppol participant identifier's `scheme:identifier` shape.
	 *
	 * @param string $peppolId The candidate identifier.
	 *
	 * @return boolean True when the shape is valid (a numeric ISO/IEC 6523 scheme, a colon, a non-empty identifier).
	 *
	 * @spec openspec/specs/peppol-access-point-connector/spec.md#scenario-a-malformed-participant-id-is-rejected-with-400
	 */
	public function isValidPeppolId(string $peppolId): bool;

	/**
	 * Resolve a Peppol participant's SMP/directory entry via the configured provider.
	 *
	 * @param string $peppolId The participant identifier (assumed already shape-validated).
	 *
	 * @return array{exists: bool, supportedDocTypes: string[]} The lookup result.
	 *
	 * @throws PeppolProviderException When no Peppol source is configured, or the Access Point errors.
	 *
	 * @spec openspec/specs/peppol-access-point-connector/spec.md#requirement-peppol-participant-smp-lookup-endpoint-req-001
	 */
	public function lookupParticipant(string $peppolId): array;

	/**
	 * Handle a consumed `nl.conduction.peppol.outbound.requested` event: create/reuse the
	 * `peppol_transmission` record and drive it through the submit -> status lifecycle.
	 *
	 * Idempotent per `objectUri`+`documentType`: a redelivered event for an
	 * already `sent`/`delivered` transmission is a no-op, and a redelivered
	 * event for a `failed` transmission that has exhausted its retry budget
	 * stays dead-lettered without a further AP call.
	 *
	 * @param array $eventData The CloudEvent `data` payload: `{sourceApp, objectType, objectUri,
	 *                         recipientPeppolId, documentType, payloadFileUri}`.
	 *
	 * @return ObjectEntity|null The resulting transmission, or null when the payload is unusable.
	 *
	 * @spec openspec/specs/peppol-access-point-connector/spec.md#requirement-event-driven-outbound-transmission-with-status-lifecycle-req-003
	 */
	public function handleOutboundRequested(array $eventData): ?ObjectEntity;

	/**
	 * Apply a verified AP delivery callback (`delivered`/`rejected`) to its transmission.
	 *
	 * A callback for an unknown `transmissionId` is recorded (logged) and MUST NOT 500 —
	 * it returns null rather than throwing.
	 *
	 * @param string $transmissionId The AP-assigned transmission id from the callback.
	 * @param string $status The reported status (`delivered`|`rejected`).
	 * @param string|null $detail An optional reason/detail message.
	 *
	 * @return ObjectEntity|null The updated transmission, or null when the transmissionId is unknown.
	 *
	 * @spec openspec/specs/peppol-access-point-connector/spec.md#requirement-inbound-receive-webhook-that-republishes-ap-callbacks-as-events-req-005
	 */
	public function handleDeliveryCallback(string $transmissionId, string $status, ?string $detail): ?ObjectEntity;

	/**
	 * Republish a verified inbound-document AP notification as a CloudEvent.
	 *
	 * @param string $senderPeppolId The sender participant identifier.
	 * @param string $documentType The UBL document type slug.
	 * @param string $payloadReference A reference (URI) to the inbound payload.
	 *
	 * @return array<ObjectEntity> The created CloudEvent messages.
	 *
	 * @spec openspec/specs/peppol-access-point-connector/spec.md#scenario-an-inbound-document-is-republished-as-a-cloudevent
	 */
	public function handleInboundDocument(string $senderPeppolId, string $documentType, string $payloadReference): array;

	/**
	 * Resolve the single active Peppol source (`type=peppol`, `isEnabled=true`).
	 *
	 * @return ObjectEntity The resolved source.
	 *
	 * @throws PeppolProviderException When no active Peppol source is configured.
	 *
	 * @spec openspec/specs/peppol-access-point-connector/spec.md#requirement-access-point-provider-abstraction-with-log-and-generic-rest-bindings-req-002
	 */
	public function resolveActiveSource(): ObjectEntity;

	/**
	 * Select the provider binding named by `configuration.provider` (default `log`).
	 *
	 * @param array $configuration The Peppol source's `configuration` object.
	 *
	 * @return PeppolAccessPointProviderInterface The resolved provider binding.
	 *
	 * @spec openspec/specs/peppol-access-point-connector/spec.md#requirement-access-point-provider-abstraction-with-log-and-generic-rest-bindings-req-002
	 */
	public function resolveProvider(array $configuration): PeppolAccessPointProviderInterface;
}

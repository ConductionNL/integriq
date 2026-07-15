<?php

/**
 * OpenConnector Peppol Transmission Service.
 *
 * Core of the peppol-access-point-connector: resolves the configured Peppol
 * source + provider binding, serves the participant/SMP lookup, and drives
 * the `peppol_transmission` queue -> submit -> status lifecycle for outbound
 * UBL documents. Inbound AP callbacks (delivery status + inbound-document
 * notifications) are also processed here so PeppolController stays a thin
 * HTTP/signature-verification shell.
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
 * @spec openspec/changes/peppol-access-point-connector/specs/peppol-access-point-connector/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service;

use DateTime;
use OCA\OpenConnector\Exception\PeppolProviderException;
use OCA\OpenConnector\Service\Peppol\LogPeppolAccessPointProvider;
use OCA\OpenConnector\Service\Peppol\PeppolAccessPointProviderInterface;
use OCA\OpenConnector\Service\Peppol\RestPeppolAccessPointProvider;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Drives Peppol participant lookup and the outbound transmission lifecycle.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ElseExpression)
 *
 * @spec openspec/changes/peppol-access-point-connector/specs/peppol-access-point-connector/spec.md
 */
class PeppolTransmissionService
{

    /**
     * OpenRegister register slug holding Peppol sources and transmissions.
     *
     * @var string
     */
    public const REGISTER = 'openconnector';

    /**
     * OR schema slug for a Peppol source.
     *
     * @var string
     */
    public const SCHEMA_SOURCE = 'source';

    /**
     * OR schema slug for a peppol_transmission record.
     *
     * @var string
     */
    public const SCHEMA_TRANSMISSION = 'peppol_transmission';

    /**
     * `source.type` value identifying a Peppol Access Point source.
     *
     * @var string
     */
    public const SOURCE_TYPE = 'peppol';

    /**
     * CloudEvent type consumed to trigger an outbound transmission.
     *
     * @var string
     */
    public const EVENT_TYPE_OUTBOUND_REQUESTED = 'nl.conduction.peppol.outbound.requested';

    /**
     * CloudEvent type emitted on every transmission status change.
     *
     * @var string
     */
    public const EVENT_TYPE_DELIVERY_STATUS = 'nl.conduction.peppol.delivery.status';

    /**
     * CloudEvent type emitted when an inbound AP document notification is republished.
     *
     * @var string
     */
    public const EVENT_TYPE_INBOUND_RECEIVED = 'nl.conduction.peppol.inbound.received';

    /**
     * Maximum submission attempts before a transmission is dead-lettered (status=failed, terminal).
     *
     * @var integer
     */
    public const MAX_ATTEMPTS = 3;

    /**
     * Terminal/in-flight statuses for which a redelivered outbound.requested is a no-op (idempotency).
     *
     * @var string[]
     */
    private const NO_RETRANSMIT_STATUSES = ['sent', 'delivered'];

    /**
     * Constructor.
     *
     * @param ORObjectService               $objectService OR object service for source/transmission persistence.
     * @param LogPeppolAccessPointProvider  $logProvider   The sandbox provider binding.
     * @param RestPeppolAccessPointProvider $restProvider  The generic REST provider binding.
     * @param EventService                  $eventService  Emits delivery-status / inbound-received CloudEvents.
     * @param IL10N                         $l             The localization service (default status detail text).
     * @param LoggerInterface               $logger        Logger for non-fatal diagnostics.
     */
    public function __construct(
        private readonly ORObjectService $objectService,
        private readonly LogPeppolAccessPointProvider $logProvider,
        private readonly RestPeppolAccessPointProvider $restProvider,
        private readonly EventService $eventService,
        private readonly IL10N $l,
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()

    /**
     * Validate a Peppol participant identifier's `scheme:identifier` shape.
     *
     * @param string $peppolId The candidate identifier.
     *
     * @return boolean True when the shape is valid (a numeric ISO/IEC 6523 scheme, a colon, a non-empty identifier).
     *
     * @spec openspec/changes/peppol-access-point-connector/specs/peppol-access-point-connector/spec.md#scenario-a-malformed-participant-id-is-rejected-with-400
     */
    public function isValidPeppolId(string $peppolId): bool
    {
        return (preg_match('/^[0-9]{4}:\S+$/', $peppolId) === 1);

    }//end isValidPeppolId()

    /**
     * Resolve a Peppol participant's SMP/directory entry via the configured provider.
     *
     * @param string $peppolId The participant identifier (assumed already shape-validated).
     *
     * @return array{exists: bool, supportedDocTypes: string[]} The lookup result.
     *
     * @throws PeppolProviderException When no Peppol source is configured, or the Access Point errors.
     *
     * @spec openspec/changes/peppol-access-point-connector/specs/peppol-access-point-connector/spec.md#requirement-peppol-participant--smp-lookup-endpoint-req-001
     */
    public function lookupParticipant(string $peppolId): array
    {
        $source        = $this->resolveActiveSource();
        $configuration = ($source->getObject()['configuration'] ?? []);
        $provider      = $this->resolveProvider(configuration: $configuration);

        return $provider->lookupParticipant(sourceConfiguration: $configuration, peppolId: $peppolId);

    }//end lookupParticipant()

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
     * @spec openspec/changes/peppol-access-point-connector/specs/peppol-access-point-connector/spec.md#requirement-event-driven-outbound-transmission-with-status-lifecycle-req-003
     */
    public function handleOutboundRequested(array $eventData): ?ObjectEntity
    {
        $objectUri         = (string) ($eventData['objectUri'] ?? '');
        $recipientPeppolId = (string) ($eventData['recipientPeppolId'] ?? '');
        $documentType      = (string) ($eventData['documentType'] ?? '');

        if ($objectUri === '' || $recipientPeppolId === '' || $documentType === '') {
            $this->logger->warning(
                '[PeppolTransmissionService] outbound.requested event is missing required fields; ignored',
                ['eventData' => $eventData]
            );
            return null;
        }

        $sourceApp      = (string) ($eventData['sourceApp'] ?? '');
        $payloadFileUri = (string) ($eventData['payloadFileUri'] ?? '');

        $existing = $this->findTransmission(objectUri: $objectUri, documentType: $documentType);
        if ($existing !== null) {
            $existingData = $existing->getObject();
            $status       = (string) ($existingData['status'] ?? '');

            if (in_array($status, self::NO_RETRANSMIT_STATUSES, true) === true) {
                // Already transmitted (or in a terminal successful state): idempotent no-op.
                return $existing;
            }

            $attempts = (array) ($existingData['attempts'] ?? []);
            if ($status === 'failed' && count($attempts) >= self::MAX_ATTEMPTS) {
                // Retry budget exhausted: stays dead-lettered, no further AP call.
                return $existing;
            }

            $transmission = $existing;
        } else {
            $transmission = $this->objectService->saveObject(
                object: [
                    'objectUri'         => $objectUri,
                    'sourceApp'         => $sourceApp,
                    'recipientPeppolId' => $recipientPeppolId,
                    'documentType'      => $documentType,
                    'payloadFileUri'    => $payloadFileUri,
                    'transmissionId'    => null,
                    'status'            => 'queued',
                    'detail'            => $this->l->t('Transmission queued'),
                    'attempts'          => [],
                ],
                register: self::REGISTER,
                schema: self::SCHEMA_TRANSMISSION
            );
        }//end if

        return $this->attemptSubmission(transmission: $transmission, payloadFileUri: $payloadFileUri);

    }//end handleOutboundRequested()

    /**
     * Attempt one AP submission for a `queued`/retryable `failed` transmission.
     *
     * @param ObjectEntity $transmission   The transmission to submit.
     * @param string       $payloadFileUri Reference to the UBL payload (transported as-is — see design.md scope note).
     *
     * @return ObjectEntity The updated transmission.
     */
    private function attemptSubmission(ObjectEntity $transmission, string $payloadFileUri): ObjectEntity
    {
        $data      = $transmission->getObject();
        $attempts  = (array) ($data['attempts'] ?? []);
        $objectUri = ($data['objectUri'] ?? null);

        try {
            $source        = $this->resolveActiveSource();
            $configuration = ($source->getObject()['configuration'] ?? []);
            $provider      = $this->resolveProvider(configuration: $configuration);

            $transmissionId = $provider->submitDocument(
                sourceConfiguration: $configuration,
                recipientPeppolId: (string) $data['recipientPeppolId'],
                documentType: (string) $data['documentType'],
                payload: $payloadFileUri
            );

            $attempts[]       = ['at' => (new DateTime())->format('c'), 'error' => null];
            $data['attempts'] = $attempts;
            $data['transmissionId'] = $transmissionId;
            $data['status']         = 'sent';
            $data['detail']         = null;

            $saved = $this->objectService->saveObject(
                object: $data,
                register: self::REGISTER,
                schema: self::SCHEMA_TRANSMISSION,
                uuid: $transmission->getUuid()
            );

            $this->emitDeliveryStatus(transmission: $saved);
            return $saved;
        } catch (Throwable $exception) {
            $attempts[]       = ['at' => (new DateTime())->format('c'), 'error' => $exception->getMessage()];
            $data['attempts'] = $attempts;
            $data['status']   = 'failed';
            $data['detail']   = $exception->getMessage();

            $saved = $this->objectService->saveObject(
                object: $data,
                register: self::REGISTER,
                schema: self::SCHEMA_TRANSMISSION,
                uuid: $transmission->getUuid()
            );

            $this->logger->warning(
                '[PeppolTransmissionService] transmission submission failed',
                [
                    'objectUri' => $objectUri,
                    'attempts'  => count($attempts),
                    'exception' => $exception->getMessage(),
                ]
            );

            $this->emitDeliveryStatus(transmission: $saved);
            return $saved;
        }//end try

    }//end attemptSubmission()

    /**
     * Apply a verified AP delivery callback (`delivered`/`rejected`) to its transmission.
     *
     * A callback for an unknown `transmissionId` is recorded (logged) and MUST NOT 500 —
     * it returns null rather than throwing.
     *
     * @param string      $transmissionId The AP-assigned transmission id from the callback.
     * @param string      $status         The reported status (`delivered`|`rejected`).
     * @param string|null $detail         An optional reason/detail message.
     *
     * @return ObjectEntity|null The updated transmission, or null when the transmissionId is unknown.
     *
     * @spec openspec/changes/peppol-access-point-connector/specs/peppol-access-point-connector/spec.md#requirement-inbound-receive-webhook-that-republishes-ap-callbacks-as-events-req-005
     */
    public function handleDeliveryCallback(string $transmissionId, string $status, ?string $detail): ?ObjectEntity
    {
        if (in_array($status, ['delivered', 'rejected'], true) === false) {
            $this->logger->warning(
                '[PeppolTransmissionService] inbound delivery callback carried an unsupported status; ignored',
                ['transmissionId' => $transmissionId, 'status' => $status]
            );
            return null;
        }

        $matches = $this->objectService->findAll(
            config: [
                'filters' => [
                    'register'       => self::REGISTER,
                    'schema'         => self::SCHEMA_TRANSMISSION,
                    'transmissionId' => $transmissionId,
                ],
                'limit'   => 1,
            ]
        );
        $results = ($matches['results'] ?? $matches);

        if (empty($results) === true) {
            $this->logger->warning(
                '[PeppolTransmissionService] inbound delivery callback for unknown transmissionId; recorded, no state change',
                ['transmissionId' => $transmissionId, 'status' => $status]
            );
            return null;
        }

        if ($detail === null || $detail === '') {
            // REQ-004: a rejection MUST carry a non-empty detail even when the
            // AP callback did not supply a reason; "delivered" gets the same
            // default-text treatment for consistency.
            if ($status === 'rejected') {
                $detail = $this->l->t('Transmission rejected');
            } else {
                $detail = $this->l->t('Transmission delivered');
            }
        }

        $transmission   = $results[0];
        $data           = $transmission->getObject();
        $data['status'] = $status;
        $data['detail'] = $detail;

        $saved = $this->objectService->saveObject(
            object: $data,
            register: self::REGISTER,
            schema: self::SCHEMA_TRANSMISSION,
            uuid: $transmission->getUuid()
        );

        $this->emitDeliveryStatus(transmission: $saved);
        return $saved;

    }//end handleDeliveryCallback()

    /**
     * Republish a verified inbound-document AP notification as a CloudEvent.
     *
     * @param string $senderPeppolId   The sender participant identifier.
     * @param string $documentType     The UBL document type slug.
     * @param string $payloadReference A reference (URI) to the inbound payload.
     *
     * @return array<ObjectEntity> The created CloudEvent messages.
     *
     * @spec openspec/changes/peppol-access-point-connector/specs/peppol-access-point-connector/spec.md#scenario-an-inbound-document-is-republished-as-a-cloudevent
     */
    public function handleInboundDocument(string $senderPeppolId, string $documentType, string $payloadReference): array
    {
        return $this->eventService->emitCloudEvent(
            type: self::EVENT_TYPE_INBOUND_RECEIVED,
            source: '/peppol/inbound',
            subject: $senderPeppolId,
            data: [
                'senderPeppolId'   => $senderPeppolId,
                'documentType'     => $documentType,
                'payloadReference' => $payloadReference,
            ]
        );

    }//end handleInboundDocument()

    /**
     * Resolve the single active Peppol source (`type=peppol`, `isEnabled=true`).
     *
     * @return ObjectEntity The resolved source.
     *
     * @throws PeppolProviderException When no active Peppol source is configured.
     */
    public function resolveActiveSource(): ObjectEntity
    {
        $matches = $this->objectService->findAll(
            config: [
                'filters' => [
                    'register'  => self::REGISTER,
                    'schema'    => self::SCHEMA_SOURCE,
                    'type'      => self::SOURCE_TYPE,
                    'isEnabled' => true,
                ],
                'limit'   => 1,
            ]
        );
        $results = ($matches['results'] ?? $matches);

        if (empty($results) === true) {
            throw new PeppolProviderException(
                message: 'No active Peppol source is configured (register "openconnector", schema "source", '
                    .'type "peppol", isEnabled=true). Configure one before using the Peppol connector.'
            );
        }

        return $results[0];

    }//end resolveActiveSource()

    /**
     * Select the provider binding named by `configuration.provider` (default `log`).
     *
     * @param array $configuration The Peppol source's `configuration` object.
     *
     * @return PeppolAccessPointProviderInterface The resolved provider binding.
     *
     * @spec openspec/changes/peppol-access-point-connector/specs/peppol-access-point-connector/spec.md#requirement-access-point-provider-abstraction-with-log-and-generic-rest-bindings-req-002
     */
    public function resolveProvider(array $configuration): PeppolAccessPointProviderInterface
    {
        $provider = ($configuration['provider'] ?? 'log');
        if ($provider === 'rest') {
            return $this->restProvider;
        }

        return $this->logProvider;

    }//end resolveProvider()

    /**
     * Find an existing transmission for the given `objectUri`+`documentType` (idempotency key).
     *
     * @param string $objectUri    The source object URI.
     * @param string $documentType The UBL document type slug.
     *
     * @return ObjectEntity|null The existing transmission, or null when none exists yet.
     */
    private function findTransmission(string $objectUri, string $documentType): ?ObjectEntity
    {
        $matches = $this->objectService->findAll(
            config: [
                'filters' => [
                    'register'     => self::REGISTER,
                    'schema'       => self::SCHEMA_TRANSMISSION,
                    'objectUri'    => $objectUri,
                    'documentType' => $documentType,
                ],
                'limit'   => 1,
            ]
        );
        $results = ($matches['results'] ?? $matches);

        if (empty($results) === true) {
            return null;
        }

        return $results[0];

    }//end findTransmission()

    /**
     * Emit `nl.conduction.peppol.delivery.status` for a transmission's current state.
     *
     * @param ObjectEntity $transmission The transmission whose state just changed.
     *
     * @return void
     *
     * @spec openspec/changes/peppol-access-point-connector/specs/peppol-access-point-connector/spec.md#requirement-delivery-status-cloudevents-on-every-state-change-req-004
     */
    private function emitDeliveryStatus(ObjectEntity $transmission): void
    {
        $data = $transmission->getObject();

        $this->eventService->emitCloudEvent(
            type: self::EVENT_TYPE_DELIVERY_STATUS,
            source: '/peppol/transmissions/'.$transmission->getUuid(),
            subject: $transmission->getUuid(),
            data: [
                'objectUri'      => ($data['objectUri'] ?? null),
                'transmissionId' => ($data['transmissionId'] ?? null),
                'status'         => ($data['status'] ?? null),
                'timestamp'      => (new DateTime())->format('c'),
                'detail'         => ($data['detail'] ?? null),
            ]
        );

    }//end emitDeliveryStatus()
}//end class

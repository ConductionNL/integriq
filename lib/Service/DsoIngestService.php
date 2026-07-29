<?php

/**
 * OpenConnector DSO Ingest Service.
 *
 * Completes the dso-connector-adapter: persists an already-verified,
 * already-parsed DSO Verzoek ({@see DSOParserService::parseVerzoek()}) as a
 * `dso_verzoek` OR record (`received` -> `mapped`|`failed`), and executes
 * the separate, authenticated `verzoek-to-case` handoff through
 * OpenRegister's real `Handoff\HandoffService` under the calling user's own
 * RBAC — see design.md §1 for why this is NOT triggered automatically at
 * webhook-receipt time (HandoffService v1 has no system-user privilege
 * lane). Also drives the outbound leg: builds and dispatches a `status`
 * (voortgangsinformatie) or `besluit` update back to DSO-LV via the
 * {@see Dso\DsoConnectorProviderInterface} seam, persisting a `dso_message`
 * audit row per attempt. Mirrors
 * {@see OpenFormulierenIntakeService} (ingest/handoff split) and
 * {@see IwmoIjwSyncService} (provider seam + per-message audit persistence).
 *
 * `DSOController` stays the thin HTTP/auth shell (signature verification +
 * payload parsing already lived there before this change; this service adds
 * the persistence/mapping/handoff/outbound steps that were previously
 * entirely missing — the controller logged and dropped every verzoek).
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
 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service;

use DateTime;
use OCA\OpenConnector\Exception\DsoProviderException;
use OCA\OpenConnector\Exception\DsoTranslationException;
use OCA\OpenConnector\Service\Dso\DsoClient;
use OCA\OpenConnector\Service\Dso\DsoConnectorProviderInterface;
use OCA\OpenConnector\Service\Dso\DsoVerzoekTranslator;
use OCA\OpenConnector\Service\Dso\LogDsoConnectorProvider;
use OCA\OpenConnector\Service\Security\RawSourceResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Handoff\HandoffService;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Drives dso_verzoek persistence/mapping, the authenticated handoff trigger,
 * and the outbound status/besluit post.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 *
 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md
 */
class DsoIngestService
{

    /**
     * OpenRegister register slug holding sources, verzoeken, and outbound messages.
     *
     * @var string
     */
    public const REGISTER = 'openconnector';

    /**
     * OR schema slug for a DSO source (outbound provider/credential config).
     *
     * @var string
     */
    public const SCHEMA_SOURCE = 'source';

    /**
     * OR schema slug for a dso_verzoek record.
     *
     * @var string
     */
    public const SCHEMA_VERZOEK = 'dso_verzoek';

    /**
     * OR schema slug for a dso_message (outbound audit) record.
     *
     * @var string
     */
    public const SCHEMA_MESSAGE = 'dso_message';

    /**
     * `source.type` value identifying a DSO outbound source.
     *
     * @var string
     */
    public const SOURCE_TYPE = 'dso';

    /**
     * The declared `x-openregister-handoff` entry id on `dso_verzoek` (see
     * lib/Settings/openconnector_register.json).
     *
     * @var string
     */
    public const HANDOFF_ID = 'verzoek-to-case';

    /**
     * Recognised outbound message kinds.
     *
     * @var array<int, string>
     */
    private const OUTBOUND_TYPES = ['status', 'besluit'];

    /**
     * Constructor.
     *
     * @param ORObjectService         $objectService     OR object service for source/verzoek/message persistence.
     * @param HandoffService          $handoffService    Executes the declared handoff under the caller's RBAC.
     * @param DsoVerzoekTranslator    $translator        Translates a parsed Verzoek into normalised handoff fields.
     * @param LogDsoConnectorProvider $logProvider       The sandbox outbound provider binding.
     * @param DsoClient               $restProvider      The generic REST outbound provider binding.
     * @param LoggerInterface         $logger            Logger for non-fatal diagnostics.
     * @param RawSourceResolver       $rawSourceResolver Re-resolves the located source raw (ocon#242).
     */
    public function __construct(
        private readonly ORObjectService $objectService,
        private readonly HandoffService $handoffService,
        private readonly DsoVerzoekTranslator $translator,
        private readonly LogDsoConnectorProvider $logProvider,
        private readonly DsoClient $restProvider,
        private readonly LoggerInterface $logger,
        private readonly RawSourceResolver $rawSourceResolver,
    ) {

    }//end __construct()

    /**
     * Persist one already-verified, already-parsed DSO Verzoek
     * (`received`), then resolve + apply {@see DsoVerzoekTranslator}
     * (`mapped`|`failed`, isolated to this verzoek).
     *
     * @param array<string, mixed> $parsedVerzoek The {@see DSOParserService::parseVerzoek()} output.
     *
     * @return ObjectEntity The persisted `dso_verzoek` record (any status).
     *
     * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-dso_verzoek-lifecycle-with-per-verzoek-isolation-req-003
     */
    public function ingest(array $parsedVerzoek): ObjectEntity
    {
        $verzoek = $this->objectService->saveObject(
            object: [
                'verzoekId'       => (string) ($parsedVerzoek['verzoekId'] ?? ''),
                'bronorganisatie' => (string) ($parsedVerzoek['bronorganisatie'] ?? ''),
                'type'            => (string) ($parsedVerzoek['type'] ?? ''),
                'indieningsdatum' => (string) ($parsedVerzoek['indieningsdatum'] ?? ''),
                'rawVerzoek'      => $parsedVerzoek,
                'mappedTitle'     => '',
                'mappedSummary'   => '',
                'mappedChannel'   => '',
                'mappedPriority'  => '',
                'requester'       => [],
                'status'          => 'received',
                'errorDetail'     => null,
                'correlationId'   => '',
                'targetCase'      => [],
                'receivedAt'      => (new DateTime())->format('c'),
            ],
            register: self::REGISTER,
            schema: self::SCHEMA_VERZOEK
        );

        try {
            $mapped = $this->translator->translate(verzoek: $parsedVerzoek);
        } catch (DsoTranslationException $exception) {
            $this->logger->warning(
                '[DsoIngestService] translation failed for verzoek '.$verzoek->getUuid(),
                ['exception' => $exception->getMessage()]
            );

            return $this->objectService->saveObject(
                object: array_merge(
                    $verzoek->getObject(),
                    ['status' => 'failed', 'errorDetail' => $exception->getMessage()]
                ),
                register: self::REGISTER,
                schema: self::SCHEMA_VERZOEK,
                uuid: $verzoek->getUuid()
            );
        }

        $data = $verzoek->getObject();
        $data['mappedTitle']    = $mapped['mappedTitle'];
        $data['mappedSummary']  = $mapped['mappedSummary'];
        $data['mappedChannel']  = $mapped['mappedChannel'];
        $data['mappedPriority'] = $mapped['mappedPriority'];
        $data['requester']      = $mapped['requester'];
        $data['status']         = 'mapped';

        return $this->objectService->saveObject(
            object: $data,
            register: self::REGISTER,
            schema: self::SCHEMA_VERZOEK,
            uuid: $verzoek->getUuid()
        );

    }//end ingest()

    /**
     * Read one dso_verzoek's current state.
     *
     * @param string $uuid The `dso_verzoek` uuid.
     *
     * @return array<string, mixed> The verzoek's object data, plus `id`.
     *
     * @throws DsoTranslationException When no verzoek exists for the uuid.
     *
     * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-dso_verzoek-lifecycle-with-per-verzoek-isolation-req-003
     */
    public function getVerzoek(string $uuid): array
    {
        $verzoek = $this->objectService->find(id: $uuid, register: self::REGISTER, schema: self::SCHEMA_VERZOEK);
        if ($verzoek instanceof ObjectEntity === false) {
            throw new DsoTranslationException(message: 'No dso_verzoek found for uuid "'.$uuid.'".');
        }

        return ($verzoek->getObject() + ['id' => $verzoek->getUuid()]);

    }//end getVerzoek()

    /**
     * List dso_verzoek records, optionally filtered by status (e.g. `mapped`
     * — the set eligible for the handoff-trigger endpoint).
     *
     * @param string|null $status Optional status filter.
     * @param integer     $limit  Maximum number of records to return.
     *
     * @return array<int, array<string, mixed>> The matching verzoeken (each with `id`).
     *
     * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-rest-surface-to-list-and-complete-mapped-verzoeken-req-004
     */
    public function listVerzoeken(?string $status=null, int $limit=100): array
    {
        $filters = ['register' => self::REGISTER, 'schema' => self::SCHEMA_VERZOEK];
        if ($status !== null) {
            $filters['status'] = $status;
        }

        $matches = $this->objectService->findAll(config: ['filters' => $filters, 'limit' => $limit]);
        $results = ($matches['results'] ?? $matches);

        $list = [];
        foreach ($results as $entity) {
            $list[] = ($entity->getObject() + ['id' => $entity->getUuid()]);
        }

        return $list;

    }//end listVerzoeken()

    /**
     * Execute the declared `verzoek-to-case` handoff for a `mapped`
     * verzoek, as the calling (real, authenticated) user — never a
     * system-account shortcut (design.md §1).
     *
     * @param string $uuid The `dso_verzoek` uuid.
     *
     * @return array<string, mixed> The engine's `execute()` result (`{status, target, correlationId}`
     *                              or `{status: parked, queueEntry}`).
     *
     * @throws DsoTranslationException When the verzoek is unknown or not yet `mapped`.
     *
     * Also propagates OpenRegister's own `Handoff\HandoffException` (not-declared /
     * provider-unavailable) and `NotAuthorizedException` (RBAC refusal) unchanged —
     * omitted from the @throws tag because PHPStan cannot resolve cross-app
     * OCA\OpenRegister\Exception\* types as Throwable subtypes (same limitation
     * documented in phpstan.neon's `unknown class OCA\\OpenRegister\\` ignores).
     *
     * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-declared-ns-case-handoff-executed-by-a-real-authenticated-actor-req-005
     */
    public function handoff(string $uuid): array
    {
        $verzoek = $this->objectService->find(id: $uuid, register: self::REGISTER, schema: self::SCHEMA_VERZOEK);
        if ($verzoek instanceof ObjectEntity === false) {
            throw new DsoTranslationException(message: 'No dso_verzoek found for uuid "'.$uuid.'".');
        }

        $data = $verzoek->getObject();
        if (($data['status'] ?? null) !== 'mapped') {
            throw new DsoTranslationException(
                message: 'Verzoek "'.$uuid.'" is not in "mapped" status (currently "'
                .(string) ($data['status'] ?? 'unknown').'") — a handoff can only be triggered once mapping succeeded.'
            );
        }

        try {
            $result = $this->handoffService->execute(
                register: self::REGISTER,
                schema: self::SCHEMA_VERZOEK,
                id: $uuid,
                handoffId: self::HANDOFF_ID
            );
        } catch (Throwable $exception) {
            $this->markFailed(verzoek: $verzoek, message: $exception->getMessage());
            throw $exception;
        }

        if (($result['status'] ?? null) === 'executed') {
            $this->recordHandoffSuccess(verzoek: $verzoek, result: $result);
        }

        return $result;

    }//end handoff()

    /**
     * Build and dispatch one outbound `status` (voortgangsinformatie) or
     * `besluit` message for a previously received verzoek, persisting a
     * `dso_message` audit row regardless of outcome.
     *
     * @param string               $verzoekUuid The `dso_verzoek` uuid this message concerns.
     * @param string               $type        The message kind: `status` or `besluit`.
     * @param array<string, mixed> $fields      Type-specific fields (`status` for `type=status`;
     *                                          `besluit`/`gemotiveerd` for `type=besluit`).
     *
     * @return array{ref: string, type: string, status: string} The dispatch outcome.
     *
     * @throws DsoTranslationException When the verzoek is unknown, or `type` is not recognised.
     * @throws DsoProviderException    When no active DSO source is configured, or the transport
     *                                 fails (a `status: failed` `dso_message` IS persisted first).
     *
     * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-outbound-status-besluit-post-with-per-message-audit-req-006
     */
    public function postOutbound(string $verzoekUuid, string $type, array $fields=[]): array
    {
        if (in_array($type, self::OUTBOUND_TYPES, true) === false) {
            throw new DsoTranslationException(
                message: 'Unknown outbound DSO message type "'.$type.'" (must be `status` or `besluit`).'
            );
        }

        $verzoek = $this->objectService->find(id: $verzoekUuid, register: self::REGISTER, schema: self::SCHEMA_VERZOEK);
        if ($verzoek instanceof ObjectEntity === false) {
            throw new DsoTranslationException(message: 'No dso_verzoek found for uuid "'.$verzoekUuid.'".');
        }

        $verzoekId     = (string) ($verzoek->getObject()['verzoekId'] ?? '');
        $source        = $this->resolveActiveSource();
        $configuration = ($source->getObject()['configuration'] ?? []);
        $provider      = $this->resolveProvider(configuration: $configuration);

        $payload = array_merge(['verzoekId' => $verzoekId, 'timestamp' => (new DateTime())->format('c')], $fields);

        $status = 'sent';
        $error  = null;
        $ref    = '';
        try {
            $ref = $provider->send(
                sourceConfiguration: $configuration,
                verzoekId: $verzoekId,
                type: $type,
                payload: $payload
            );
        } catch (DsoProviderException $exception) {
            $status = 'failed';
            $error  = $exception->getMessage();
        }

        $this->objectService->saveObject(
            object: [
                'ref'         => $ref,
                'type'        => $type,
                'status'      => $status,
                'payload'     => $payload,
                'verzoekUuid' => $verzoekUuid,
                'error'       => $error,
                'syncedAt'    => (new DateTime())->format('c'),
            ],
            register: self::REGISTER,
            schema: self::SCHEMA_MESSAGE
        );

        if ($status === 'failed') {
            throw new DsoProviderException(message: (string) $error);
        }

        return ['ref' => $ref, 'type' => $type, 'status' => $status];

    }//end postOutbound()

    /**
     * Resolve the single active `dso` outbound source
     * (`type=dso`, `isEnabled=true`).
     *
     * @return ObjectEntity The resolved source.
     *
     * @throws DsoProviderException When no active source is configured.
     *
     * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-outbound-status-besluit-post-with-per-message-audit-req-006
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
            throw new DsoProviderException(
                message: 'No active DSO source is configured (register "openconnector", '
                .'schema "source", type "dso", isEnabled=true).'
            );
        }

        return $this->rawSourceResolver->resolveRaw(source: $results[0]);

    }//end resolveActiveSource()

    /**
     * Resolve the outbound provider binding named by
     * `configuration.provider` (`log`|`rest`), defaulting to the sandbox
     * `log` provider when unset or unrecognised — new/unconfigured
     * deployments never accidentally dispatch a live DSO-LV call.
     *
     * @param array<string, mixed> $configuration The `dso` source's `configuration` object.
     *
     * @return DsoConnectorProviderInterface The resolved provider.
     *
     * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-dso-outbound-provider-abstraction-with-log-and-rest-bindings-req-001
     */
    public function resolveProvider(array $configuration): DsoConnectorProviderInterface
    {
        $providerId = (string) ($configuration['provider'] ?? 'log');
        if ($providerId === 'rest') {
            return $this->restProvider;
        }

        return $this->logProvider;

    }//end resolveProvider()

    /**
     * Best-effort persist the handoff's target/correlation metadata onto the
     * verzoek (`status` itself was already set by the engine's own
     * `onSuccess.set`).
     *
     * @param ObjectEntity         $verzoek The (pre-handoff) verzoek object.
     * @param array<string, mixed> $result  The engine's `execute()` result (`status: executed`).
     *
     * @return void
     */
    private function recordHandoffSuccess(ObjectEntity $verzoek, array $result): void
    {
        $target        = (array) ($result['target'] ?? []);
        $correlationId = (string) ($result['correlationId'] ?? '');

        $current = $this->objectService->find(id: $verzoek->getUuid(), register: self::REGISTER, schema: self::SCHEMA_VERZOEK);
        $data    = $verzoek->getObject();
        if ($current instanceof ObjectEntity === true) {
            $data = $current->getObject();
        }

        $data = array_merge($data, ['targetCase' => $target, 'correlationId' => $correlationId]);

        $this->objectService->saveObject(
            object: $data,
            register: self::REGISTER,
            schema: self::SCHEMA_VERZOEK,
            uuid: $verzoek->getUuid()
        );

    }//end recordHandoffSuccess()

    /**
     * Mark a verzoek `failed` after a handoff execution error — isolated to
     * this verzoek, never thrown past this method (the original exception
     * is rethrown by the caller separately).
     *
     * @param ObjectEntity $verzoek The verzoek being handed off.
     * @param string       $message The failure detail.
     *
     * @return void
     */
    private function markFailed(ObjectEntity $verzoek, string $message): void
    {
        $this->objectService->saveObject(
            object: array_merge($verzoek->getObject(), ['status' => 'failed', 'errorDetail' => $message]),
            register: self::REGISTER,
            schema: self::SCHEMA_VERZOEK,
            uuid: $verzoek->getUuid()
        );

    }//end markFailed()
}//end class

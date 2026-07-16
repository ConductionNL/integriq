<?php

/**
 * OpenConnector KISS Sync Service.
 *
 * Core of the kiss-kcc-bridge: resolves the configured KISS
 * (Klantinteractie Servicesysteem) source + provider binding, runs the
 * scheduled PULL of new/changed klantcontacten into `kiss_klantcontact`
 * records (mapping each klantcontact's onderwerpobjecten to a case
 * reference), and serves the PUSH path that lets a sibling app (e.g.
 * procest's ContactMomentService) register a klantcontact in KISS and link
 * it to a case. Mirrors {@see BankfeedSyncService} (cursor-based pull sweep)
 * and {@see PeppolTransmissionService} (provider seam + REST surface).
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
 * @spec openspec/specs/kiss-kcc-bridge/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service;

use DateTime;
use OCA\OpenConnector\Exception\KissProviderException;
use OCA\OpenConnector\Service\Kiss\KlantinteractiesClient;
use OCA\OpenConnector\Service\Kiss\KlantinteractiesProviderInterface;
use OCA\OpenConnector\Service\Kiss\LogKlantinteractiesProvider;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Drives the KISS klantcontacten pull sync and the push-to-KISS path.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 *
 * @spec openspec/specs/kiss-kcc-bridge/spec.md
 */
class KissSyncService
{

    /**
     * OpenRegister register slug holding KISS sources and klantcontact records.
     *
     * @var string
     */
    public const REGISTER = 'openconnector';

    /**
     * OR schema slug for a KISS source.
     *
     * @var string
     */
    public const SCHEMA_SOURCE = 'source';

    /**
     * OR schema slug for a kiss_klantcontact record.
     *
     * @var string
     */
    public const SCHEMA_KLANTCONTACT = 'kiss_klantcontact';

    /**
     * `source.type` value identifying a KISS / Klantinteracties source.
     *
     * @var string
     */
    public const SOURCE_TYPE = 'kiss';

    /**
     * Default number of klantcontacten pulled per sync sweep (per source).
     *
     * @var integer
     */
    private const DEFAULT_PAGE_SIZE = 100;

    /**
     * `onderwerpobjectidentificator.codeObjecttype` values treated as a case/zaak link.
     *
     * Case-insensitive substring match — see design.md "Mapping
     * onderwerpobjecten to a case reference" for why a substring match
     * (rather than an exact enum) is the documented, deliberately tolerant
     * choice given the unverified live API shape.
     *
     * @var string
     */
    private const CASE_OBJECT_TYPE_MARKER = 'zaak';

    /**
     * `partijIdentificator.codeSoortObjectId` value identifying a raw BSN,
     * hashed before storage — consistent with this app's own
     * `AvgBsnPolicyRule` precedent (see design.md "AVG / BSN handling").
     *
     * @var string
     */
    private const BSN_CODE_SOORT_OBJECT_ID = 'bsn';

    /**
     * Constructor.
     *
     * @param ORObjectService             $objectService OR object service for source/klantcontact persistence.
     * @param LogKlantinteractiesProvider $logProvider   The sandbox provider binding.
     * @param KlantinteractiesClient      $restProvider  The generic REST provider binding.
     * @param IL10N                       $l             The localization service.
     * @param LoggerInterface             $logger        Logger for non-fatal diagnostics.
     */
    public function __construct(
        private readonly ORObjectService $objectService,
        private readonly LogKlantinteractiesProvider $logProvider,
        private readonly KlantinteractiesClient $restProvider,
        private readonly IL10N $l,
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()

    /**
     * Run one pull sweep over every configured KISS source.
     *
     * A source without an active KISS configuration (none configured at all,
     * or the resolved source is misconfigured) is a clean no-op, never a
     * thrown exception out of this method — the cron job calls this
     * unconditionally on every sweep (REQ: pull job no-ops when unconfigured).
     *
     * @return integer The total number of klantcontacten upserted across every source in this sweep.
     *
     * @spec openspec/specs/kiss-kcc-bridge/spec.md
     */
    public function pullAll(): int
    {
        $matches = $this->objectService->findAll(
            config: [
                'filters' => [
                    'register'  => self::REGISTER,
                    'schema'    => self::SCHEMA_SOURCE,
                    'type'      => self::SOURCE_TYPE,
                    'isEnabled' => true,
                ],
            ]
        );
        $results = ($matches['results'] ?? $matches);

        $total = 0;
        foreach ($results as $source) {
            try {
                $outcome = $this->pullSource(source: $source);
                $total  += $outcome['processed'];
            } catch (Throwable $exception) {
                $this->logger->warning(
                    $this->l->t('KISS pull sweep failed for one source; cursor not advanced'),
                    [
                        'sourceUuid' => $source->getUuid(),
                        'exception'  => $exception->getMessage(),
                    ]
                );
            }
        }//end foreach

        return $total;

    }//end pullAll()

    /**
     * Pull one source's new/changed klantcontacten and upsert them.
     *
     * Per-record isolation: a single malformed/unpersistable klantcontact is
     * logged and skipped — it does NOT abort the rest of the page and does
     * NOT block the cursor from advancing (see design.md "Cursor semantics"
     * for why availability of newer records is favoured over perfect retry
     * of a persistently failing one).
     *
     * @param ObjectEntity $source The KISS source to pull.
     *
     * @return array{processed: integer, skipped: integer, cursor: string|null} The sweep outcome.
     *
     * @spec openspec/specs/kiss-kcc-bridge/spec.md
     */
    public function pullSource(ObjectEntity $source): array
    {
        $data          = $source->getObject();
        $configuration = ($data['configuration'] ?? []);
        $provider      = $this->resolveProvider(configuration: $configuration);

        $since    = ($configuration['cursor']['lastRegistratiedatum'] ?? null);
        $pageSize = (int) ($configuration['pageSize'] ?? self::DEFAULT_PAGE_SIZE);
        if ($pageSize < 1) {
            $pageSize = self::DEFAULT_PAGE_SIZE;
        }

        $page = $provider->listKlantcontacten(
            sourceConfiguration: $configuration,
            since: $since,
            pageSize: $pageSize
        );

        $processed = 0;
        $skipped   = 0;
        foreach ($page['items'] as $item) {
            try {
                $this->upsertKlantcontact(item: $item, direction: 'pulled', sourceApp: null);
                $processed++;
            } catch (Throwable $exception) {
                $skipped++;
                $this->logger->warning(
                    $this->l->t('One klantcontact failed to persist; skipped, sweep continues'),
                    [
                        'kissId'    => ($item['uuid'] ?? null),
                        'exception' => $exception->getMessage(),
                    ]
                );
            }
        }//end foreach

        $nextCursor = $page['nextCursor'];
        if ($nextCursor !== null) {
            // Cursor advances to the latest source-side registratiedatum seen
            // in this page REGARDLESS of individual persist failures — a page
            // that is mostly successful must not be re-pulled in full on the
            // next sweep. See design.md "Cursor semantics".
            $configuration['cursor'] = [
                'lastRegistratiedatum' => $nextCursor,
                'lastSyncAt'           => (new DateTime())->format('c'),
            ];
            $data['configuration']   = $configuration;

            $this->objectService->saveObject(
                object: $data,
                register: self::REGISTER,
                schema: self::SCHEMA_SOURCE,
                uuid: $source->getUuid()
            );
        }

        return ['processed' => $processed, 'skipped' => $skipped, 'cursor' => $nextCursor];

    }//end pullSource()

    /**
     * Register a klantcontact in KISS on behalf of a sibling app and link it
     * to a case (onderwerpobject), persisting a local mirror record.
     *
     * @param array $input The push payload: `onderwerp`, `kanaal`, `tekst`, `plaatsgevondenOp`,
     *                     `indicatieContactGelukt`, `taal`, `betrokkene` (optional),
     *                     `sourceApp`, `caseReference` (optional), `caseObjectType` (optional,
     *                     default `zaak`).
     *
     * @return array{id: string, localUuid: string} The KISS-assigned klantcontact id and local record uuid.
     *
     * @throws KissProviderException When no active KISS source is configured, or KISS rejects the request.
     *
     * @spec openspec/specs/kiss-kcc-bridge/spec.md
     */
    public function pushKlantcontact(array $input): array
    {
        $source        = $this->resolveActiveSource();
        $configuration = ($source->getObject()['configuration'] ?? []);
        $provider      = $this->resolveProvider(configuration: $configuration);

        $payload = [
            'onderwerp'              => (string) ($input['onderwerp'] ?? ''),
            'kanaal'                 => (string) ($input['kanaal'] ?? ''),
            'tekst'                  => (string) ($input['tekst'] ?? ''),
            'plaatsgevondenOp'       => (string) ($input['plaatsgevondenOp'] ?? (new DateTime())->format('c')),
            'indicatieContactGelukt' => (bool) ($input['indicatieContactGelukt'] ?? true),
            'taal'                   => (string) ($input['taal'] ?? 'nl'),
        ];

        $betrokkene = ($input['betrokkene'] ?? null);
        if (is_array($betrokkene) === true && $betrokkene !== []) {
            $payload['betrokkene'] = $betrokkene;
        }

        $kissId = $provider->createKlantcontact(sourceConfiguration: $configuration, payload: $payload);

        $caseReference     = (string) ($input['caseReference'] ?? '');
        $caseObjectType    = (string) ($input['caseObjectType'] ?? KlantinteractiesClient::DEFAULT_CASE_OBJECT_TYPE);
        $onderwerpobjecten = [];
        if ($caseReference !== '') {
            $provider->linkOnderwerpobject(
                sourceConfiguration: $configuration,
                klantcontactId: $kissId,
                caseReference: $caseReference,
                caseObjectType: $caseObjectType
            );
            $onderwerpobjecten[] = [
                'onderwerpobjectidentificator' => [
                    'objectId'       => $caseReference,
                    'codeObjecttype' => $caseObjectType,
                ],
            ];
        }

        $sourceApp = null;
        if (isset($input['sourceApp']) === true) {
            $sourceApp = (string) $input['sourceApp'];
        }

        $item = $payload;
        unset($item['betrokkene']);
        $item['uuid'] = $kissId;
        $item['registratiedatum'] = (new DateTime())->format('c');
        $item['betrokkenen']      = [];
        if ($betrokkene !== null) {
            $item['betrokkenen'] = [$betrokkene];
        }

        $item['onderwerpobjecten'] = $onderwerpobjecten;

        $saved = $this->upsertKlantcontact(item: $item, direction: 'pushed', sourceApp: $sourceApp);

        return ['id' => $kissId, 'localUuid' => $saved->getUuid()];

    }//end pushKlantcontact()

    /**
     * Resolve the single active KISS source (`type=kiss`, `isEnabled=true`).
     *
     * @return ObjectEntity The resolved source.
     *
     * @throws KissProviderException When no active KISS source is configured.
     *
     * @spec openspec/specs/kiss-kcc-bridge/spec.md
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
            throw new KissProviderException(
                message: 'No active KISS source is configured (register "openconnector", schema "source", '
                    .'type "kiss", isEnabled=true). Configure one before using the KISS bridge.'
            );
        }

        return $results[0];

    }//end resolveActiveSource()

    /**
     * Select the provider binding named by `configuration.provider` (default `log`).
     *
     * @param array $configuration The KISS source's `configuration` object.
     *
     * @return KlantinteractiesProviderInterface The resolved provider binding.
     *
     * @spec openspec/specs/kiss-kcc-bridge/spec.md
     */
    public function resolveProvider(array $configuration): KlantinteractiesProviderInterface
    {
        $provider = ($configuration['provider'] ?? 'log');
        if ($provider === 'rest') {
            return $this->restProvider;
        }

        return $this->logProvider;

    }//end resolveProvider()

    /**
     * Upsert a `kiss_klantcontact` record keyed by the KISS `uuid` — a
     * redelivered/re-pulled klantcontact updates the existing local record
     * in place (idempotent "new or changed" semantics) rather than
     * duplicating it.
     *
     * @param array       $item      The raw klantcontact item (KISS shape: `uuid`, `onderwerp`, `kanaal`,
     *                               `tekst`, `registratiedatum`, `betrokkenen`, `onderwerpobjecten`, ...).
     * @param string      $direction Either `pulled` (from KISS) or `pushed` (to KISS, mirrored locally).
     * @param string|null $sourceApp The producing sibling app slug (push path only).
     *
     * @return ObjectEntity The saved local record.
     *
     * @spec openspec/specs/kiss-kcc-bridge/spec.md
     */
    private function upsertKlantcontact(array $item, string $direction, ?string $sourceApp): ObjectEntity
    {
        $kissId = (string) ($item['uuid'] ?? '');
        if ($kissId === '') {
            throw new KissProviderException(message: 'A klantcontact item without a `uuid` cannot be persisted.');
        }

        $onderwerpobjecten = (array) ($item['onderwerpobjecten'] ?? []);
        $caseMapping       = $this->extractCaseReference(onderwerpobjecten: $onderwerpobjecten);

        $record = [
            'kissId'                 => $kissId,
            'nummer'                 => (string) ($item['nummer'] ?? ''),
            'kanaal'                 => (string) ($item['kanaal'] ?? ''),
            'onderwerp'              => (string) ($item['onderwerp'] ?? ''),
            'tekst'                  => (string) ($item['tekst'] ?? ''),
            'indicatieContactGelukt' => (bool) ($item['indicatieContactGelukt'] ?? true),
            'taal'                   => (string) ($item['taal'] ?? ''),
            'plaatsgevondenOp'       => (string) ($item['plaatsgevondenOp'] ?? ''),
            'registratiedatum'       => (string) ($item['registratiedatum'] ?? ''),
            'betrokkenen'            => $this->redactBsnIdentifiers(betrokkenen: (array) ($item['betrokkenen'] ?? [])),
            'onderwerpobjecten'      => $onderwerpobjecten,
            'caseReference'          => $caseMapping['reference'],
            'caseObjectType'         => $caseMapping['objectType'],
            'direction'              => $direction,
            'sourceApp'              => $sourceApp,
            'syncedAt'               => (new DateTime())->format('c'),
        ];

        $existing = $this->findByKissId(kissId: $kissId);
        if ($existing !== null) {
            return $this->objectService->saveObject(
                object: $record,
                register: self::REGISTER,
                schema: self::SCHEMA_KLANTCONTACT,
                uuid: $existing->getUuid()
            );
        }

        return $this->objectService->saveObject(
            object: $record,
            register: self::REGISTER,
            schema: self::SCHEMA_KLANTCONTACT
        );

    }//end upsertKlantcontact()

    /**
     * Find an existing local `kiss_klantcontact` record by its KISS `kissId`.
     *
     * @param string $kissId The KISS-assigned klantcontact id.
     *
     * @return ObjectEntity|null The existing record, or null when none matches.
     */
    private function findByKissId(string $kissId): ?ObjectEntity
    {
        $matches = $this->objectService->findAll(
            config: [
                'filters' => [
                    'register' => self::REGISTER,
                    'schema'   => self::SCHEMA_KLANTCONTACT,
                    'kissId'   => $kissId,
                ],
                'limit'   => 1,
            ]
        );
        $results = ($matches['results'] ?? $matches);

        if (empty($results) === true) {
            return null;
        }

        return $results[0];

    }//end findByKissId()

    /**
     * Extract a bare case UUID/identificatie from a klantcontact's expanded
     * onderwerpobjecten — the first entry whose `codeObjecttype` matches the
     * case/zaak marker (case-insensitive substring, see design.md).
     *
     * @param array $onderwerpobjecten The expanded onderwerpobjecten array (may be empty).
     *
     * @return array{reference: string|null, objectType: string|null} The extracted mapping, both null
     *         when no onderwerpobject is present or none matches the case/zaak marker (a "foreign"
     *         onderwerpobjectidentificator — e.g. linking to a different object type — is left
     *         unmapped, not misattributed as a case).
     *
     * @spec openspec/specs/kiss-kcc-bridge/spec.md
     */
    private function extractCaseReference(array $onderwerpobjecten): array
    {
        foreach ($onderwerpobjecten as $onderwerpobject) {
            $identificator  = (array) ($onderwerpobject['onderwerpobjectidentificator'] ?? []);
            $objectId       = (string) ($identificator['objectId'] ?? '');
            $codeObjecttype = (string) ($identificator['codeObjecttype'] ?? '');

            if ($objectId === '' || $codeObjecttype === '') {
                continue;
            }

            if (str_contains(strtolower($codeObjecttype), self::CASE_OBJECT_TYPE_MARKER) === true) {
                return ['reference' => $objectId, 'objectType' => $codeObjecttype];
            }
        }

        return ['reference' => null, 'objectType' => null];

    }//end extractCaseReference()

    /**
     * Hash any raw BSN found in a betrokkenen array's `partijIdentificator`
     * before storage — consistent with this app's `AvgBsnPolicyRule`
     * precedent (never persist a raw citizen service number).
     *
     * @param array $betrokkenen The raw betrokkenen array (KISS shape).
     *
     * @return array The betrokkenen array with any `bsn`-typed identifier value SHA-256-hashed.
     *
     * @spec openspec/specs/kiss-kcc-bridge/spec.md
     */
    private function redactBsnIdentifiers(array $betrokkenen): array
    {
        foreach ($betrokkenen as $index => $betrokkene) {
            $identificator = ($betrokkene['partijIdentificator'] ?? null);
            if (is_array($identificator) === false) {
                continue;
            }

            $code  = strtolower((string) ($identificator['codeSoortObjectId'] ?? ''));
            $value = (string) ($identificator['objectId'] ?? '');
            if ($code === self::BSN_CODE_SOORT_OBJECT_ID && $value !== '') {
                $identificator['objectId'] = hash('sha256', $value);
                $betrokkenen[$index]['partijIdentificator'] = $identificator;
            }
        }

        return $betrokkenen;

    }//end redactBsnIdentifiers()
}//end class

<?php

/**
 * Catalog Registry Service
 *
 * Single PHP-side source of truth for the Catalog page's card entries
 * (REQ-003). Assembles catalog descriptors from three existing sources —
 * never inventing an entry:
 *   (a) OpenRegister's IntegrationRegistry (the 4+ registered category
 *       adapters, e.g. Azure Virtual Desktop, SharePoint Online,
 *       Microsoft 365, S3),
 *   (b) a small static descriptor list for built-in adapters not yet
 *       registered there (PDOK, Digikoppeling, Berichtenbox, DSO),
 *   (c) the `register.d/*-source.json` seed fragments (BRP, KVK, xWiki,
 *       messaging channels, OpenCorporates) for seeded source templates.
 *
 * `lib/Repair/MaterializeCatalogItems.php` calls collect() + resolveStatus()
 * to upsert one `catalog_item` OpenRegister object per entry.
 * `CatalogController::status()` calls resolveStatus() again on a single
 * entry for a live re-check when the detail dialog opens (REQ-002).
 *
 * @category Service
 * @package  OCA\OpenConnector\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/specs/connector-catalog/spec.md#scenario-materialization-is-idempotent
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Assembles and resolves live status for Catalog entries.
 *
 * @spec openspec/specs/connector-catalog/spec.md#scenario-materialization-is-idempotent
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 */
class CatalogRegistryService
{
    /**
     * Directory holding the register.d seed fragments this service reads
     * for seeded source templates.
     *
     * @var string
     */
    private const FRAGMENT_DIR = __DIR__.'/../Settings/register.d';

    /**
     * Human-readable category labels keyed by the source schema's free-form
     * `type` field (lib/Settings/openconnector_register.json's documented
     * vocabulary: api, database, file, soap, dso, peppol, psd2, sms, payment).
     *
     * @var array<string,string>
     */
    private const TYPE_CATEGORY_LABELS = [
        'api'      => 'API integrations',
        'database' => 'Data infrastructure',
        'file'     => 'File integrations',
        'soap'     => 'SOAP integrations',
        'dso'      => 'DSO / Omgevingsloket',
        'peppol'   => 'Peppol / e-invoicing',
        'psd2'     => 'Payments / open banking',
        'sms'      => 'Messaging',
        'payment'  => 'Payments / open banking',
    ];

    /**
     * Per-slug category overrides for the seeded sources whose free-form
     * `type` field ("api" across the board today) is too coarse to be a
     * useful catalog facet. Keyed by the seed fragment's source slug; a
     * seed not listed here falls back to TYPE_CATEGORY_LABELS.
     *
     * @var array<string,string>
     */
    private const SLUG_CATEGORY_OVERRIDES = [
        'brp-haalcentraal'   => 'Government registers',
        'kvk'                => 'Government registers',
        'opencorporates'     => 'Company data',
        'xwiki'              => 'Document / CMS',
        'cmcom-sms'          => 'Messaging',
        'messagebird-sms'    => 'Messaging',
        'twilio-sms'         => 'Messaging',
        'whatsapp-bsp'       => 'Messaging',
        'whatsapp-cloud-api' => 'Messaging',
    ];

    /**
     * Category overrides for IntegrationRegistry providers whose getGroup()
     * is null (all four category adapters today), keyed by provider id.
     * An unknown provider falls back to its group (when set) or the
     * generic 'Integrations' bucket — REQ-003's "5th provider appears with
     * no code change" scenario stays intact.
     *
     * @var array<string,string>
     */
    private const PROVIDER_CATEGORY_OVERRIDES = [
        'azure-virtual-desktop' => 'Endpoint / Workspace',
        'sharepoint-online'     => 'Document / CMS',
        'microsoft-365'         => 'SaaS productivity',
        'data-infra-s3'         => 'Data infrastructure',
    ];

    /**
     * Constructor.
     *
     * @param IntegrationRegistry $integrationRegistry OR's registry of IntegrationProvider adapters.
     * @param OrObjectService     $orObjectService     OR object service, used to resolve seeded Source objects.
     * @param IAppConfig          $appConfig           App config, used to resolve flag-gated mechanism status.
     * @param LoggerInterface     $logger              Logger for malformed seed-fragment warnings.
     */
    public function __construct(
        private readonly IntegrationRegistry $integrationRegistry,
        private readonly OrObjectService $orObjectService,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Assemble every catalog entry from the three sources (REQ-003).
     *
     * Each entry is a plain array matching the `catalog_item` schema's
     * property names (minus `status`, which callers compute separately via
     * {@see resolveStatus()} since it must be re-checkable live).
     *
     * @return array<int, array<string,mixed>>
     *
     * @spec openspec/specs/connector-catalog/spec.md#scenario-materialization-is-idempotent
     */
    public function collect(): array
    {
        $entries = [];

        foreach ($this->collectFromIntegrationRegistry() as $entry) {
            $entries[] = $entry;
        }

        foreach ($this->collectStaticDescriptors() as $entry) {
            $entries[] = $entry;
        }

        foreach ($this->collectFromSeedFragments() as $entry) {
            $entries[] = $entry;
        }

        return $entries;

    }//end collect()

    /**
     * (a) Read every provider registered with OR's IntegrationRegistry.
     *
     * @return array<int, array<string,mixed>>
     *
     * @spec openspec/specs/connector-catalog/spec.md#scenario-materialization-is-idempotent
     */
    private function collectFromIntegrationRegistry(): array
    {
        $entries = [];
        foreach ($this->integrationRegistry->list() as $provider) {
            $group    = $provider->getGroup();
            $fallback = 'Integrations';
            if ($group !== null && $group !== '') {
                $fallback = ucfirst($group).' integrations';
            }

            $category = (self::PROVIDER_CATEGORY_OVERRIDES[$provider->getId()] ?? $fallback);

            $entries[] = [
                'slug'               => 'adapter:'.$provider->getId(),
                'name'               => $provider->getLabel(),
                'description'        => sprintf(
                    'OpenRegister integration adapter (%s).',
                    $provider->getId()
                ),
                'category'           => $category,
                'kind'               => 'adapter',
                'mechanism'          => 'always-available',
                'flagKey'            => '',
                'sourceTemplateSlug' => '',
                'standards'          => [],
                'icon'               => $provider->getIcon(),
                // Adapter items reflect the provider's own isEnabled() as
                // the initial/fallback status; resolveStatus() re-derives
                // this live for 'always-available' mechanism as 'available'
                // unless a future mechanism variant is added.
                '_fallbackEnabled'   => $provider->isEnabled(),
            ];
        }//end foreach

        return $entries;

    }//end collectFromIntegrationRegistry()

    /**
     * (b) Static descriptor list for built-in adapters not (yet) registered
     * with OR's IntegrationRegistry — PDOK, Digikoppeling, Berichtenbox, DSO.
     *
     * Deliberately NOT promoted into full IntegrationProviders here (see
     * design.md Trade-offs) — this list only needs to feed the catalog.
     *
     * @return array<int, array<string,mixed>>
     */
    private function collectStaticDescriptors(): array
    {
        return [
            [
                'slug'               => 'adapter:pdok',
                'name'               => 'PDOK Locatieserver & kaartlagen',
                'description'        => 'Dutch national geo-data platform (Publieke Dienstverlening Op de Kaart) — address/location suggest+lookup, '
                    .'WMS/WFS map layers. Ships with a deterministic mock flavour so the leaf is demonstrably functional without a live '
                    .'PDOK dependency; flip `pdok.feature_flag` to reach the real api.pdok.nl / service.pdok.nl endpoints.',
                'category'           => 'Geo / Maps',
                'kind'               => 'adapter',
                'mechanism'          => 'flag-gated',
                'flagKey'            => 'pdok.feature_flag',
                'sourceTemplateSlug' => '',
                'standards'          => ['OGC WMS', 'OGC WFS', 'PDOK Locatieserver'],
                'icon'               => 'MapMarkerOutline',
            ],
            [
                'slug'               => 'adapter:digikoppeling',
                'name'               => 'Digikoppeling',
                'description'        => 'Dutch government messaging standard (WUS profile, ebMS2 reliable messaging, WS-Security signing, '
                    .'PKIoverheid credentials, Grote Berichten large-message reference transfer) for secure inter-government data exchange.',
                'category'           => 'Government messaging',
                'kind'               => 'adapter',
                'mechanism'          => 'always-available',
                'flagKey'            => '',
                'sourceTemplateSlug' => '',
                'standards'          => ['Digikoppeling WUS', 'ebMS2', 'PKIoverheid'],
                'icon'               => 'SwapHorizontal',
            ],
            [
                'slug'               => 'adapter:berichtenbox',
                'name'               => 'Berichtenbox (Logius)',
                'description'        => 'Logius Berichtenbox voor Bedrijven (BBK 1.7) — the government-to-business message box bridging '
                    .'MijnOverheid-style delivery. Ships mock by default; flip `logius.berichtenbox.feature_flag` to activate the live HTTP flavour.',
                'category'           => 'Government messaging',
                'kind'               => 'adapter',
                'mechanism'          => 'flag-gated',
                'flagKey'            => 'logius.berichtenbox.feature_flag',
                'sourceTemplateSlug' => '',
                'standards'          => ['BBK 1.7'],
                'icon'               => 'EmailOutline',
            ],
            [
                'slug'               => 'adapter:dso',
                'name'               => 'DSO / Omgevingsloket STAM',
                'description'        => 'Digitaal Stelsel Omgevingswet (DSO) STAM koppelvlak — receives Omgevingsloket verzoeken with '
                    .'PKIoverheid certificate-chain / HMAC shared-secret signature verification.',
                'category'           => 'DSO / Omgevingsloket',
                'kind'               => 'adapter',
                'mechanism'          => 'always-available',
                'flagKey'            => '',
                'sourceTemplateSlug' => '',
                'standards'          => ['STAM'],
                'icon'               => 'CityVariantOutline',
            ],
        ];

    }//end collectStaticDescriptors()

    /**
     * (c) One entry per `register.d/*.json` seed fragment that ships a
     * `source` object — the seeded source templates (BRP, KVK, xWiki,
     * messaging channels, OpenCorporates, …).
     *
     * Content-inspected (not filename-pattern matched) so a future fragment
     * naming itself differently is still picked up as long as it seeds a
     * `source` object — REQ-003's "no hardcoded entry" guarantee extends to
     * fragment naming, not just registry membership.
     *
     * @return array<int, array<string,mixed>>
     */
    private function collectFromSeedFragments(): array
    {
        $entries = [];

        if (is_dir(self::FRAGMENT_DIR) === false) {
            return $entries;
        }

        $files = glob(self::FRAGMENT_DIR.'/*.json');
        if ($files === false) {
            return $entries;
        }

        sort($files);

        foreach ($files as $file) {
            $raw = file_get_contents($file);
            if ($raw === false) {
                continue;
            }

            $data = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE || is_array($data) === false) {
                $this->logger->warning('[openconnector] CatalogRegistryService: skipping malformed seed fragment '.basename($file));
                continue;
            }

            $objects = ($data['components']['objects'] ?? []);
            if (is_array($objects) === false) {
                continue;
            }

            foreach ($objects as $object) {
                $self = ($object['@self'] ?? []);
                if (($self['schema'] ?? '') !== 'source') {
                    // Not a Source seed (e.g. a schema-defining fragment
                    // like eudi-wallet-credential-issuance.json) — skip.
                    continue;
                }

                $slug = (string) ($self['slug'] ?? '');
                if ($slug === '') {
                    continue;
                }

                $type     = (string) ($object['type'] ?? 'api');
                $category = (self::SLUG_CATEGORY_OVERRIDES[$slug] ?? self::TYPE_CATEGORY_LABELS[$type] ?? 'Integrations');

                $entries[] = [
                    'slug'               => 'source-template:'.$slug,
                    'name'               => (string) ($object['name'] ?? $slug),
                    'description'        => (string) ($object['description'] ?? ''),
                    'category'           => $category,
                    'kind'               => 'source-template',
                    // Design decision (proposal.md Risk 2): every register.d
                    // seed source uses the mock/isEnabled mechanism, distinct
                    // from the flag-gated container-level PDOK/Berichtenbox
                    // adapters above.
                    'mechanism'          => 'mock-seeded',
                    'flagKey'            => '',
                    'sourceTemplateSlug' => $slug,
                    'standards'          => $this->standardsForType(type: $type),
                    'icon'               => $this->iconForType(type: $type),
                ];
            }//end foreach
        }//end foreach

        return $entries;

    }//end collectFromSeedFragments()

    /**
     * Small standards vocabulary lookup keyed by the source's free-form
     * `type` field — a best-effort label, not an authoritative registry.
     *
     * @param string $type The source schema's `type` field value.
     *
     * @return array<int,string>
     */
    private function standardsForType(string $type): array
    {
        return match ($type) {
            'sms' => ['SMS Gateway'],
            'soap' => ['SOAP'],
            'dso' => ['STAM'],
            'peppol' => ['Peppol'],
            'psd2' => ['PSD2 AIS'],
            'payment' => ['Payment API'],
            default => ['REST API'],
        };

    }//end standardsForType()

    /**
     * MDI icon lookup keyed by the source's free-form `type` field.
     *
     * @param string $type The source schema's `type` field value.
     *
     * @return string
     */
    private function iconForType(string $type): string
    {
        return match ($type) {
            'sms' => 'MessageTextOutline',
            'soap' => 'CogOutline',
            'dso' => 'CityVariantOutline',
            'peppol' => 'FileDocumentOutline',
            'psd2', 'payment' => 'CreditCardOutline',
            default => 'DatabaseArrowLeftOutline',
        };

    }//end iconForType()

    /**
     * Re-read a register.d seed fragment's full raw `source` object payload
     * for a given slug — used by `CatalogController::instantiate()` to
     * create a brand-new Source when a mock-seeded template's Source object
     * doesn't exist yet (e.g. it was deleted, or a future seed ships with
     * `isEnabled: false` by default).
     *
     * Unlike {@see collectFromSeedFragments()}, this returns the FULL
     * object body (all configuration fields), not just the catalog display
     * fields, since it feeds a real `saveObject()` call.
     *
     * @param string $slug The source template's slug (e.g. "brp-haalcentraal").
     *
     * @return array<string,mixed>|null The raw source object payload (minus `@self`), or null when not found.
     *
     * @spec openspec/specs/connector-catalog/spec.md#scenario-instantiate-action-creates-a-source-from-a-seeded-template
     */
    public function findSeedSourcePayload(string $slug): ?array
    {
        if (is_dir(self::FRAGMENT_DIR) === false) {
            return null;
        }

        $files = glob(self::FRAGMENT_DIR.'/*.json');
        if ($files === false) {
            return null;
        }

        foreach ($files as $file) {
            $raw = file_get_contents($file);
            if ($raw === false) {
                continue;
            }

            $data = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE || is_array($data) === false) {
                continue;
            }

            $objects = ($data['components']['objects'] ?? []);
            if (is_array($objects) === false) {
                continue;
            }

            foreach ($objects as $object) {
                $self = ($object['@self'] ?? []);
                if (($self['schema'] ?? '') === 'source' && ($self['slug'] ?? '') === $slug) {
                    $payload         = $object;
                    $payload['slug'] = $slug;
                    unset($payload['@self']);
                    return $payload;
                }
            }
        }//end foreach

        return null;

    }//end findSeedSourcePayload()

    /**
     * Live-resolve an entry's status, given its mechanism/flagKey/
     * sourceTemplateSlug (REQ-002's per-item live re-check, and the
     * materialisation-time cache write).
     *
     * @param array<string,mixed> $entry A collect()-shaped entry (or the
     *                                   equivalent fields read back off a
     *                                   materialised `catalog_item` object).
     *
     * @return string One of 'available' | 'dormant'.
     *
     * @spec openspec/specs/connector-catalog/spec.md#scenario-status-badge-reflects-a-flag-gated-dormant-item
     * @spec openspec/specs/connector-catalog/spec.md#scenario-status-badge-reflects-a-mock-seeded-available-item
     */
    public function resolveStatus(array $entry): string
    {
        $mechanism = (string) ($entry['mechanism'] ?? 'always-available');

        if ($mechanism === 'flag-gated') {
            $flagKey = (string) ($entry['flagKey'] ?? '');
            if ($flagKey === '') {
                return 'dormant';
            }

            $raw = $this->appConfig->getValueString('openconnector', $flagKey, '0');
            if ($raw === '1' || strtolower($raw) === 'true') {
                return 'available';
            }

            return 'dormant';
        }

        if ($mechanism === 'mock-seeded') {
            $slug = (string) ($entry['sourceTemplateSlug'] ?? '');
            if ($slug === '') {
                return 'dormant';
            }

            $source = $this->findSourceBySlug(slug: $slug);
            if ($source === null) {
                return 'dormant';
            }

            $data = $source->getObject();
            // Mock mode is NOT dormant — the source is reachable, just
            // returning canned data (REQ-001 scenario).
            if (($data['isEnabled'] ?? false) === true) {
                return 'available';
            }

            return 'dormant';
        }

        // 'always-available' — fall back to the collector's own
        // isEnabled() read when present (IntegrationRegistry providers),
        // otherwise treat as always available.
        if (array_key_exists('_fallbackEnabled', $entry) === true) {
            if ($entry['_fallbackEnabled'] === true) {
                return 'available';
            }

            return 'dormant';
        }

        return 'available';

    }//end resolveStatus()

    /**
     * Find a `source` OpenRegister object by its slug.
     *
     * @param string $slug The source's slug (e.g. "brp-haalcentraal").
     *
     * @return ObjectEntity|null
     */
    private function findSourceBySlug(string $slug): ?ObjectEntity
    {
        $result = $this->orObjectService->findAll(
            config: ['filters' => ['register' => 'openconnector', 'schema' => 'source', 'slug' => $slug]]
        );
        $items  = ($result['results'] ?? $result);
        foreach ($items as $item) {
            if ($item instanceof ObjectEntity === false) {
                continue;
            }

            $data = $item->getObject();
            if (($data['slug'] ?? '') === $slug) {
                return $item;
            }
        }

        return null;

    }//end findSourceBySlug()
}//end class

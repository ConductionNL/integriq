<?php

/**
 * Catalog Registry Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/connector-catalog/spec.md#scenario-materialization-is-idempotent
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\Integriq\Service\Catalog\ConnectorTemplateLibrary;

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
class CatalogRegistryService implements CatalogRegistryServiceInterface {
	/**
	 * Directory holding the register.d seed fragments this service reads
	 * for seeded source templates.
	 *
	 * @var string
	 */
	private const FRAGMENT_DIR = __DIR__ . '/../Settings/register.d';

	/**
	 * The connector template library: listed in the Store, never imported
	 * as source objects (connectors-catalogue-expansion design D1).
	 */
	private const TEMPLATE_DIR = __DIR__ . '/../Settings/connector-templates';

	/**
	 * Seeded sources that are not connectors: promotion targets seeded by
	 * environments-and-promotion.json (design D4).
	 */
	private const PLACEHOLDER_SLUG_PREFIX = 'environment-';

	/**
	 * The connector template library this instance reads.
	 *
	 * @var ConnectorTemplateLibrary
	 */
	private readonly ConnectorTemplateLibrary $templates;

	/**
	 * Human-readable category labels keyed by the source schema's free-form
	 * `type` field (lib/Settings/integriq_register.json's documented
	 * vocabulary: api, database, file, soap, dso, peppol, psd2, sms, payment).
	 *
	 * @var array<string,string>
	 */
	private const TYPE_CATEGORY_LABELS = [
		'api' => 'API integrations',
		'database' => 'Data infrastructure',
		'file' => 'File integrations',
		'soap' => 'SOAP integrations',
		'dso' => 'DSO / Omgevingsloket',
		'peppol' => 'Peppol / e-invoicing',
		'psd2' => 'Payments / open banking',
		'sms' => 'Messaging',
		'payment' => 'Payments / open banking',
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
		'brp-haalcentraal' => 'Government registers',
		'kvk' => 'Government registers',
		'opencorporates' => 'Company data',
		'xwiki' => 'Document / CMS',
		// Service desks with application records (connectors-service-desk-templates).
		'topdesk' => 'Service management',
		'servicenow' => 'Service management',
		'glpi' => 'Service management',
		'cmcom-sms' => 'Messaging',
		'messagebird-sms' => 'Messaging',
		'twilio-sms' => 'Messaging',
		'whatsapp-bsp' => 'Messaging',
		'whatsapp-cloud-api' => 'Messaging',
		'smartdocuments' => 'Document generation',
		'xential' => 'Document generation',
		// SLO curriculum open data (slo-kerndoelen-import): kerndoelen, examenprogramma's.
		'slo-curriculum' => 'Education data',
		// Text translation for sibling apps (connectors-translation-service).
		'deepl-translation' => 'Language',
		'libretranslate' => 'Language',
		// GitHub for the publiccode harvest (sources-github-publiccode).
		'github-api' => 'Code hosting',
		'github-raw' => 'Code hosting',
		// Web shops whose orders go to shillinq (connectors-webshop-orders D4).
		'woocommerce-shop' => 'Commerce',
		'shopify-shop' => 'Commerce',
		'lightspeed-shop' => 'Commerce',
		// Bookkeeping connectors in the categories shillinq's integrations page filters on
		// (platform-bookkeeping-catalogue-items D2). These names are a contract: see BOOKKEEPING_CATEGORIES.
		'peppol-access-point' => 'E-invoicing',
		'psd2-bank-aggregator' => 'Bank',
		'corporate-card-feed' => 'Bank',
		'mollie-payments' => 'Payments',
		'digipoort-sbr' => 'Tax filing',
		// The ECB euro reference rates, credential-free (sources-ecb-exchange-rates D4).
		'ecb-eurofxref-daily' => 'Bank',
	];

	/**
	 * The categories shillinq's integrations page filters integriq's catalogue on
	 * (shillinq platform-integration-catalogue D1). Every bookkeeping connector
	 * template carries one of them.
	 *
	 * @var list<string>
	 *
	 * @spec openspec/changes/platform-bookkeeping-catalogue-items/specs/connector-catalog/spec.md#requirement-bookkeeping-connectors-are-catalogue-templates-in-bookkeeping-categories-req-cat-bk-001
	 */
	public const BOOKKEEPING_CATEGORIES = ['Bank', 'Payments', 'E-invoicing', 'Tax filing', 'Commerce', 'Payroll'];

	/**
	 * The Objecten API and Objecttypen API facade as one adapter card
	 * (objecten-api-facade Task 6). The routes exist on every install and
	 * answer once an administrator creates a token, so it is always available.
	 * A constant rather than a method: the class sits at its complexity limit.
	 *
	 * @var array<string,mixed>
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md#requirement-a-leaf-app-declares-the-objecttypes-it-publishes-req-oaf-006
	 */
	private const OBJECTEN_DESCRIPTOR = [
		'slug' => 'adapter:objecten-api',
		'name' => 'Objecten API and Objecttypen API',
		'description' => 'Serves the VNG Objecten API and Objecttypen API (version 2) over your registers, at /api/v2/objects '
			. 'and /api/v2/objecttypes. An objecttype is a schema you name by configuration, or one a leaf app declares in '
			. 'its own lib/Settings/objecttypes.json. A caller sends Authorization: Token, the token names read or read_write '
			. 'per objecttype, and every request then runs as the token\'s user, so the register\'s own rights still apply. '
			. 'It answers nobody until an administrator creates a token.',
		'category' => 'Common Ground APIs',
		'kind' => 'adapter',
		'mechanism' => 'always-available',
		'flagKey' => '',
		'sourceTemplateSlug' => '',
		'standards' => ['Objecten API 2', 'Objecttypen API 2'],
		'icon' => 'Api',
	];

	/**
	 * How a vendor document-generation adapter ships and what a live source of it needs.
	 *
	 * @var string
	 */
	private const DOCUMENT_GENERATION_NOTE = 'Ships in mock mode with fixture templates, so the path works before a licence exists. '
		. 'A live source needs a baseUrl and a '
		. '`credentialRef` held by the OpenRegister credential broker; an API key written into the source is refused.';

	/**
	 * The built-in adapters not registered with OR's IntegrationRegistry, as
	 * catalog descriptors (see {@see collectStaticDescriptors()}).
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private const STATIC_DESCRIPTORS = [
		[
			'slug' => 'adapter:pdok',
			'name' => 'PDOK Locatieserver & kaartlagen',
			'description' => 'Dutch national geo-data platform (Publieke Dienstverlening Op de Kaart) — address/location suggest+lookup, '
				. 'WMS/WFS map layers. Ships with a deterministic mock flavour so the leaf is demonstrably functional without a live '
				. 'PDOK dependency; flip `pdok.feature_flag` to reach the real api.pdok.nl / service.pdok.nl endpoints.',
			'category' => 'Geo / Maps',
			'kind' => 'adapter',
			'mechanism' => 'flag-gated',
			'flagKey' => 'pdok.feature_flag',
			'sourceTemplateSlug' => '',
			'standards' => ['OGC WMS', 'OGC WFS', 'PDOK Locatieserver'],
			'icon' => 'MapMarkerOutline',
		],
		[
			'slug' => 'adapter:digikoppeling',
			'name' => 'Digikoppeling',
			'description' => 'Dutch government messaging standard (WUS profile, ebMS2 reliable messaging, WS-Security signing, '
				. 'PKIoverheid credentials, Grote Berichten large-message reference transfer) for secure inter-government data exchange.',
			'category' => 'Government messaging',
			'kind' => 'adapter',
			'mechanism' => 'always-available',
			'flagKey' => '',
			'sourceTemplateSlug' => '',
			'standards' => ['Digikoppeling WUS', 'ebMS2', 'PKIoverheid'],
			'icon' => 'SwapHorizontal',
		],
		[
			'slug' => 'adapter:berichtenbox',
			'name' => 'Berichtenbox (Logius)',
			'description' => 'MijnOverheid Berichtenbox: letters to the message box a citizen reads in MijnOverheid. '
				. 'Letters go over Digikoppeling ebMS through an ebMS adapter you run; the subscription is checked over '
				. 'Digikoppeling WUS before every letter. It needs a Logius aansluiting, a PKIoverheid certificate with '
				. 'your OIN (uploaded under Administration settings, Integriq, and stored encrypted) and the CPA values '
				. 'Logius gives you. Ships mock while `logius.berichtenbox.feature_flag` is unset, and every send is then '
				. 'reported as simulated. With the flag set the mock is not served: a source that lacks a value is '
				. 'refused, naming each one. Logius reports delivered or not, never read.',
			'category' => 'Government messaging',
			'kind' => 'adapter',
			'mechanism' => 'flag-gated',
			'flagKey' => 'logius.berichtenbox.feature_flag',
			'sourceTemplateSlug' => '',
			'standards' => ['Digikoppeling ebMS2', 'Digikoppeling WUS', 'PKIoverheid'],
			'icon' => 'EmailOutline',
		],
		[
			'slug' => 'adapter:smartdocuments',
			'name' => 'SmartDocuments',
			'description' => 'Vendor document generation (SmartDocuments). Filinq calls it as a template backend (ADR-075); no case app talks to the vendor. '
				. self::DOCUMENT_GENERATION_NOTE,
			'category' => 'Document generation',
			'kind' => 'adapter',
			'mechanism' => 'mock-seeded',
			'flagKey' => '',
			'sourceTemplateSlug' => 'smartdocuments',
			'standards' => ['REST API'],
			'icon' => 'FileDocumentOutline',
		],
		[
			'slug' => 'adapter:xential',
			'name' => 'Xential',
			'description' => 'Vendor document generation (Xential). Filinq calls it as a template backend (ADR-075); no case app talks to the vendor. '
				. self::DOCUMENT_GENERATION_NOTE,
			'category' => 'Document generation',
			'kind' => 'adapter',
			'mechanism' => 'mock-seeded',
			'flagKey' => '',
			'sourceTemplateSlug' => 'xential',
			'standards' => ['REST API'],
			'icon' => 'FileDocumentOutline',
		],
		[
			'slug' => 'adapter:dso',
			'name' => 'DSO / Omgevingsloket STAM',
			'description' => 'Digitaal Stelsel Omgevingswet (DSO) STAM koppelvlak — receives Omgevingsloket verzoeken with '
				. 'PKIoverheid certificate-chain / HMAC shared-secret signature verification.',
			'category' => 'DSO / Omgevingsloket',
			'kind' => 'adapter',
			'mechanism' => 'always-available',
			'flagKey' => '',
			'sourceTemplateSlug' => '',
			'standards' => ['STAM'],
			'icon' => 'CityVariantOutline',
		],
		self::OBJECTEN_DESCRIPTOR,
		[
			'slug' => 'adapter:edurep',
			'name' => 'Edurep open lesmateriaal (Kennisnet)',
			'description' => 'Searches open learning material in Edurep, the free and keyless SRU search of Kennisnet over about 495,000 Dutch '
				. 'records from every sector (Wikiwijs, Schooltv, SURFsharekit and more). Each record is mapped to one shape with its makers, '
				. 'licence and access rights. learniq calls it through the LearningMaterialRequestedEvent. Live by default; set '
				. '`edurep.feature_flag` to 0 to serve recorded responses.',
			'category' => 'Education data',
			'kind' => 'adapter',
			'mechanism' => 'always-available',
			'flagKey' => '',
			'sourceTemplateSlug' => '',
			'standards' => ['SRU 1.2', 'IMS LOM 1.2', 'NL-LOM'],
			'icon' => 'BookOpenPageVariantOutline',
		],
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
		'sharepoint-online' => 'Document / CMS',
		'microsoft-365' => 'SaaS productivity',
		'data-infra-s3' => 'Data infrastructure',
	];

	/**
	 * Constructor.
	 *
	 * @param IntegrationRegistry $integrationRegistry OR's registry of IntegrationProvider adapters.
	 * @param OrObjectService $orObjectService OR object service, used to resolve seeded Source objects.
	 * @param IAppConfig $appConfig App config, used to resolve flag-gated mechanism status.
	 * @param LoggerInterface $logger Logger for malformed seed-fragment warnings.
	 * @param string|null $templateDir The template library to read; the shipped one when null.
	 */
	public function __construct(
		private readonly IntegrationRegistry $integrationRegistry,
		private readonly OrObjectService $orObjectService,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		?string $templateDir = null,
	) {
		$this->templates = new ConnectorTemplateLibrary(directory: ($templateDir ?? self::TEMPLATE_DIR));
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
	 * @spec openspec/specs/connector-catalog/spec.md#requirement-the-store-counts-only-real-connectors-once-each-req-ccx-004
	 */
	public function collect(): array {
		$withTier = static fn (string $tier): \Closure => static fn (array $entry): array => $entry + ['tier' => $tier];

		$adapters = array_map($withTier('adapter'), [...$this->collectFromIntegrationRegistry(), ...$this->collectStaticDescriptors()]);

		// A system with an adapter and a seeded source is listed once, as the
		// adapter; the seeded source is what its Instantiate enables (D4).
		$backedByAdapter = array_flip(array_filter(array_column($adapters, 'sourceTemplateSlug')));
		$seeded = array_filter(
			$this->collectFromSeedFragments(),
			static fn (array $entry): bool => isset($backedByAdapter[$entry['sourceTemplateSlug']]) === false
		);

		return [...$adapters, ...array_map($withTier('curated'), array_values($seeded)), ...$this->collectFromTemplates()];
	}//end collect()

	/**
	 * (d) One entry per template in the connector template library, which
	 * the Store lists and the register import never installs (design D1).
	 *
	 * @return array<int, array<string,mixed>>
	 *
	 * @spec openspec/specs/connector-catalog/spec.md#requirement-the-store-lists-templates-it-does-not-install-req-ccx-001
	 */
	private function collectFromTemplates(): array {
		return array_map(
			fn (array $card): array => ['icon' => $this->iconForType(type: (string)$card['sourceType'])] + array_diff_key($card, ['sourceType' => true]),
			$this->templates->cards()
		);
	}//end collectFromTemplates()

	/**
	 * (a) Read every provider registered with OR's IntegrationRegistry.
	 *
	 * @return array<int, array<string,mixed>>
	 *
	 * @spec openspec/specs/connector-catalog/spec.md#scenario-materialization-is-idempotent
	 */
	private function collectFromIntegrationRegistry(): array {
		$entries = [];
		foreach ($this->integrationRegistry->list() as $provider) {
			$group = $provider->getGroup();
			$fallback = 'Integrations';
			if ($group !== null && $group !== '') {
				$fallback = ucfirst($group) . ' integrations';
			}

			$category = (self::PROVIDER_CATEGORY_OVERRIDES[$provider->getId()] ?? $fallback);

			$entries[] = [
				'slug' => 'adapter:' . $provider->getId(),
				'name' => $provider->getLabel(),
				'description' => sprintf(
					'OpenRegister integration adapter (%s).',
					$provider->getId()
				),
				'category' => $category,
				'kind' => 'adapter',
				'mechanism' => 'always-available',
				'flagKey' => '',
				'sourceTemplateSlug' => '',
				'standards' => [],
				'icon' => $provider->getIcon(),
				// Adapter items reflect the provider's own isEnabled() as
				// the initial/fallback status; resolveStatus() re-derives
				// this live for 'always-available' mechanism as 'available'
				// unless a future mechanism variant is added.
				'_fallbackEnabled' => $provider->isEnabled(),
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
	private function collectStaticDescriptors(): array {
		return self::STATIC_DESCRIPTORS;

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
	private function collectFromSeedFragments(): array {
		$entries = [];
		$fragments = new SeedFragments(directory: self::FRAGMENT_DIR);
		$files = $fragments->files();
		sort($files);

		foreach ($files as $file) {
			$raw = file_get_contents($file);
			if ($raw === false) {
				continue;
			}

			$data = $fragments->decode(raw: $raw);
			if ($data === null) {
				$this->logger->warning('[integriq] CatalogRegistryService: skipping malformed seed fragment ' . basename($file));
				continue;
			}

			foreach ($fragments->objects(data: $data) as $object) {
				$entries = $this->withTemplateEntry(entries: $entries, object: $object);
			}
		}

		return $entries;
	}//end collectFromSeedFragments()

	/**
	 * The entries with the catalog entry of one seeded object added, when it is a real Source seed.
	 *
	 * @param array<int, array<string,mixed>> $entries The entries so far.
	 * @param mixed                           $object  One seeded object.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	private function withTemplateEntry(array $entries, mixed $object): array {
		$self = ($object['@self'] ?? []);
		if (($self['schema'] ?? '') !== 'source') {
			// Not a Source seed (e.g. a schema-defining fragment
			// like eudi-wallet-credential-issuance.json) — skip.
			return $entries;
		}

		$slug = (string)($self['slug'] ?? '');
		if ($slug === '' || str_starts_with($slug, self::PLACEHOLDER_SLUG_PREFIX) === true) {
			return $entries;
		}

		$entries[] = $this->templateEntry(object: $object, slug: $slug);
		return $entries;
	}//end withTemplateEntry()

	/**
	 * The catalog entry of a seeded Source.
	 *
	 * @param array<string,mixed> $object The seeded Source object.
	 * @param string              $slug   Its slug.
	 *
	 * @return array<string,mixed>
	 */
	private function templateEntry(array $object, string $slug): array {
		$type = (string)($object['type'] ?? 'api');

		return [
			'slug' => 'source-template:' . $slug,
			'name' => (string)($object['name'] ?? $slug),
			'description' => (string)($object['description'] ?? ''),
			'category' => (self::SLUG_CATEGORY_OVERRIDES[$slug] ?? self::TYPE_CATEGORY_LABELS[$type] ?? 'Integrations'),
			'kind' => 'source-template',
			// Design decision (proposal.md Risk 2): every register.d
			// seed source uses the mock/isEnabled mechanism, distinct
			// from the flag-gated container-level PDOK/Berichtenbox
			// adapters above.
			'mechanism' => 'mock-seeded',
			'flagKey' => '',
			'sourceTemplateSlug' => $slug,
			'standards' => $this->standardsForType(type: $type),
			'icon' => $this->iconForType(type: $type),
		];
	}//end templateEntry()

	/**
	 * Small standards vocabulary lookup keyed by the source's free-form
	 * `type` field — a best-effort label, not an authoritative registry.
	 *
	 * @param string $type The source schema's `type` field value.
	 *
	 * @return array<int,string>
	 */
	private function standardsForType(string $type): array {
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
	private function iconForType(string $type): string {
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
	 * @spec openspec/specs/connector-catalog/spec.md#requirement-the-store-lists-templates-it-does-not-install-req-ccx-001
	 */
	public function findSeedSourcePayload(string $slug): ?array {
		$templatePayload = $this->templates->payload(slug: $slug);
		if ($templatePayload !== null) {
			return $templatePayload;
		}

		$fragments = new SeedFragments(directory: self::FRAGMENT_DIR);
		foreach ($fragments->files() as $file) {
			$raw = file_get_contents($file);
			$data = null;
			if ($raw !== false) {
				$data = $fragments->decode(raw: $raw);
			}

			$payload = $fragments->sourcePayload(objects: $fragments->objects(data: ($data ?? [])), slug: $slug);
			if ($payload !== null) {
				return $payload;
			}
		}

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
	public function resolveStatus(array $entry): string {
		$mechanism = (string)($entry['mechanism'] ?? 'always-available');

		if ($mechanism === 'flag-gated') {
			return $this->flagStatus(flagKey: (string)($entry['flagKey'] ?? ''));
		}

		if ($mechanism === 'mock-seeded') {
			return $this->seededStatus(slug: (string)($entry['sourceTemplateSlug'] ?? ''));
		}

		// 'always-available' — fall back to the collector's own
		// isEnabled() read when present (IntegrationRegistry providers),
		// otherwise treat as always available.
		if (array_key_exists('_fallbackEnabled', $entry) === true && $entry['_fallbackEnabled'] !== true) {
			return 'dormant';
		}

		return 'available';
	}//end resolveStatus()

	/**
	 * The status of a flag-gated entry: available when its app-config flag is on.
	 *
	 * @param string $flagKey The app-config key, or ''.
	 *
	 * @return string 'available' | 'dormant'.
	 */
	private function flagStatus(string $flagKey): string {
		if ($flagKey === '') {
			return 'dormant';
		}

		$raw = $this->appConfig->getValueString('integriq', $flagKey, '0');
		if ($raw === '1' || strtolower($raw) === 'true') {
			return 'available';
		}

		return 'dormant';
	}//end flagStatus()

	/**
	 * The status of a mock-seeded entry: available when its seeded Source exists and is enabled.
	 *
	 * Mock mode is NOT dormant — the source is reachable, just returning
	 * canned data (REQ-001 scenario).
	 *
	 * @param string $slug The source template's slug, or ''.
	 *
	 * @return string 'available' | 'dormant'.
	 */
	private function seededStatus(string $slug): string {
		if ($slug === '') {
			return 'dormant';
		}

		$source = $this->findSourceBySlug(slug: $slug);
		if ($source !== null && ($source->getObject()['isEnabled'] ?? false) === true) {
			return 'available';
		}

		return 'dormant';
	}//end seededStatus()

	/**
	 * Find a `source` OpenRegister object by its slug.
	 *
	 * @param string $slug The source's slug (e.g. "brp-haalcentraal").
	 *
	 * @return ObjectEntity|null
	 */
	private function findSourceBySlug(string $slug): ?ObjectEntity {
		$result = $this->orObjectService->findAll(
			config: ['filters' => ['register' => 'integriq', 'schema' => 'source', 'slug' => $slug]]
		);
		$items = ($result['results'] ?? $result);
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

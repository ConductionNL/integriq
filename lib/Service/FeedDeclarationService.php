<?php

/**
 * Integriq FeedDeclarationService.
 *
 * Reads `lib/Settings/feeds.json` from every enabled app and keeps one
 * read-only feed endpoint per declared dataset, and one API product per
 * declaring app, in step with it (ADR-091 decision 4: an app declares the
 * endpoints it needs, integriq owns them).
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-an-app-declares-its-data-feeds-in-a-file-req-ddf-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\Integriq\Service\Feed\FeedDeclarationValidator;
use OCA\Integriq\Service\Feed\FeedSchemaFieldsInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectDeletion;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Syncs feed declarations into endpoint and api_product objects.
 *
 * A dataset that names a field its schema lacks is refused with the field
 * named; the app's other datasets are synced, and an endpoint that already
 * served the refused dataset keeps answering until the declaration is fixed.
 * An endpoint whose dataset no declaration names any more is removed.
 *
 * @spec openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-an-app-declares-its-data-feeds-in-a-file-req-ddf-001
 */
class FeedDeclarationService {

	/**
	 * Where an app declares its feeds, relative to the app's root.
	 *
	 * @var string
	 */
	public const DECLARATION_FILE = 'lib/Settings/feeds.json';

	/**
	 * The endpoint target type a feed endpoint carries.
	 *
	 * @var string
	 */
	public const TARGET_TYPE = 'feed';

	/**
	 * The register the endpoint and product objects live in.
	 *
	 * @var string
	 */
	private const REGISTER = 'integriq';

	/**
	 * How many feed endpoints one read returns at most.
	 *
	 * @var integer
	 */
	private const READ_LIMIT = 1000;

	/**
	 * Constructor.
	 *
	 * @param IAppManager               $appManager   Finds enabled apps and their paths.
	 * @param FeedDeclarationValidator  $validator    Checks a declaration file.
	 * @param FeedSchemaFieldsInterface $schemaFields Reads the fields a schema declares.
	 * @param ORObjectService           $objectService Reads and writes endpoints and products.
	 * @param LoggerInterface           $logger       Logs refused files and datasets.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly FeedDeclarationValidator $validator,
		private readonly FeedSchemaFieldsInterface $schemaFields,
		private readonly ORObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The path a feed is served at, under `/api/endpoint/`.
	 *
	 * @param string $app The declaring app.
	 * @param string $key The dataset key.
	 *
	 * @return string The path.
	 *
	 * @spec openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-an-app-declares-its-data-feeds-in-a-file-req-ddf-001
	 */
	public static function endpointPath(string $app, string $key): string {
		return 'feeds/' . $app . '/' . $key;
	}//end endpointPath()

	/**
	 * The API product slug of an app's feeds.
	 *
	 * @param string $app The declaring app.
	 *
	 * @return string The slug.
	 *
	 * @spec openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-an-app-declares-its-data-feeds-in-a-file-req-ddf-001
	 */
	public static function productSlug(string $app): string {
		return $app . '-data-feeds';
	}//end productSlug()

	/**
	 * Sync the feed declarations of one app, or of every enabled app.
	 *
	 * Syncing every app also removes the feeds of an app that is no longer
	 * enabled: no declaration names them any more.
	 *
	 * @param string|null $app One app id, or null for every enabled app.
	 *
	 * @return array{created:int,updated:int,deleted:int,unchanged:int,refused:array<string,string>}
	 *         The counts, and per refused file or dataset (`app` or `app/key`) why.
	 *
	 * @spec openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-an-app-declares-its-data-feeds-in-a-file-req-ddf-001
	 */
	public function sync(?string $app = null): array {
		$summary = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'unchanged' => 0, 'refused' => []];
		$enabled = array_map('strval', $this->appManager->getEnabledApps());
		$existing = $this->feedEndpoints();

		foreach ($this->appsToSync(app: $app, enabled: $enabled) as $appId) {
			$entries = $this->entriesOf(app: $appId, enabled: $enabled, summary: $summary);
			if ($entries === null) {
				// A file that cannot be read changes nothing: what was served stays.
				continue;
			}

			$summary = $this->syncApp(app: $appId, entries: $entries, existing: ($existing[$appId] ?? []), summary: $summary);
			unset($existing[$appId]);
		}

		if ($app === null) {
			$summary = $this->syncVanished(existing: $existing, summary: $summary);
		}

		return $summary;
	}//end sync()

	/**
	 * The apps a sync covers: the one asked for, else every enabled app.
	 *
	 * @param string|null        $app     The app asked for, null for all.
	 * @param array<int, string> $enabled The enabled apps.
	 *
	 * @return array<int, string>
	 */
	private function appsToSync(?string $app, array $enabled): array {
		if ($app === null) {
			return $enabled;
		}

		return [$app];
	}//end appsToSync()

	/**
	 * Sync the apps that still have feed endpoints but were not synced: they
	 * declare nothing now, so their endpoints go.
	 *
	 * @param array<string, array<string, ObjectEntity>> $existing The remaining endpoints by app and feed key.
	 * @param array                                      $summary  The running summary.
	 *
	 * @return array The summary.
	 */
	private function syncVanished(array $existing, array $summary): array {
		foreach ($existing as $appId => $endpoints) {
			$summary = $this->syncApp(app: (string)$appId, entries: [], existing: $endpoints, summary: $summary);
		}

		return $summary;
	}//end syncVanished()

	/**
	 * Sync one app's datasets into endpoints and its product.
	 *
	 * @param string                               $app      The app id.
	 * @param array<int, array<string, mixed>>     $entries  The declared datasets.
	 * @param array<string, ObjectEntity>          $existing The app's feed endpoints, by dataset key.
	 * @param array<string, mixed>                 $summary  The running summary.
	 *
	 * @return array<string, mixed> The summary.
	 */
	private function syncApp(string $app, array $entries, array $existing, array $summary): array {
		$served = [];
		foreach ($entries as $index => $entry) {
			$key = (string)($entry['key'] ?? ('#' . $index));
			$refusal = $this->refusalOf(entry: $entry, index: (int)$index);
			if ($refusal === null) {
				$served[$key] = $this->upsert(app: $app, entry: $entry, existing: ($existing[$key] ?? null), summary: $summary);
				continue;
			}

			$served = array_replace($served, $this->refuseEntry(app: $app, key: $key, refusal: $refusal, existing: $existing, summary: $summary));
		}

		$summary['deleted'] += $this->deleteUnserved(existing: $existing, served: $served);

		if ($served !== [] || $existing !== []) {
			$this->keepProduct(app: $app, endpointUuids: array_values($served));
		}

		return $summary;
	}//end syncApp()

	/**
	 * Record a refused feed; a feed that was served before keeps being served.
	 *
	 * @param string                      $app      The declaring app.
	 * @param string                      $key      The feed key.
	 * @param string                      $refusal  Why it is refused.
	 * @param array<string, ObjectEntity> $existing The app's endpoints by feed key.
	 * @param array                       $summary  The running summary.
	 *
	 * @return array<string, string> The kept endpoint by key, or none.
	 */
	private function refuseEntry(string $app, string $key, string $refusal, array $existing, array &$summary): array {
		$summary['refused'][$app . '/' . $key] = $refusal;
		$this->logger->error(
			'Integriq refused the feed {feed} of {declaringApp}: {reason}',
			['app' => 'integriq', 'declaringApp' => $app, 'feed' => $key, 'reason' => $refusal]
		);
		if (isset($existing[$key]) === true) {
			return [$key => (string)$existing[$key]->getUuid()];
		}

		return [];
	}//end refuseEntry()

	/**
	 * Delete the endpoints of feeds the app no longer serves.
	 *
	 * @param array<string, ObjectEntity> $existing The app's endpoints by feed key.
	 * @param array<string, string>       $served   The served endpoints by feed key.
	 *
	 * @return int How many were deleted.
	 */
	private function deleteUnserved(array $existing, array $served): int {
		$deleted = 0;
		foreach (array_diff_key($existing, $served) as $endpoint) {
			$this->objectService->deleteObject(
				deletion: new ObjectDeletion(uuid: (string)$endpoint->getUuid(), target: new ObjectFindTarget(register: self::REGISTER, schema: 'endpoint'))
			);
			$deleted++;
		}

		return $deleted;
	}//end deleteUnserved()

	/**
	 * Why a dataset is refused, or null when it can be served.
	 *
	 * @param mixed   $entry The dataset.
	 * @param integer $index Its position in the file.
	 *
	 * @return string|null The reason.
	 */
	private function refusalOf(mixed $entry, int $index): ?string {
		$errors = $this->validator->feedErrors(feed: $entry, index: $index);
		if ($errors !== []) {
			return implode('; ', $errors);
		}

		$declared = $this->schemaFields->fieldsOf(register: (string)$entry['register'], schema: (string)$entry['schema']);
		if ($declared === null) {
			return sprintf('the register "%s" has no schema "%s"', $entry['register'], $entry['schema']);
		}

		$needed = array_merge($entry['fields'], [$entry['scope']['field']], array_keys(($entry['filter'] ?? [])));
		$missing = array_values(array_unique(array_diff($needed, $declared)));
		if ($missing !== []) {
			return sprintf('the schema "%s" does not declare %s', $entry['schema'], implode(', ', $missing));
		}

		return null;
	}//end refusalOf()

	/**
	 * Create or update the endpoint of one dataset.
	 *
	 * @param string               $app      The app id.
	 * @param array<string, mixed> $entry    The dataset.
	 * @param ObjectEntity|null    $existing Its endpoint, when it has one.
	 * @param array<string, mixed> $summary  The running summary, counted into.
	 *
	 * @return string The endpoint uuid.
	 */
	private function upsert(string $app, array $entry, ?ObjectEntity $existing, array &$summary): string {
		$data = $this->endpointData(app: $app, entry: $entry);
		if ($existing !== null && $this->isSame(stored: $existing->getObject(), next: $data) === true) {
			$summary['unchanged']++;
			return (string)$existing->getUuid();
		}

		$saved = $this->objectService->saveObject(
			object: $data,
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: self::REGISTER, schema: 'endpoint'),
				uuid: $existing?->getUuid()
			)
		);

		if ($existing === null) {
			$summary['created']++;
		} else {
			$summary['updated']++;
		}

		return (string)$saved->getUuid();
	}//end upsert()

	/**
	 * The endpoint object of one dataset.
	 *
	 * @param string               $app   The app id.
	 * @param array<string, mixed> $entry The dataset.
	 *
	 * @return array<string, mixed> The endpoint.
	 *
	 * @spec openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-an-app-declares-its-data-feeds-in-a-file-req-ddf-001
	 */
	public function endpointData(string $app, array $entry): array {
		$key = (string)$entry['key'];

		return [
			'name' => $app . ': ' . (string)$entry['title'],
			'description' => (string)($entry['description'] ?? ''),
			'reference' => 'feed:' . $app . ':' . $key,
			'slug' => 'feed-' . $app . '-' . $key,
			'endpoint' => self::endpointPath(app: $app, key: $key),
			'method' => 'GET',
			'targetType' => self::TARGET_TYPE,
			'targetId' => (string)$entry['register'] . '/' . (string)$entry['schema'],
			'feed' => [
				'app' => $app,
				'key' => $key,
				'title' => (string)$entry['title'],
				'register' => (string)$entry['register'],
				'schema' => (string)$entry['schema'],
				'fields' => array_values($entry['fields']),
				'filter' => (object)($entry['filter'] ?? []),
				'scope' => [
					'parameter' => (string)$entry['scope']['parameter'],
					'field' => (string)$entry['scope']['field'],
				],
				'pageSize' => (int)$entry['pageSize'],
			],
		];
	}//end endpointData()

	/**
	 * Keep the app's API product holding exactly its feed endpoints.
	 *
	 * @param string             $app           The app id.
	 * @param array<int, string> $endpointUuids The served feed endpoints.
	 *
	 * @return void
	 */
	private function keepProduct(string $app, array $endpointUuids): void {
		$slug = self::productSlug(app: $app);
		$product = $this->findOne(schema: 'api_product', filters: ['productSlug' => $slug]);

		$data = [
			'name' => $app . ' data feeds',
			'productSlug' => $slug,
			'version' => '1',
			'description' => 'Read-only data feeds declared by ' . $app . ' in ' . self::DECLARATION_FILE . '.',
			'visibility' => 'private',
			'status' => 'active',
			'endpoints' => $endpointUuids,
		];

		if ($product !== null) {
			$stored = $product->getObject();
			if (($stored['endpoints'] ?? []) === $endpointUuids) {
				return;
			}

			$data = array_merge($stored, ['endpoints' => $endpointUuids]);
		}

		$this->objectService->saveObject(
			object: $data,
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: self::REGISTER, schema: 'api_product'),
				uuid: $product?->getUuid()
			)
		);
	}//end keepProduct()

	/**
	 * Read and check an app's declaration file.
	 *
	 * @param string               $app     The app id.
	 * @param array<string, mixed> $summary The running summary; a refused file is recorded in it.
	 *
	 * @return array<int, mixed>|null The datasets; [] when the app ships no file; null when the file is refused.
	 */
	private function readDeclaration(string $app, array &$summary): ?array {
		$file = $this->declarationPath(app: $app);
		if ($file === null) {
			return [];
		}

		$data = json_decode((string)file_get_contents($file), true);
		$errors = $this->declarationErrors(app: $app, data: $data);
		if ($errors !== []) {
			$summary['refused'][$app] = implode('; ', $errors);
			$this->logger->error(
				'Integriq skipped the feeds.json of {declaringApp}: {errors}',
				['app' => 'integriq', 'declaringApp' => $app, 'errors' => implode('; ', $errors)]
			);
			return null;
		}

		return $data['feeds'];
	}//end readDeclaration()

	/**
	 * The problems of a decoded declaration file: not JSON, malformed, or
	 * claiming another app's id. Call right after json_decode().
	 *
	 * @param string $app  The declaring app.
	 * @param mixed  $data The decoded file.
	 *
	 * @return array<int, string> The problems.
	 */
	private function declarationErrors(string $app, mixed $data): array {
		$errors = $this->validator->fileErrors(data: $data);
		if (json_last_error() !== JSON_ERROR_NONE) {
			return ['it is not valid JSON (' . json_last_error_msg() . ')'];
		}

		if ($errors === [] && isset($data['app']) === true && $data['app'] !== $app) {
			return [sprintf('it claims the app id "%s"', $data['app'])];
		}

		return $errors;
	}//end declarationErrors()

	/**
	 * The feeds an app declares: none for an app that is not enabled, null
	 * when its file cannot be read.
	 *
	 * @param string             $app     The app.
	 * @param array<int, string> $enabled The enabled apps.
	 * @param array              $summary The running summary.
	 *
	 * @return array|null The feed entries.
	 */
	private function entriesOf(string $app, array $enabled, array &$summary): ?array {
		if (in_array($app, $enabled, true) === false) {
			return [];
		}

		return $this->readDeclaration(app: $app, summary: $summary);
	}//end entriesOf()

	/**
	 * The declaration file of an app, or null when it ships none.
	 *
	 * @param string $app The app id.
	 *
	 * @return string|null The path.
	 */
	private function declarationPath(string $app): ?string {
		try {
			$path = rtrim($this->appManager->getAppPath($app), '/') . '/' . self::DECLARATION_FILE;
		} catch (Throwable $e) {
			return null;
		}

		if (is_file($path) === false || is_readable($path) === false) {
			return null;
		}

		return $path;
	}//end declarationPath()

	/**
	 * Every feed endpoint, by declaring app and dataset key.
	 *
	 * @return array<string, array<string, ObjectEntity>> The endpoints.
	 */
	private function feedEndpoints(): array {
		$result = $this->objectService->findAll(
			config: [
				'filters' => ['register' => self::REGISTER, 'schema' => 'endpoint', 'targetType' => self::TARGET_TYPE],
				'limit' => self::READ_LIMIT,
			],
			_rbac: false,
			_multitenancy: false
		);

		$endpoints = [];
		foreach (($result['results'] ?? $result) as $entity) {
			if (($entity instanceof ObjectEntity) === false) {
				continue;
			}

			$data = $entity->getObject();
			if (($data['targetType'] ?? null) !== self::TARGET_TYPE || is_array(($data['feed'] ?? null)) === false) {
				continue;
			}

			$endpoints[(string)($data['feed']['app'] ?? '')][(string)($data['feed']['key'] ?? '')] = $entity;
		}

		return $endpoints;
	}//end feedEndpoints()

	/**
	 * The first object of a schema matching the filters.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return ObjectEntity|null The object.
	 */
	private function findOne(string $schema, array $filters): ?ObjectEntity {
		$result = $this->objectService->findAll(
			config: ['filters' => array_merge(['register' => self::REGISTER, 'schema' => $schema], $filters), 'limit' => 1],
			_rbac: false,
			_multitenancy: false
		);

		foreach (($result['results'] ?? $result) as $entity) {
			if ($entity instanceof ObjectEntity) {
				return $entity;
			}
		}

		return null;
	}//end findOne()

	/**
	 * Whether a stored endpoint already holds what the declaration asks for.
	 *
	 * @param array<string, mixed> $stored The stored endpoint.
	 * @param array<string, mixed> $next   The endpoint the declaration builds.
	 *
	 * @return boolean True when nothing changed.
	 */
	private function isSame(array $stored, array $next): bool {
		foreach ($next as $key => $value) {
			if (json_decode((string)json_encode(($stored[$key] ?? null)), true) !== json_decode((string)json_encode($value), true)) {
				return false;
			}
		}

		return true;
	}//end isSame()
}//end class

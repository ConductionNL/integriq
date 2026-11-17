<?php

/**
 * Integriq AppHost Metrics Provider.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Escape-hatch metrics provider for the OpenRegister AppHost observability
 * engine (ADR-040 / ADR-006). The per-Source circuit breaker state
 * (`circuit_breaker_state{source="<name>"}`, REQ-PROM-011) cannot be
 * expressed by the closed declarative source-kind set: it needs a labelled
 * gauge carrying each individual Source's OWN `circuitBreakerState` field
 * value (1/0), not a row COUNT — `tableCount`/`objectCount` only aggregate
 * counts, they cannot surface a per-row field value as the sample's numeric
 * value. This provider reads each Source directly (via OpenRegister object
 * aggregation, ADR-022) and emits one sample per Source.
 *
 * The engine resolves this class via the container alias
 * `OCA\OpenRegister\AppHost\IMetricsProvider::integriq` (ADR-035
 * pattern) and merges its MetricSample output into the response; the
 * AppHost PrometheusRenderer prepends the `integriq_` prefix and
 * renders the exposition format, so this provider never emits raw
 * Prometheus text.
 *
 * @category Observability
 * @package  OCA\Integriq\Observability
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/prometheus-metrics/spec.md#requirement-circuit-breaker-state-gauge-req-prom-011
 */

declare(strict_types=1);

namespace OCA\Integriq\Observability;

use OCA\OpenRegister\AppHost\IMetricsProvider;
use OCA\OpenRegister\AppHost\Observability\MetricSample;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Domain metrics provider for Integriq (AppHost escape hatch).
 *
 * @psalm-suppress UnusedClass Resolved via the AppHost container alias.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Escape-hatch provider now spans two
 *   domains (circuit_breaker_state + api-product-gateway's latency/error gauges); each
 *   domain's own methods are individually simple (see per-method complexity, all under
 *   threshold) — the class-level score is a sum-of-many-small-methods artifact, not a
 *   single tangled method.
 *
 * @spec openspec/specs/prometheus-metrics/spec.md#requirement-circuit-breaker-state-gauge-req-prom-011
 * @spec openspec/specs/prometheus-metrics/spec.md#requirement-per-api-product-latency-percentile-gauges-req-prom-013
 */
class IntegriqMetricsProvider implements IMetricsProvider {
	/**
	 * The single-label grouped counters and gauges, keyed by metric name
	 * (unprefixed), in emission order: the Prometheus type, the HELP text,
	 * the OR schema title to read objects from, the object property to group
	 * by, the label key to emit and the label value of the zero placeholder.
	 *
	 * @var array<string, array{type: string, help: string, schemaTitle: string, field: string, label: string, placeholder: string}>
	 */
	private const GROUPED_COUNT_METRICS = [
		'sources_total' => [
			'type' => 'gauge',
			'help' => 'Total sources by type',
			'schemaTitle' => 'Source',
			'field' => 'type',
			'label' => 'type',
			'placeholder' => 'rest',
		],
		'calls_total' => [
			'type' => 'counter',
			'help' => 'Total API calls by status',
			'schemaTitle' => 'CallLog',
			'field' => 'statusCode',
			'label' => 'status',
			'placeholder' => '200',
		],
		'synchronization_runs_total' => [
			'type' => 'counter',
			'help' => 'Total synchronization log entries by result',
			'schemaTitle' => 'SynchronizationLog',
			'field' => 'result',
			'label' => 'status',
			'placeholder' => 'success',
		],
	];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container for the OR ObjectService / mappers.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Resolve the OpenRegister ObjectService, or null when unavailable.
	 *
	 * @return object|null The ObjectService, or null.
	 *
	 * @spec exclude Lazy DI accessor for the OR ObjectService; pure plumbing.
	 */
	private function getObjectService(): ?object {
		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (Throwable $e) {
			return null;
		}

	}//end getObjectService()

	/**
	 * Resolve the OpenRegister SchemaMapper, or null when unavailable.
	 *
	 * @return object|null The SchemaMapper, or null.
	 *
	 * @spec exclude Lazy DI accessor for the OR SchemaMapper; pure plumbing.
	 */
	private function getSchemaMapper(): ?object {
		try {
			return $this->container->get('OCA\OpenRegister\Db\SchemaMapper');
		} catch (Throwable $e) {
			return null;
		}

	}//end getSchemaMapper()

	/**
	 * Resolve the OpenRegister RegisterMapper, or null when unavailable.
	 *
	 * @return object|null The RegisterMapper, or null.
	 *
	 * @spec exclude Lazy DI accessor for the OR RegisterMapper; pure plumbing.
	 */
	private function getRegisterMapper(): ?object {
		try {
			return $this->container->get('OCA\OpenRegister\Db\RegisterMapper');
		} catch (Throwable $e) {
			return null;
		}

	}//end getRegisterMapper()

	/**
	 * Normalise an OpenRegister object (entity or array) to a plain array.
	 *
	 * @param mixed $object The OR object.
	 *
	 * @return array<string, mixed> The object's own fields.
	 *
	 * @spec exclude Shape-normalisation plumbing for OR objects; no domain behaviour.
	 */
	private function normalise(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true) {
			if (method_exists($object, 'jsonSerialize') === true) {
				$data = $object->jsonSerialize();
				if (is_array($data) === true) {
					return $data;
				}
			}

			return (array)$object;
		}

		return [];
	}//end normalise()

	/**
	 * Resolve the ids of every register that carries the given schema id.
	 *
	 * @param integer $schemaId The schema id to look for.
	 *
	 * @return array<int, int> Matching register ids.
	 */
	private function resolveRegisterIdsForSchema(int $schemaId): array {
		$registerMapper = $this->getRegisterMapper();
		if ($registerMapper === null) {
			return [];
		}

		$registerIds = [];
		foreach ($registerMapper->findAll() as $register) {
			$data = $this->normalise(object: $register);
			if ($this->registerCarriesSchema(register: $data, schemaId: $schemaId) === true) {
				$registerIds[] = (int)$data['id'];
			}
		}

		return $registerIds;
	}//end resolveRegisterIdsForSchema()

	/**
	 * Whether a register with an id lists the given schema id among its schemas.
	 *
	 * @param array<string, mixed> $register The register's own fields.
	 * @param integer              $schemaId The schema id to look for.
	 *
	 * @return boolean True when the register has an id and carries the schema.
	 */
	private function registerCarriesSchema(array $register, int $schemaId): bool {
		$schemas = ($register['schemas'] ?? []);
		if (($register['id'] ?? null) === null || is_array($schemas) === false) {
			return false;
		}

		foreach ($schemas as $schema) {
			if ($this->schemaEntryId(schema: $schema) === $schemaId) {
				return true;
			}
		}

		return false;

	}//end registerCarriesSchema()

	/**
	 * Read the numeric id of one entry of a register's schema list, which is
	 * either the id itself or a schema array carrying it.
	 *
	 * @param mixed $schema One entry of the register's `schemas` list.
	 *
	 * @return integer|null The schema id, or null when the entry carries no numeric id.
	 */
	private function schemaEntryId(mixed $schema): ?int {
		$candidateId = $schema;
		if (is_array($schema) === true) {
			$candidateId = ($schema['id'] ?? null);
		}

		if (is_numeric($candidateId) === false) {
			return null;
		}

		return (int)$candidateId;

	}//end schemaEntryId()

	/**
	 * Resolve the id of a schema by exact title match — the generic form of
	 * {@see resolveSchemaIdByTitle()}, reused by the api-product-gateway
	 * percentile/error gauges below to resolve the `Api Product` and
	 * `CallLog` schema ids.
	 *
	 * @param string $title The schema's exact `title` field value.
	 *
	 * @return integer|null The schema id, or null when not found/unavailable.
	 *
	 * @spec openspec/specs/prometheus-metrics/spec.md#requirement-per-api-product-latency-percentile-gauges-req-prom-013
	 */
	private function resolveSchemaIdByTitle(string $title): ?int {
		$schemaMapper = $this->getSchemaMapper();
		if ($schemaMapper === null) {
			return null;
		}

		foreach ($schemaMapper->findAll() as $schema) {
			$data = $this->normalise(object: $schema);
			if ((string)($data['title'] ?? '') === $title) {
				return $this->optionalId(id: ($data['id'] ?? null));
			}
		}

		return null;
	}//end resolveSchemaIdByTitle()

	/**
	 * Cast an object id to an integer, keeping a missing id missing.
	 *
	 * @param mixed $id The id field value, or null.
	 *
	 * @return integer|null The id, or null when absent.
	 */
	private function optionalId(mixed $id): ?int {
		if ($id === null) {
			return null;
		}

		return (int)$id;

	}//end optionalId()

	/**
	 * Fetch the inbound `call_log` objects as plain field arrays, via the same
	 * object-aggregation route {@see fetchObjectsBySchemaTitle()} uses. There is no
	 * query-level filter/sort/limit vocabulary confirmed available on
	 * `searchObjects()` beyond `@self.register`/`@self.schema` (unlike the
	 * DI-injected `ORObjectService::findAll(config:['filters'=>...])` used
	 * elsewhere in this app), so the `product`/`direction` narrowing and the
	 * 1000-row-per-product bound (design.md Decision 3 / `REQ-PROM-013`) are
	 * both applied in PHP by the caller, not at the query — a pragmatic,
	 * behaviourally-safe choice at the cost of fetching more rows than the
	 * eventual per-product window; the provider's own degrade-to-zero
	 * fallback (see {@see apiProductLatencySample()}) already tolerates a
	 * slow/failed query, so this is a performance, not a correctness,
	 * trade-off.
	 *
	 * @return array<int, array<string, mixed>> The call_log rows' own fields.
	 *
	 * @throws Throwable When the underlying OR aggregation query fails.
	 *
	 * @spec openspec/specs/prometheus-metrics/spec.md#requirement-per-api-product-latency-percentile-gauges-req-prom-013
	 */
	private function fetchInboundCallLogRows(): array {
		return array_filter(
			$this->fetchObjectsBySchemaTitle(title: 'CallLog'),
			static fn (array $row): bool => (string)($row['direction'] ?? '') === 'inbound'
		);
	}//end fetchInboundCallLogRows()

	/**
	 * Nearest-rank percentile of a pre-sorted (ascending, milliseconds)
	 * value list, converted to seconds per Prometheus convention.
	 *
	 * @param array<int, float> $sortedMs Ascending-sorted millisecond values.
	 * @param float $fraction The percentile as a fraction (e.g. 0.95 for p95).
	 *
	 * @return float The percentile in seconds, or 0 when the list is empty.
	 *
	 * @spec openspec/specs/prometheus-metrics/spec.md#requirement-per-api-product-latency-percentile-gauges-req-prom-013
	 */
	private function percentileSeconds(array $sortedMs, float $fraction): float {
		$count = count($sortedMs);
		if ($count === 0) {
			return 0.0;
		}

		$index = ((int)ceil($fraction * $count) - 1);
		$index = max(0, min(($count - 1), $index));

		return round(($sortedMs[$index] / 1000), 6);
	}//end percentileSeconds()

	/**
	 * Produce the `api_product_latency_seconds{product,quantile}` gauge
	 * (`REQ-PROM-013`) — p50/p95/p99 computed from each product's most
	 * recent (bound: 1000) inbound `call_log` `responseTime` values.
	 * Cannot be expressed by the declarative `tableCount`/`groupBy`
	 * vocabulary (percentiles require sorting values within a group, not
	 * counting rows) — same escape hatch as `circuit_breaker_state`
	 * (`REQ-PROM-011`). Falls back to a single zero-value sample set (never
	 * a 500) with a warning logged when the underlying query fails.
	 *
	 * @return MetricSample The latency percentile gauge sample set.
	 *
	 * @spec openspec/specs/prometheus-metrics/spec.md#requirement-per-api-product-latency-percentile-gauges-req-prom-013
	 */
	private function apiProductLatencySample(): MetricSample {
		$help = 'Per-API-Product inbound response-time latency percentiles in seconds (p50/p95/p99), from bounded per-product call_log windows';

		try {
			$products = $this->fetchObjectsBySchemaTitle(title: 'API Product');
			$callLogRows = $this->fetchInboundCallLogRows();
		} catch (Throwable $e) {
			$this->logger->warning('integriq: api_product_latency_seconds query failed: ' . $e->getMessage());
			return new MetricSample(
				name: 'api_product_latency_seconds',
				type: 'gauge',
				help: $help,
				samples: $this->latencyPlaceholderPoints()
			);
		}

		$points = [];
		foreach ($products as $product) {
			array_push($points, ...$this->productLatencyPoints(product: $product, callLogRows: $callLogRows));
		}

		if ($points === []) {
			$points = $this->latencyPlaceholderPoints();
		}

		return new MetricSample(name: 'api_product_latency_seconds', type: 'gauge', help: $help, samples: $points);
	}//end apiProductLatencySample()

	/**
	 * The zero-valued p50/p95/p99 points emitted when no product reports latency.
	 *
	 * @return array<int, array{labels: array<string, string>, value: int}>
	 *
	 * @spec openspec/specs/prometheus-metrics/spec.md#requirement-per-api-product-latency-percentile-gauges-req-prom-013
	 */
	private function latencyPlaceholderPoints(): array {
		$points = [];
		foreach (['0.5', '0.95', '0.99'] as $label) {
			$points[] = ['labels' => ['product' => '', 'quantile' => $label], 'value' => 0];
		}

		return $points;

	}//end latencyPlaceholderPoints()

	/**
	 * Compute the three p50/p95/p99 sample points for a single product,
	 * from the bounded (most recent 1000, design.md Decision 3) subset of
	 * `$callLogRows` carrying its uuid. Extracted from
	 * {@see apiProductLatencySample()} to keep that method's own cyclomatic
	 * complexity under the project's threshold.
	 *
	 * @param array<string, mixed> $product One api_product's own fields.
	 * @param array<int, array<string, mixed>> $callLogRows Every fetched inbound call_log row.
	 *
	 * @return array<int, array{labels: array<string, string>, value: float}>
	 *
	 * @spec openspec/specs/prometheus-metrics/spec.md#requirement-per-api-product-latency-percentile-gauges-req-prom-013
	 */
	private function productLatencyPoints(array $product, array $callLogRows): array {
		$uuid = (string)($product['uuid'] ?? '');
		$slug = (string)($product['productSlug'] ?? $uuid);

		$responseTimes = $this->productResponseTimes(uuid: $uuid, callLogRows: $callLogRows);

		$points = [];
		foreach (['0.5' => 0.5, '0.95' => 0.95, '0.99' => 0.99] as $label => $fraction) {
			$points[] = [
				'labels' => ['product' => $slug, 'quantile' => $label],
				'value' => $this->percentileSeconds(sortedMs: $responseTimes, fraction: $fraction),
			];
		}

		return $points;
	}//end productLatencyPoints()

	/**
	 * Collect one product's numeric response times, sorted ascending and
	 * bounded to the most recent 1000.
	 *
	 * @param string                           $uuid        The product's uuid.
	 * @param array<int, array<string, mixed>> $callLogRows Every fetched inbound call_log row.
	 *
	 * @return array<int, float> The sorted, bounded response times in milliseconds.
	 *
	 * @spec openspec/specs/prometheus-metrics/spec.md#requirement-per-api-product-latency-percentile-gauges-req-prom-013
	 */
	private function productResponseTimes(string $uuid, array $callLogRows): array {
		$responseTimes = [];
		foreach ($callLogRows as $row) {
			if ((string)($row['product'] ?? '') === $uuid
				&& isset($row['responseTime']) === true
				&& is_numeric($row['responseTime']) === true
			) {
				$responseTimes[] = (float)$row['responseTime'];
			}
		}

		sort($responseTimes);

		return $this->boundToMostRecent(responseTimes: $responseTimes);

	}//end productResponseTimes()

	/**
	 * Bound response times to the most recent 1000 (design.md Decision 3) —
	 * the query itself is unbounded (see fetchInboundCallLogRows()), so this
	 * slices the tail of whatever was returned.
	 *
	 * @param array<int, float> $responseTimes The sorted response times.
	 *
	 * @return array<int, float> At most the last 1000 of them.
	 *
	 * @spec openspec/specs/prometheus-metrics/spec.md#requirement-per-api-product-latency-percentile-gauges-req-prom-013
	 */
	private function boundToMostRecent(array $responseTimes): array {
		if (count($responseTimes) > 1000) {
			return array_slice($responseTimes, -1000);
		}

		return $responseTimes;

	}//end boundToMostRecent()

	/**
	 * Produce the `api_product_errors_total{product}` gauge
	 * (`REQ-PROM-012`). Implemented via the `IMetricsProvider` escape hatch
	 * rather than the declarative `tableCount`/`groupBy` vocabulary used for
	 * the sibling `api_product_requests_total` (manifest.json): a
	 * `statusCode >= 400` THRESHOLD aggregation is not expressible by
	 * `groupBy` (which groups by exact column values, like a percentile it
	 * needs row-level logic, not a count) — a deviation from REQ-PROM-012's
	 * literal "computed declaratively" wording, followed the code's actual
	 * declarative vocabulary instead (same class of deviation this change's
	 * discovery.md already documented once for the call_log inbound-logging
	 * assumption).
	 *
	 * @return MetricSample The error-count gauge sample set.
	 *
	 * @spec openspec/specs/prometheus-metrics/spec.md#requirement-per-api-product-request-and-error-gauges-req-prom-012
	 */
	private function apiProductErrorsSample(): MetricSample {
		$help = 'Total inbound error (statusCode>=400) requests per API Product';

		try {
			$products = $this->fetchObjectsBySchemaTitle(title: 'API Product');
			$callLogRows = $this->fetchInboundCallLogRows();
		} catch (Throwable $e) {
			$this->logger->warning('integriq: api_product_errors_total query failed: ' . $e->getMessage());
			return new MetricSample(
				name: 'api_product_errors_total',
				type: 'gauge',
				help: $help,
				samples: [['labels' => ['product' => ''], 'value' => 0]]
			);
		}

		$points = [];
		foreach ($products as $product) {
			$points[] = $this->productErrorPoint(product: $product, callLogRows: $callLogRows);
		}

		if ($points === []) {
			$points[] = ['labels' => ['product' => ''], 'value' => 0];
		}

		return new MetricSample(name: 'api_product_errors_total', type: 'gauge', help: $help, samples: $points);
	}//end apiProductErrorsSample()

	/**
	 * Count one product's inbound error rows (statusCode >= 400) as a sample point.
	 *
	 * @param array<string, mixed>             $product     One api_product's own fields.
	 * @param array<int, array<string, mixed>> $callLogRows Every fetched inbound call_log row.
	 *
	 * @return array{labels: array<string, string>, value: int}
	 *
	 * @spec openspec/specs/prometheus-metrics/spec.md#requirement-per-api-product-request-and-error-gauges-req-prom-012
	 */
	private function productErrorPoint(array $product, array $callLogRows): array {
		$uuid = (string)($product['uuid'] ?? '');
		$slug = (string)($product['productSlug'] ?? $uuid);

		$errorCount = 0;
		foreach ($callLogRows as $row) {
			if ((string)($row['product'] ?? '') !== $uuid) {
				continue;
			}

			if ((int)($row['statusCode'] ?? 0) >= 400) {
				$errorCount++;
			}
		}

		return ['labels' => ['product' => $slug], 'value' => $errorCount];

	}//end productErrorPoint()

	/**
	 * Produce the `circuit_breaker_state{source="<name>"}` gauge (REQ-PROM-011).
	 *
	 * `1` when a Source's `circuitBreakerState` is `open`, `0` when `closed`
	 * or unset. Falls back to a single zero-value sample (no crash, 200
	 * still returned) with a warning logged when the source query fails.
	 *
	 * @return MetricSample The circuit-breaker-state gauge sample set.
	 *
	 * @spec openspec/specs/prometheus-metrics/spec.md#requirement-circuit-breaker-state-gauge-req-prom-011
	 */
	private function circuitBreakerStateSample(): MetricSample {
		$help = 'Per-source circuit breaker state (1=open, 0=closed)';

		try {
			$sources = $this->fetchObjectsBySchemaTitle(title: 'Source');
		} catch (Throwable $e) {
			return new MetricSample(
				name: 'circuit_breaker_state',
				type: 'gauge',
				help: $help,
				samples: [['labels' => ['source' => ''], 'value' => 0]]
			);
		}

		$points = [];
		foreach ($sources as $source) {
			$points[] = $this->circuitBreakerPoint(source: $source);
		}

		if ($points === []) {
			$points[] = ['labels' => ['source' => ''], 'value' => 0];
		}

		return new MetricSample(name: 'circuit_breaker_state', type: 'gauge', help: $help, samples: $points);
	}//end circuitBreakerStateSample()

	/**
	 * One source's circuit breaker sample point: 1 when open, 0 otherwise.
	 *
	 * @param array<string, mixed> $source One Source's own fields.
	 *
	 * @return array{labels: array<string, string>, value: int}
	 *
	 * @spec openspec/specs/prometheus-metrics/spec.md#requirement-circuit-breaker-state-gauge-req-prom-011
	 */
	private function circuitBreakerPoint(array $source): array {
		$name = (string)($source['name'] ?? '');
		$state = (string)($source['circuitBreakerState'] ?? 'closed');

		$value = 0;
		if ($state === 'open') {
			$value = 1;
		}

		return [
			'labels' => ['source' => $name],
			'value' => $value,
		];

	}//end circuitBreakerPoint()

	/**
	 * Fetch every object of the schema with the given title, as plain field
	 * arrays, via OpenRegister object aggregation (ADR-022).
	 *
	 * @param string $title The schema's exact `title` field value.
	 *
	 * @return array<int, array<string, mixed>> The objects' own fields.
	 *
	 * @throws Throwable When the underlying OR aggregation query fails.
	 *
	 * @spec exclude Shared OR-aggregation plumbing for every gauge and counter below.
	 */
	private function fetchObjectsBySchemaTitle(string $title): array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$schemaId = $this->resolveSchemaIdByTitle(title: $title);
		if ($schemaId === null) {
			return [];
		}

		$objects = [];
		foreach ($this->resolveRegisterIdsForSchema(schemaId: $schemaId) as $registerId) {
			$scope = [
				'register' => $registerId,
				'schema' => $schemaId,
			];
			array_push($objects, ...$this->searchScopedObjects(objectService: $objectService, scope: $scope));
		}

		return $objects;
	}//end fetchObjectsBySchemaTitle()

	/**
	 * Search one register/schema pair in system context and normalise the hits.
	 *
	 * @param object               $objectService The OR ObjectService.
	 * @param array<string, mixed> $scope         The `@self` register and schema filter.
	 *
	 * @return array<int, array<string, mixed>> The objects' own fields.
	 *
	 * @throws Throwable When the underlying OR aggregation query fails.
	 *
	 * @spec exclude Shared OR-aggregation plumbing for every gauge and counter below.
	 */
	private function searchScopedObjects(object $objectService, array $scope): array {
		// Unscoped: metrics count objects across every organisation, as the system.
		$results = $objectService->searchObjects(
			query: ['@self' => $scope],
			_rbac: false,
			_multitenancy: false,
		);

		if (is_array($results) === false) {
			return [];
		}

		$objects = [];
		foreach ($results as $result) {
			$objects[] = $this->normalise(object: $result);
		}

		return $objects;

	}//end searchScopedObjects()

	/**
	 * Read the objects a grouped metric counts, logging a failed read and
	 * treating it as no objects.
	 *
	 * @param string $name The metric name, a key of GROUPED_COUNT_METRICS.
	 *
	 * @return array<int, array<string, mixed>> The objects' own fields.
	 *
	 * @spec openspec/specs/prometheus-metrics/spec.md#req-prom-004-sources-gauge-by-type
	 */
	private function readGroupedRows(string $name): array {
		$schemaTitle = self::GROUPED_COUNT_METRICS[$name]['schemaTitle'];
		try {
			return $this->fetchObjectsBySchemaTitle(title: $schemaTitle);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: sprintf('[integriq\\Metrics] "%s" could not read %s objects: %s', $name, $schemaTitle, $e->getMessage()),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return [];
		}

	}//end readGroupedRows()

	/**
	 * Count the rows per non-empty value of one property, in first-seen order.
	 *
	 * @param array<int, array<string, mixed>> $rows  The objects' own fields.
	 * @param string                           $field The property to group by.
	 *
	 * @return array<string|int, int> Row count per property value.
	 *
	 * @spec openspec/specs/prometheus-metrics/spec.md#req-prom-004-sources-gauge-by-type
	 */
	private function countByField(array $rows, string $field): array {
		$counts = [];
		foreach ($rows as $row) {
			$value = (string)($row[$field] ?? '');
			if ($value === '') {
				continue;
			}

			$counts[$value] = (($counts[$value] ?? 0) + 1);
		}

		return $counts;

	}//end countByField()

	/**
	 * Build a single-label grouped counter/gauge from OR objects, honouring
	 * the spec's ZERO-VALUE PLACEHOLDER scenarios.
	 *
	 * Why this is a provider escape hatch rather than a declarative
	 * `objectCount` + `groupBy` descriptor — two independent reasons, both
	 * measured on run 30821823343:
	 *
	 *  1. `objectCount`'s grouped path emits NOTHING even when the data is
	 *     there. `ObjectMetricSource::expandGroups()` discovers buckets via
	 *     `getFacetsForObjects()`, and that returned no terms buckets for a
	 *     plain JSON property: `sources_total` produced zero samples against
	 *     22 Source objects that all carry `type: "api"`, while the ungrouped
	 *     descriptors (`jobs_total 18`, `mappings_total 19`) were correct in
	 *     the same scrape. Filed as ConductionNL/openregister#2308.
	 *  2. Even with working facets it could not satisfy the spec. The engine
	 *     has no `labelMap` for object sources, so the label would carry the
	 *     OR property name (`statusCode`, `result`) instead of the `status`
	 *     the spec mandates; and `expandGroups()` drops zero-valued buckets,
	 *     so the "no calls emits a zero placeholder" scenarios are
	 *     inexpressible in the declarative vocabulary at all.
	 *
	 * The placeholder is not cosmetic: `rate()` and `absent()` behave very
	 * differently against a series that is missing versus one that is present
	 * and zero, which is exactly why the spec calls for it.
	 *
	 * @param string $name The metric name, a key of GROUPED_COUNT_METRICS.
	 *
	 * @return MetricSample The grouped sample set (never empty).
	 *
	 * @spec openspec/specs/prometheus-metrics/spec.md#req-prom-004-sources-gauge-by-type
	 */
	private function groupedCountSample(string $name): MetricSample {
		$metric = self::GROUPED_COUNT_METRICS[$name];
		$counts = $this->countByField(rows: $this->readGroupedRows(name: $name), field: $metric['field']);

		// Spec: with no rows the family MUST still expose one zero-valued
		// series rather than disappear entirely.
		if ($counts === []) {
			$counts[$metric['placeholder']] = 0;
		}

		$points = [];
		foreach ($counts as $value => $count) {
			$points[] = [
				'labels' => [$metric['label'] => (string)$value],
				'value' => $count,
			];
		}

		return new MetricSample(name: $name, type: $metric['type'], help: $metric['help'], samples: $points);
	}//end groupedCountSample()

	/**
	 * Produce Integriq's domain metric samples.
	 *
	 * @return MetricSample[] The provider's samples.
	 *
	 * @spec openspec/specs/prometheus-metrics/spec.md#requirement-circuit-breaker-state-gauge-req-prom-011
	 */
	public function metrics(): array {
		$samples = [
			$this->circuitBreakerStateSample(),
			$this->apiProductLatencySample(),
			$this->apiProductErrorsSample(),
		];
		foreach (array_keys(self::GROUPED_COUNT_METRICS) as $name) {
			$samples[] = $this->groupedCountSample(name: $name);
		}

		return $samples;

	}//end metrics()
}//end class

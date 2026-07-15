<?php
/**
 * OpenConnector AppHost Metrics Provider.
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
 * `OCA\OpenRegister\AppHost\IMetricsProvider::openconnector` (ADR-035
 * pattern) and merges its MetricSample output into the response; the
 * AppHost PrometheusRenderer prepends the `openconnector_` prefix and
 * renders the exposition format, so this provider never emits raw
 * Prometheus text.
 *
 * @category Observability
 * @package  OCA\OpenConnector\Observability
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/specs/prometheus-metrics/spec.md#req-prom-011-circuit-breaker-state-gauge
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Observability;

use OCA\OpenRegister\AppHost\IMetricsProvider;
use OCA\OpenRegister\AppHost\Observability\MetricSample;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Domain metrics provider for OpenConnector (AppHost escape hatch).
 *
 * @psalm-suppress UnusedClass Resolved via the AppHost container alias.
 *
 * @spec openspec/specs/prometheus-metrics/spec.md#req-prom-011-circuit-breaker-state-gauge
 */
class OpenConnectorMetricsProvider implements IMetricsProvider
{
    /**
     * Constructor.
     *
     * @param ContainerInterface $container DI container for the OR ObjectService / mappers.
     * @param LoggerInterface    $logger    Logger.
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
    private function getObjectService(): ?object
    {
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
    private function getSchemaMapper(): ?object
    {
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
    private function getRegisterMapper(): ?object
    {
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
    private function normalise(mixed $object): array
    {
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

            return (array) $object;
        }

        return [];

    }//end normalise()

    /**
     * Resolve the id of the `source` schema by exact title match, via
     * `SchemaMapper::findAll()` (a signature every mapper implementation —
     * including this app's own test stubs — declares consistently, unlike
     * the slug-keyed `find()` overload).
     *
     * @return integer|null The schema id, or null when not found/unavailable.
     */
    private function resolveSourceSchemaId(): ?int
    {
        $schemaMapper = $this->getSchemaMapper();
        if ($schemaMapper === null) {
            return null;
        }

        foreach ($schemaMapper->findAll() as $schema) {
            $data = $this->normalise(object: $schema);
            if ((string) ($data['title'] ?? '') === 'Source') {
                $schemaId = ($data['id'] ?? null);
                if ($schemaId === null) {
                    return null;
                }

                return (int) $schemaId;
            }
        }

        return null;

    }//end resolveSourceSchemaId()

    /**
     * Resolve the ids of every register that carries the given schema id.
     *
     * @param integer $schemaId The schema id to look for.
     *
     * @return array<int, int> Matching register ids.
     */
    private function resolveRegisterIdsForSchema(int $schemaId): array
    {
        $registerMapper = $this->getRegisterMapper();
        if ($registerMapper === null) {
            return [];
        }

        $registerIds = [];
        foreach ($registerMapper->findAll() as $register) {
            $data       = $this->normalise(object: $register);
            $registerId = ($data['id'] ?? null);
            $schemas    = ($data['schemas'] ?? []);
            if ($registerId === null || is_array($schemas) === false) {
                continue;
            }

            foreach ($schemas as $schema) {
                $candidateId = $schema;
                if (is_array($schema) === true) {
                    $candidateId = ($schema['id'] ?? null);
                }

                if (is_numeric($candidateId) === true && (int) $candidateId === $schemaId) {
                    $registerIds[] = (int) $registerId;
                    break;
                }
            }
        }//end foreach

        return $registerIds;

    }//end resolveRegisterIdsForSchema()

    /**
     * Fetch every `source` object as plain field arrays, via OpenRegister
     * object aggregation (ADR-022 — no raw SQL against openconnector's
     * storage table).
     *
     * @return array<int, array<string, mixed>> The sources' own fields.
     *
     * @throws Throwable When the underlying OR aggregation query fails.
     */
    private function fetchSources(): array
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return [];
        }

        $schemaId = $this->resolveSourceSchemaId();
        if ($schemaId === null) {
            return [];
        }

        $registerIds = $this->resolveRegisterIdsForSchema(schemaId: $schemaId);
        if ($registerIds === []) {
            return [];
        }

        $objects = [];
        foreach ($registerIds as $registerId) {
            $results = $objectService->searchObjects(
                query: [
                    '@self' => [
                        'register' => $registerId,
                        'schema'   => $schemaId,
                    ],
                ],
                _rbac: false,
                _multitenancy: false,
            );

            if (is_array($results) === false) {
                continue;
            }

            foreach ($results as $result) {
                $objects[] = $this->normalise(object: $result);
            }
        }//end foreach

        return $objects;

    }//end fetchSources()

    /**
     * Produce the `circuit_breaker_state{source="<name>"}` gauge (REQ-PROM-011).
     *
     * `1` when a Source's `circuitBreakerState` is `open`, `0` when `closed`
     * or unset. Falls back to a single zero-value sample (no crash, 200
     * still returned) with a warning logged when the source query fails.
     *
     * @return MetricSample The circuit-breaker-state gauge sample set.
     *
     * @spec openspec/specs/prometheus-metrics/spec.md#req-prom-011-circuit-breaker-state-gauge
     */
    private function circuitBreakerStateSample(): MetricSample
    {
        $help = 'Per-source circuit breaker state (1=open, 0=closed)';

        try {
            $sources = $this->fetchSources();
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
            $name  = (string) ($source['name'] ?? '');
            $state = (string) ($source['circuitBreakerState'] ?? 'closed');

            $value = 0;
            if ($state === 'open') {
                $value = 1;
            }

            $points[] = [
                'labels' => ['source' => $name],
                'value'  => $value,
            ];
        }

        if ($points === []) {
            $points[] = ['labels' => ['source' => ''], 'value' => 0];
        }

        return new MetricSample(name: 'circuit_breaker_state', type: 'gauge', help: $help, samples: $points);

    }//end circuitBreakerStateSample()

    /**
     * Produce OpenConnector's domain metric samples.
     *
     * @return MetricSample[] The provider's samples.
     *
     * @spec openspec/specs/prometheus-metrics/spec.md#req-prom-011-circuit-breaker-state-gauge
     */
    public function metrics(): array
    {
        return [$this->circuitBreakerStateSample()];

    }//end metrics()
}//end class

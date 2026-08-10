<?php

/**
 * Unit tests for OpenConnectorMetricsProvider.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Observability
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/prometheus-metrics/spec.md#requirement-circuit-breaker-state-gauge-req-prom-011
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Observability;

use OCA\OpenConnector\Observability\OpenConnectorMetricsProvider;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the circuit_breaker_state provider gauge (REQ-PROM-011).
 *
 * @spec openspec/specs/prometheus-metrics/spec.md#requirement-circuit-breaker-state-gauge-req-prom-011
 */
class OpenConnectorMetricsProviderTest extends TestCase
{

    /**
     * Build a provider whose container resolves the given ObjectService,
     * SchemaMapper and RegisterMapper doubles.
     *
     * @param object|null $objectService Double returned for the OR ObjectService alias, or null (unavailable).
     * @param array       $schemas       Rows `findAll()` on SchemaMapper should return.
     * @param array       $registers     Rows `findAll()` on RegisterMapper should return.
     *
     * @return OpenConnectorMetricsProvider
     */
    private function buildProvider(?object $objectService, array $schemas, array $registers): OpenConnectorMetricsProvider
    {
        $schemaMapper = $this->createMock(SchemaMapper::class);
        $schemaMapper->method('findAll')->willReturn($schemas);

        $registerMapper = $this->createMock(RegisterMapper::class);
        $registerMapper->method('findAll')->willReturn($registers);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            function (string $id) use ($objectService, $schemaMapper, $registerMapper) {
                return match ($id) {
                    'OCA\OpenRegister\Service\ObjectService' => $objectService,
                    'OCA\OpenRegister\Db\SchemaMapper' => $schemaMapper,
                    'OCA\OpenRegister\Db\RegisterMapper' => $registerMapper,
                    default => null,
                };
            }
        );

        return new OpenConnectorMetricsProvider($container, $this->createMock(LoggerInterface::class));
    }//end buildProvider()


    /**
     * Every provider-backed metric NAME this provider is expected to emit.
     *
     * One MetricSample per name, regardless of how many points each carries.
     * Asserting the name SET rather than a bare count means adding or losing a
     * metric fails with a diff that names it, instead of an opaque
     * "6 does not match 3" — which is how this assertion silently rotted when
     * #1126 revived sources_total / calls_total / synchronization_runs_total.
     *
     * @var string[]
     */
    private const EXPECTED_METRIC_NAMES = [
        'api_product_errors_total',
        'api_product_latency_seconds',
        'calls_total',
        'circuit_breaker_state',
        'sources_total',
        'synchronization_runs_total',
    ];


    /**
     * Assert the provider emitted exactly the expected set of metric names.
     *
     * @param array<int,object> $samples MetricSample objects returned by the provider.
     *
     * @return void
     */
    private function assertSameMetricNames(array $samples): void
    {
        $names = array_map(static fn (object $s): string => $s->name, $samples);
        sort($names);

        $this->assertSame(self::EXPECTED_METRIC_NAMES, $names);
    }//end assertSameMetricNames()


    /**
     * TC-19 — mixed open/closed/never-evaluated sources report 1/0/0.
     *
     * @return void
     */
    public function testGaugeReportsOneForOpenAndZeroForClosedAndUnset(): void
    {
        $objectService = new class {
            public function searchObjects(array $query, bool $_rbac=true, bool $_multitenancy=true): array
            {
                return [
                    ['name' => 'kvk-api', 'circuitBreakerState' => 'open'],
                    ['name' => 'brp-api', 'circuitBreakerState' => 'closed'],
                    ['name' => 'never-evaluated'],
                ];
            }
        };

        $provider = $this->buildProvider(
            objectService: $objectService,
            schemas: [['id' => 7, 'title' => 'Source']],
            registers: [['id' => 3, 'schemas' => [7]]],
        );

        $samples = $provider->metrics();
        $this->assertSameMetricNames($samples);

        $sample = $this->sampleByName($samples, 'circuit_breaker_state');
        $this->assertSame('circuit_breaker_state', $sample->name);
        $this->assertSame('gauge', $sample->type);

        $bySource = [];
        foreach ($sample->samples as $point) {
            $bySource[$point['labels']['source']] = $point['value'];
        }

        $this->assertSame(1, $bySource['kvk-api']);
        $this->assertSame(0, $bySource['brp-api']);
        $this->assertSame(0, $bySource['never-evaluated']);
    }//end testGaugeReportsOneForOpenAndZeroForClosedAndUnset()


    /**
     * TC-20 — a query failure falls back to a single zero-value sample
     * (degraded-but-not-broken); the provider never throws.
     *
     * @return void
     */
    public function testQueryFailureFallsBackToZeroValue(): void
    {
        $objectService = new class {
            public function searchObjects(array $query, bool $_rbac=true, bool $_multitenancy=true): array
            {
                throw new \RuntimeException('DB unavailable');
            }
        };

        $provider = $this->buildProvider(
            objectService: $objectService,
            schemas: [['id' => 7, 'title' => 'Source']],
            registers: [['id' => 3, 'schemas' => [7]]],
        );

        $samples = $provider->metrics();

        $this->assertSameMetricNames($samples);
        $sample = $this->sampleByName($samples, 'circuit_breaker_state');
        $this->assertCount(1, $sample->samples);
        $this->assertSame(0, $sample->samples[0]['value']);
    }//end testQueryFailureFallsBackToZeroValue()


    /**
     * The ObjectService being unresolvable (OpenRegister absent) degrades to
     * the same zero-value fallback rather than a fatal error.
     *
     * @return void
     */
    public function testUnavailableObjectServiceFallsBackToZeroValue(): void
    {
        $provider = $this->buildProvider(objectService: null, schemas: [], registers: []);

        $samples = $provider->metrics();

        $this->assertSameMetricNames($samples);
        $circuitBreaker = $this->sampleByName($samples, 'circuit_breaker_state');
        $this->assertSame(0, $circuitBreaker->samples[0]['value']);
    }//end testUnavailableObjectServiceFallsBackToZeroValue()


    /**
     * REQ-PROM-013 — latency percentiles are computed per product from its
     * own inbound call_log rows' responseTime values, converted to seconds.
     *
     * @return void
     */
    public function testLatencyGaugeComputesPercentilesPerProduct(): void
    {
        // 10 inbound rows for product "prod-a" with responseTime 10..100ms
        // (ascending, easy to hand-verify nearest-rank percentiles).
        $callLogRows = [];
        foreach (range(1, 10) as $i) {
            $callLogRows[] = [
                'direction'    => 'inbound',
                'product'      => 'uuid-a',
                'statusCode'   => 200,
                'responseTime' => ($i * 10),
            ];
        }

        $objectService = new class ($callLogRows) {
            /**
             * @param array $callLogRows Rows returned for the CallLog schema.
             */
            public function __construct(private array $callLogRows)
            {
            }

            public function searchObjects(array $query, bool $_rbac=true, bool $_multitenancy=true): array
            {
                $schemaId = ($query['@self']['schema'] ?? null);
                if ($schemaId === 99) {
                    return [['uuid' => 'uuid-a', 'productSlug' => 'prod-a']];
                }

                if ($schemaId === 100) {
                    return $this->callLogRows;
                }

                return [];
            }
        };

        $provider = $this->buildProvider(
            objectService: $objectService,
            schemas: [
                ['id' => 99, 'title' => 'API Product'],
                ['id' => 100, 'title' => 'CallLog'],
            ],
            registers: [['id' => 3, 'schemas' => [99, 100]]],
        );

        $samples = $provider->metrics();
        $latency = $this->sampleByName($samples, 'api_product_latency_seconds');

        $byQuantile = [];
        foreach ($latency->samples as $point) {
            if ($point['labels']['product'] === 'prod-a') {
                $byQuantile[$point['labels']['quantile']] = $point['value'];
            }
        }

        // Nearest-rank over [10,20,...,100]ms: p50 -> index ceil(0.5*10)-1=4 -> 50ms=0.05s.
        $this->assertSame(0.05, $byQuantile['0.5']);
        // p95 -> index ceil(0.95*10)-1=9 -> 100ms=0.1s.
        $this->assertSame(0.1, $byQuantile['0.95']);
        // p99 -> index ceil(0.99*10)-1=9 -> 100ms=0.1s.
        $this->assertSame(0.1, $byQuantile['0.99']);
    }//end testLatencyGaugeComputesPercentilesPerProduct()


    /**
     * REQ-PROM-013 — a product with zero inbound rows reports all three
     * quantile samples as 0, never omitted.
     *
     * @return void
     */
    public function testLatencyGaugeReportsZeroForTrafficFreeProduct(): void
    {
        $objectService = new class {
            public function searchObjects(array $query, bool $_rbac=true, bool $_multitenancy=true): array
            {
                $schemaId = ($query['@self']['schema'] ?? null);
                if ($schemaId === 99) {
                    return [['uuid' => 'uuid-a', 'productSlug' => 'prod-a']];
                }

                return [];
            }
        };

        $provider = $this->buildProvider(
            objectService: $objectService,
            schemas: [
                ['id' => 99, 'title' => 'API Product'],
                ['id' => 100, 'title' => 'CallLog'],
            ],
            registers: [['id' => 3, 'schemas' => [99, 100]]],
        );

        $samples = $provider->metrics();
        $latency = $this->sampleByName($samples, 'api_product_latency_seconds');

        $this->assertCount(3, $latency->samples);
        foreach ($latency->samples as $point) {
            $this->assertSame('prod-a', $point['labels']['product']);
            $this->assertEqualsWithDelta(0.0, $point['value'], 0.0001);
        }
    }//end testLatencyGaugeReportsZeroForTrafficFreeProduct()


    /**
     * REQ-PROM-013 — a query failure degrades to a zero-value fallback, HTTP
     * 200 still implied (the provider never throws).
     *
     * @return void
     */
    public function testLatencyGaugeQueryFailureFallsBackToZero(): void
    {
        $objectService = new class {
            public function searchObjects(array $query, bool $_rbac=true, bool $_multitenancy=true): array
            {
                throw new \RuntimeException('DB unavailable');
            }
        };

        $provider = $this->buildProvider(
            objectService: $objectService,
            schemas: [['id' => 99, 'title' => 'API Product']],
            registers: [['id' => 3, 'schemas' => [99]]],
        );

        $samples = $provider->metrics();
        $latency = $this->sampleByName($samples, 'api_product_latency_seconds');

        $this->assertCount(3, $latency->samples);
        foreach ($latency->samples as $point) {
            $this->assertSame(0, $point['value']);
        }
    }//end testLatencyGaugeQueryFailureFallsBackToZero()


    /**
     * REQ-PROM-012 — the errors_total gauge counts only statusCode>=400 rows
     * per product.
     *
     * @return void
     */
    public function testErrorsGaugeCountsOnlyErrorStatusRows(): void
    {
        $callLogRows = [
            ['direction' => 'inbound', 'product' => 'uuid-a', 'statusCode' => 200],
            ['direction' => 'inbound', 'product' => 'uuid-a', 'statusCode' => 404],
            ['direction' => 'inbound', 'product' => 'uuid-a', 'statusCode' => 500],
            ['direction' => 'inbound', 'product' => 'uuid-b', 'statusCode' => 200],
        ];

        $objectService = new class ($callLogRows) {
            /**
             * @param array $callLogRows Rows returned for the CallLog schema.
             */
            public function __construct(private array $callLogRows)
            {
            }

            public function searchObjects(array $query, bool $_rbac=true, bool $_multitenancy=true): array
            {
                $schemaId = ($query['@self']['schema'] ?? null);
                if ($schemaId === 99) {
                    return [
                        ['uuid' => 'uuid-a', 'productSlug' => 'prod-a'],
                        ['uuid' => 'uuid-b', 'productSlug' => 'prod-b'],
                    ];
                }

                if ($schemaId === 100) {
                    return $this->callLogRows;
                }

                return [];
            }
        };

        $provider = $this->buildProvider(
            objectService: $objectService,
            schemas: [
                ['id' => 99, 'title' => 'API Product'],
                ['id' => 100, 'title' => 'CallLog'],
            ],
            registers: [['id' => 3, 'schemas' => [99, 100]]],
        );

        $samples = $provider->metrics();
        $errors  = $this->sampleByName($samples, 'api_product_errors_total');

        $byProduct = [];
        foreach ($errors->samples as $point) {
            $byProduct[$point['labels']['product']] = $point['value'];
        }

        $this->assertSame(2, $byProduct['prod-a']);
        $this->assertSame(0, $byProduct['prod-b']);
    }//end testErrorsGaugeCountsOnlyErrorStatusRows()


    /**
     * Locate a MetricSample by its `name` in the provider's metrics() array.
     *
     * @param array<int, \OCA\OpenRegister\AppHost\Observability\MetricSample> $samples The provider's samples.
     * @param string                                                          $name    The metric name to find.
     *
     * @return \OCA\OpenRegister\AppHost\Observability\MetricSample
     */
    private function sampleByName(array $samples, string $name): \OCA\OpenRegister\AppHost\Observability\MetricSample
    {
        foreach ($samples as $sample) {
            if ($sample->name === $name) {
                return $sample;
            }
        }

        $this->fail('No MetricSample named '.$name.' was produced');
    }//end sampleByName()

}//end class

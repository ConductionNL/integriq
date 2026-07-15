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
 * @spec openspec/specs/prometheus-metrics/spec.md#req-prom-011-circuit-breaker-state-gauge
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
 * @spec openspec/specs/prometheus-metrics/spec.md#req-prom-011-circuit-breaker-state-gauge
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
        $this->assertCount(1, $samples);

        $sample = $samples[0];
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

        $this->assertCount(1, $samples);
        $this->assertSame('circuit_breaker_state', $samples[0]->name);
        $this->assertCount(1, $samples[0]->samples);
        $this->assertSame(0, $samples[0]->samples[0]['value']);
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

        $this->assertCount(1, $samples);
        $this->assertSame(0, $samples[0]->samples[0]['value']);
    }//end testUnavailableObjectServiceFallsBackToZeroValue()

}//end class

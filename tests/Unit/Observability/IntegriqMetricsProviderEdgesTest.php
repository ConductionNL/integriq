<?php

/**
 * Edge-case unit tests for IntegriqMetricsProvider.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Observability
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/prometheus-metrics/spec.md#requirement-per-api-product-latency-percentile-gauges-req-prom-013
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Observability;

use OCA\Integriq\Observability\IntegriqMetricsProvider;
use OCA\OpenRegister\AppHost\Observability\MetricSample;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * How the provider reads entity shapes, degrades when a service is missing,
 * and bounds the latency window.
 *
 * @covers \OCA\Integriq\Observability\IntegriqMetricsProvider
 * @uses \OCA\OpenRegister\AppHost\Observability\MetricSample
 */
class IntegriqMetricsProviderEdgesTest extends TestCase {

	/**
	 * Only the most recent 1000 response times (the tail of the sorted
	 * window) feed the percentiles; entities of every shape are read.
	 *
	 * @return void
	 */
	public function testLatencyIsBoundedToTheLast1000ResponseTimes(): void {
		$callLogs = [];
		foreach (range(1, 1500) as $ms) {
			$callLogs[] = ['direction' => 'inbound', 'product' => 'uuid-a', 'responseTime' => $ms];
		}

		// Ignored rows: outbound, non-numeric time, another product, a scalar.
		$callLogs[] = ['direction' => 'outbound', 'product' => 'uuid-a', 'responseTime' => 1];
		$callLogs[] = ['direction' => 'inbound', 'product' => 'uuid-a', 'responseTime' => 'slow'];
		$callLogs[] = ['direction' => 'inbound', 'product' => 'uuid-b', 'responseTime' => 1];
		$callLogs[] = 42;

		$provider = $this->provider(
			objectService: $this->objectService([1 => [$this->serialising(['uuid' => 'uuid-a', 'productSlug' => 'orders'])], 2 => $callLogs]),
			schemas: [
				(object)['id' => '1', 'title' => 'API Product'],
				$this->serialising(['id' => 2, 'title' => 'CallLog']),
				$this->serialisingToAString(),
			],
			registers: [
				$this->serialising(['id' => 3, 'schemas' => [['id' => 1], ['id' => '2'], 'not-an-id']]),
				['schemas' => [1, 2]],
				['id' => 4, 'schemas' => 'not-a-list'],
			],
		);

		$this->assertSame(
			[
				['labels' => ['product' => 'orders', 'quantile' => '0.5'], 'value' => 1.0],
				['labels' => ['product' => 'orders', 'quantile' => '0.95'], 'value' => 1.45],
				['labels' => ['product' => 'orders', 'quantile' => '0.99'], 'value' => 1.49],
			],
			$this->sample($provider->metrics(), 'api_product_latency_seconds')->samples
		);
	}//end testLatencyIsBoundedToTheLast1000ResponseTimes()

	/**
	 * Which service is missing.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function missing(): array {
		return [
			'object service' => ['OCA\OpenRegister\Service\ObjectService'],
			'schema mapper' => ['OCA\OpenRegister\Db\SchemaMapper'],
			'register mapper' => ['OCA\OpenRegister\Db\RegisterMapper'],
		];
	}//end missing()

	/**
	 * A service the container cannot resolve degrades the latency gauge to
	 * its zero placeholders instead of throwing.
	 *
	 * @param string $id The service that throws.
	 *
	 * @dataProvider missing
	 *
	 * @return void
	 */
	public function testAMissingServiceDegradesToPlaceholders(string $id): void {
		$searched = 0;
		$objectService = new class ($searched) {
			/**
			 * Constructor.
			 *
			 * @param integer $searched Counts the searches.
			 */
			public function __construct(private int &$searched) {
			}

			/**
			 * Search.
			 *
			 * @param array   $query          The query.
			 * @param boolean $_rbac          RBAC.
			 * @param boolean $_multitenancy Multitenancy.
			 *
			 * @return array
			 */
			public function searchObjects(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->searched++;
				return [['uuid' => 'uuid-a']];
			}
		};

		$provider = $this->provider(
			objectService: $objectService,
			schemas: [['id' => 1, 'title' => 'API Product'], ['id' => 2, 'title' => 'CallLog']],
			registers: [['id' => 3, 'schemas' => [1, 2]]],
			throwing: $id,
		);

		$this->assertSame(
			[
				['labels' => ['product' => '', 'quantile' => '0.5'], 'value' => 0],
				['labels' => ['product' => '', 'quantile' => '0.95'], 'value' => 0],
				['labels' => ['product' => '', 'quantile' => '0.99'], 'value' => 0],
			],
			$this->sample($provider->metrics(), 'api_product_latency_seconds')->samples
		);
		$this->assertSame(0, $searched);
	}//end testAMissingServiceDegradesToPlaceholders()

	/**
	 * A failing latency query is logged and degrades to the placeholders.
	 *
	 * @return void
	 */
	public function testAFailingLatencyQueryIsLogged(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$warnings = [];
		$logger->method('warning')->willReturnCallback(
			static function (string $message) use (&$warnings): void {
				$warnings[] = $message;
			}
		);

		$objectService = new class {
			/**
			 * Search; always fails.
			 *
			 * @param array   $query          The query.
			 * @param boolean $_rbac          RBAC.
			 * @param boolean $_multitenancy Multitenancy.
			 *
			 * @return array
			 */
			public function searchObjects(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				throw new RuntimeException('DB unavailable');
			}
		};

		$provider = $this->provider(
			objectService: $objectService,
			schemas: [['id' => 1, 'title' => 'API Product']],
			registers: [['id' => 3, 'schemas' => [1]]],
			logger: $logger,
		);

		$sample = $this->sample($provider->metrics(), 'api_product_latency_seconds');

		$this->assertSame([0, 0, 0], array_column($sample->samples, 'value'));
		$this->assertContains('integriq: api_product_latency_seconds query failed: DB unavailable', $warnings);
	}//end testAFailingLatencyQueryIsLogged()

	/**
	 * The sample with the given name.
	 *
	 * @param array<int, MetricSample> $samples The samples.
	 * @param string                   $name    The name.
	 *
	 * @return MetricSample
	 */
	private function sample(array $samples, string $name): MetricSample {
		foreach ($samples as $sample) {
			if ($sample->name === $name) {
				return $sample;
			}
		}

		$this->fail('No sample named ' . $name);
	}//end sample()

	/**
	 * An object service that answers per schema id.
	 *
	 * @param array<int, array> $bySchema Rows per schema id.
	 *
	 * @return object
	 */
	private function objectService(array $bySchema): object {
		return new class ($bySchema) {
			/**
			 * Constructor.
			 *
			 * @param array<int, array> $bySchema Rows per schema id.
			 */
			public function __construct(private readonly array $bySchema) {
			}

			/**
			 * Search one register/schema scope.
			 *
			 * @param array   $query          The query.
			 * @param boolean $_rbac          RBAC.
			 * @param boolean $_multitenancy Multitenancy.
			 *
			 * @return array
			 */
			public function searchObjects(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				if ($query['@self']['register'] !== 3 || $_rbac !== false || $_multitenancy !== false) {
					throw new RuntimeException('unexpected scope');
				}

				return ($this->bySchema[$query['@self']['schema']] ?? []);
			}
		};
	}//end objectService()

	/**
	 * An entity that serialises to the given array.
	 *
	 * @param array $data The data.
	 *
	 * @return object
	 */
	private function serialising(array $data): object {
		return new class ($data) {
			/**
			 * Constructor.
			 *
			 * @param array $data The data.
			 */
			public function __construct(private readonly array $data) {
			}

			/**
			 * Serialise.
			 *
			 * @return array
			 */
			public function jsonSerialize(): array {
				return $this->data;
			}
		};
	}//end serialising()

	/**
	 * An entity whose serialisation is not an array; its public properties
	 * are read instead.
	 *
	 * @return object
	 */
	private function serialisingToAString(): object {
		return new class {
			/**
			 * The title.
			 *
			 * @var string
			 */
			public string $title = 'Something else';

			/**
			 * Serialise.
			 *
			 * @return string
			 */
			public function jsonSerialize(): string {
				return 'not an array';
			}
		};
	}//end serialisingToAString()

	/**
	 * A provider over the given doubles.
	 *
	 * @param object               $objectService The object service.
	 * @param array                $schemas       Schema rows.
	 * @param array                $registers     Register rows.
	 * @param string|null          $throwing      A service id the container cannot resolve.
	 * @param LoggerInterface|null $logger        The logger.
	 *
	 * @return IntegriqMetricsProvider
	 */
	private function provider(
		object $objectService,
		array $schemas,
		array $registers,
		?string $throwing = null,
		?LoggerInterface $logger = null,
	): IntegriqMetricsProvider {
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findAll')->willReturn($schemas);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('findAll')->willReturn($registers);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objectService, $schemaMapper, $registerMapper, $throwing): object {
				if ($id === $throwing) {
					throw new RuntimeException('not registered');
				}

				return match ($id) {
					'OCA\OpenRegister\Service\ObjectService' => $objectService,
					'OCA\OpenRegister\Db\SchemaMapper' => $schemaMapper,
					'OCA\OpenRegister\Db\RegisterMapper' => $registerMapper,
				};
			}
		);

		return new IntegriqMetricsProvider($container, ($logger ?? $this->createMock(LoggerInterface::class)));
	}//end provider()
}//end class

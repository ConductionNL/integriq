<?php

/**
 * Golden characterization of DashboardDatasourceService::resolve().
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Datasource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/dashboard-http-datasource/specs/dashboard-http-datasource/spec.md#requirement-resolve-endpoint-returns-a-single-value-from-a-named-source
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Datasource;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\Datasource\DashboardDatasourceService;
use OCA\Integriq\Service\Datasource\JsonPathLiteEvaluator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Resolves a sequence of values against one cache and records every cache
 * read and write, upstream call, log line and answer against a golden file.
 * Regenerate it on the code it must describe with DATASOURCE_GOLDEN=1.
 *
 * @covers \OCA\Integriq\Service\Datasource\DashboardDatasourceService
 * @uses   \OCA\Integriq\Service\Datasource\JsonPathLiteEvaluator
 */
class DashboardDatasourceResolveGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../../fixtures/datasource/resolve.json';

	/**
	 * Everything the resolves read, wrote, called and logged, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $trace = [];

	/**
	 * The cache store.
	 *
	 * @var array<string, mixed>
	 */
	private array $store = [];

	/**
	 * The resolves, in order: name => [sourceId, valueExpr, params, ttl, step before].
	 *
	 * @return array<string, array{0: string, 1: string, 2: array, 3: int|null, 4: string|null}>
	 */
	private function resolves(): array {
		return [
			'fetch' => ['src-ok', '$.data.count', ['b' => 2, 'url' => 'https://evil.test', 'a' => 1, 'host' => 'evil.test'], null, null],
			'fetch-again-hits-cache' => ['src-ok', '$.data.count', ['a' => 1, 'b' => 2], null, null],
			'other-expression' => ['src-ok', '$.data.items[1].name', [], null, null],
			'requested-ttl-under-max' => ['src-maxttl', '$.data.count', [], 3, null],
			'requested-ttl-over-max' => ['src-maxttl', '$.data.name', [], 50, null],
			'requested-ttl-zero' => ['src-ttl0', '$.data.name', [], 0, null],
			'flaky-fetch' => ['src-flaky', '$.data.count', ['a' => 1], null, null],
			'upstream-500-serves-stale' => ['src-flaky', '$.data.count', ['a' => 1], null, 'expire'],
			'upstream-throws-serves-stale' => ['src-throws', '$.data.count', [], null, null],
			'status-199' => ['src-199', '$.data.count', [], null, null],
			'status-300' => ['src-300', '$.data.count', [], null, null],
			'status-200' => ['src-200', '$.data.count', [], null, null],
			'status-299' => ['src-299', '$.data.count', [], null, null],
			'body-not-json' => ['src-text', '$.data.count', [], null, null],
			'body-scalar-json' => ['src-scalar', '$', [], null, null],
			'rate-1' => ['src-busy', '$.data.count', ['n' => 1], null, null],
			'rate-2' => ['src-busy', '$.data.count', ['n' => 2], null, null],
			'rate-3-limited-no-stale' => ['src-busy', '$.data.count', ['n' => 3], null, null],
			'rate-4-limited-stale' => ['src-busy', '$.data.count', ['n' => 1], null, 'expire'],
			'not-authorized' => ['src-denied', '$.data.count', [], null, null],
		];

	}//end resolves()

	/**
	 * Build the service over a recording cache and recording mocks.
	 *
	 * @return DashboardDatasourceService
	 */
	private function service(): DashboardDatasourceService {
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(
			function ($key): mixed {
				$this->trace[] = ['cacheGet' => $this->stableKey(key: (string)$key)];
				return ($this->store[$key] ?? null);
			}
		);
		$cache->method('set')->willReturnCallback(
			function ($key, $value, $ttl = 0): bool {
				$recorded = $value;
				if (is_array($value) === true) {
					$recorded['fetchedAt'] = $this->stableTime(time: ($value['fetchedAt'] ?? null));
				}

				$this->trace[] = ['cacheSet' => $this->stableKey(key: (string)$key), 'value' => $recorded, 'ttl' => $ttl];
				$this->store[$key] = $value;
				return true;
			}
		);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturnCallback(
			function (string $prefix) use ($cache): ICache {
				$this->trace[] = ['cachePrefix' => $prefix];
				return $cache;
			}
		);

		$objects = $this->createMock(OrObjectService::class);
		$objects->method('find')->willReturnCallback(
			function (...$args): ObjectEntity {
				$this->trace[] = ['find' => $args];
				$id = (string)($args['id'] ?? $args[0]);
				if ($id === 'src-denied') {
					throw new NotAuthorizedException('no read access');
				}

				$data = ['name' => 'Source ' . $id];
				if ($id === 'src-maxttl') {
					$data['dashboardDatasource'] = ['maxTtl' => 20];
				}

				$source = new ObjectEntity();
				$source->setUuid('uuid-' . $id);
				$source->setObject($data);
				return $source;
			}
		);

		$calls = $this->createMock(CallService::class);
		$calls->method('call')->willReturnCallback(
			function (...$args): ObjectEntity {
				$source = ($args['source'] ?? $args[0]);
				unset($args['source']);
				$this->trace[] = ['call' => $source->getUuid(), 'args' => $args];
				return $this->callLog(sourceUuid: (string)$source->getUuid());
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message) use ($level): void {
					$this->trace[] = ['log' => $level, 'message' => (string)$message];
				}
			);
		}

		return new DashboardDatasourceService(
			orObjectService: $objects,
			callService: $calls,
			evaluator: new JsonPathLiteEvaluator(),
			cacheFactory: $factory,
			logger: $logger,
			rateLimitPerWindow: 2,
			rateLimitWindowSeconds: 3600,
		);

	}//end service()

	/**
	 * The call log the upstream answers a source with.
	 *
	 * @param string $sourceUuid The source uuid.
	 *
	 * @return ObjectEntity
	 */
	private function callLog(string $sourceUuid): ObjectEntity {
		$status = 200;
		$body = json_encode(['data' => ['count' => 42, 'name' => 'Teller', 'items' => [['name' => 'a'], ['name' => 'b']]]]);
		$statuses = ['uuid-src-199' => 199, 'uuid-src-300' => 300, 'uuid-src-299' => 299];
		if (isset($statuses[$sourceUuid]) === true) {
			$status = $statuses[$sourceUuid];
		}

		if ($sourceUuid === 'uuid-src-throws') {
			throw new RuntimeException('connection refused');
		}

		if ($this->upstreamFails === true) {
			$status = 500;
		}

		if ($sourceUuid === 'uuid-src-text') {
			$body = 'plain text';
		}

		if ($sourceUuid === 'uuid-src-scalar') {
			$body = '17';
		}

		$log = new ObjectEntity();
		$log->setUuid('log-1');
		$log->setObject(['response' => ['statusCode' => $status, 'body' => $body]]);
		return $log;

	}//end callLog()

	/**
	 * Whether the upstream answers 500 for the current resolve.
	 *
	 * @var boolean
	 */
	private bool $upstreamFails = false;

	/**
	 * A cache key with the time window of a rate-limit key masked.
	 *
	 * @param string $key The cache key.
	 *
	 * @return string
	 */
	private function stableKey(string $key): string {
		return (string)preg_replace('/^(oc_ddc_rl_[0-9a-f]+)_\d+$/', '$1_<window>', $key);

	}//end stableKey()

	/**
	 * A fixture time as is, or `now` for a time the code took from the clock.
	 *
	 * @param mixed $time The recorded time.
	 *
	 * @return mixed
	 */
	private function stableTime(mixed $time): mixed {
		if (is_string($time) === true && $time !== '') {
			return 'now';
		}

		return $time;

	}//end stableTime()

	/**
	 * Every resolve in the sequence matches the golden file.
	 *
	 * @return void
	 */
	public function testEveryResolveMatchesTheGoldenFile(): void {
		$service = $this->service();
		$actual = ['construct' => $this->trace];
		foreach ($this->resolves() as $name => [$sourceId, $valueExpr, $params, $ttl, $before]) {
			$this->trace = [];
			$this->upstreamFails = ($before === 'expire');
			if ($before === 'expire') {
				foreach (array_keys($this->store) as $key) {
					if (str_starts_with((string)$key, 'fresh_') === true) {
						unset($this->store[$key]);
					}
				}
			}

			try {
				$outcome = ['return' => $service->resolve($sourceId, $valueExpr, $params, $ttl)];
				$outcome['return']['fetchedAt'] = $this->stableTime(time: $outcome['return']['fetchedAt']);
			} catch (\Throwable $exception) {
				$outcome = ['throws' => get_class($exception), 'message' => $exception->getMessage()];
			}

			$actual[$name] = ['trace' => $this->trace, 'outcome' => $outcome];
		}

		$encoded = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n";
		if (getenv('DATASOURCE_GOLDEN') === '1') {
			if (is_dir(dirname(self::GOLDEN)) === false) {
				mkdir(dirname(self::GOLDEN), 0777, true);
			}

			file_put_contents(self::GOLDEN, $encoded);
		}

		$this->assertSame((string)file_get_contents(self::GOLDEN), $encoded);

	}//end testEveryResolveMatchesTheGoldenFile()
}//end class

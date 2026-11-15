<?php

/**
 * Golden characterization of SynchronizationService::fetchAllPagesOptimized().
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/synchronization-engine/spec.md#requirement-fetch-completeness-tracking-during-source-pagination-req-009
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use Exception;
use GuzzleHttp\Promise\FulfilledPromise;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\SynchronizationApprovalGate;
use OCA\Integriq\Service\SynchronizationLogService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Every branch of fetchAllPagesOptimized(), recorded against a golden file:
 * the objects and completeness it returns and every call, prefetch, count,
 * save and log call.
 *
 * Regenerate the golden file on the code it must describe with
 * FETCH_ALL_PAGES_GOLDEN=1.
 */
class SynchronizationFetchAllPagesTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/synchronization/fetch-all-pages.json';

	/**
	 * Every collaborator call, in order.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $log = [];

	/**
	 * The current case.
	 *
	 * @var array<string, mixed>
	 */
	private array $case = [];

	/**
	 * The pages the sequential call still answers with.
	 *
	 * @var list<mixed>
	 */
	private array $pages = [];

	/**
	 * A call log answering with one page.
	 *
	 * @param mixed $page The page: a body array, or `status`/`headers`/`body` overrides.
	 *
	 * @return ObjectEntity
	 */
	private function callLog(mixed $page): ObjectEntity {
		$status = 200;
		$headers = [];
		$body = $page;
		if (is_array($page) === true && isset($page['__status']) === true) {
			$status = $page['__status'];
			$headers = ($page['__headers'] ?? []);
			$body = ($page['__body'] ?? []);
		}

		$callLog = new ObjectEntity();
		$callLog->setObject(['statusCode' => $status, 'response' => ['statusCode' => $status, 'headers' => $headers, 'body' => json_encode($body)]]);
		return $callLog;
	}//end callLog()

	/**
	 * The engine over recording collaborators.
	 *
	 * @return SynchronizationService
	 */
	private function service(): SynchronizationService {
		$calls = $this->createMock(CallService::class);
		$calls->method('applyConfigDot')->willReturnArgument(0);
		$calls->method('call')->willReturnCallback(
			function ($source, string $endpoint = '', string $method = 'GET', array $config = []) {
				$this->log[] = ['call.call', $endpoint, ($config['pagination'] ?? null)];
				$page = array_shift($this->pages);
				if ($page === 'throw') {
					throw new Exception('source down');
				}

				return $this->callLog(($page ?? ['items' => []]));
			}
		);
		$calls->method('callAsync')->willReturnCallback(
			function ($source, string $endpoint = '', string $method = 'GET', array $config = []) {
				$index = (int)($config['pagination']['index'] ?? 0);
				$this->log[] = ['call.callAsync', $endpoint, $index];
				return new FulfilledPromise($this->callLog(($this->case['prefetch'][$index] ?? ['items' => []])));
			}
		);

		$or = $this->createMock(ORObjectService::class);
		$or->method('count')->willReturnCallback(
			function () {
				$this->log[] = ['or.count'];
				if (($this->case['known'] ?? 0) === 'throw') {
					throw new Exception('count failed');
				}

				return ($this->case['known'] ?? 0);
			}
		);
		$or->method('saveObject')->willReturnCallback(
			function ($object) {
				$this->log[] = ['or.saveObject', ($object['currentPage'] ?? null)];
				return new ObjectEntity();
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'debug', 'error', 'info'] as $level) {
			$logger->method($level)->willReturnCallback(
				function ($message, array $context = []) use ($level) {
					$this->log[] = ['logger.' . $level, $message, $context];
				}
			);
		}

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);

		return new SynchronizationService(
			$calls,
			$this->createMock(MappingService::class),
			$this->createMock(ContainerInterface::class),
			$or,
			$this->createMock(ObjectService::class),
			$logger,
			$this->createMock(SynchronizationLogService::class),
			$appConfig,
			$this->createMock(SynchronizationApprovalGate::class),
		);
	}//end service()

	/**
	 * The cases: source config, arguments, sequential pages and prefetched pages.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$a = ['items' => [['id' => 'a'], ['id' => 'b']]];
		$b = ['items' => [['id' => 'c']]];
		$empty = ['items' => []];
		$link = fn (int $last, array $body) => ['__status' => 200, '__headers' => ['Link' => '<https://api.invalid/items?page=' . $last . '>; rel="last"'], '__body' => $body];

		return [
			'one-page-then-empty' => ['pages' => [$a, $empty]],
			'two-pages' => ['pages' => [$a, $b, $empty]],
			'first-page-empty' => ['pages' => [$empty]],
			'failed-first-page' => ['pages' => [['__status' => 500, '__body' => ['error' => 'x']]]],
			'failed-second-page' => ['pages' => [$a, ['__status' => 502, '__body' => []]]],
			'call-throws' => ['pages' => ['throw']],
			'test-mode' => ['pages' => [$a, $b], 'isTest' => true],
			'test-mode-empty' => ['pages' => [$empty], 'isTest' => true],
			'max-pages-reached' => ['config' => ['maxPages' => 2], 'pages' => [$a, $b, $a]],
			'max-pages-one' => ['config' => ['maxPages' => 1], 'pages' => [$a, $b]],
			'max-pages-zero' => ['config' => ['maxPages' => 0], 'pages' => [$a]],
			'next-endpoint' => ['pages' => [($a + ['next' => 'https://api.invalid/items?cursor=2']), ($b + ['next' => null]), $empty]],
			'next-endpoint-then-missing' => ['pages' => [($a + ['next' => 'https://api.invalid/items?cursor=2']), $b, $a, $empty]],
			'second-fetch-after-link-last' => ['pages' => [$link(1, $a), $a, $b, $empty], 'twice' => true],
			'next-endpoint-known' => ['next' => true, 'pages' => [($a + ['next' => 'https://api.invalid/items?cursor=2']), $b]],
			'next-endpoint-same' => ['pages' => [($a + ['next' => 'https://api.invalid/items']), $b]],
			'page-numbers-forced' => ['next' => false, 'pages' => [($a + ['next' => 'https://api.invalid/items?cursor=2']), $empty]],
			'link-last-stops' => ['pages' => [$link(2, $a), $b, $a]],
			'link-last-prefetch' => ['pages' => [$link(3, $a)], 'prefetch' => [2 => $b, 3 => $a]],
			'link-last-one' => ['pages' => [$link(1, $a), $b]],
			'predicted-prefetch' => ['config' => ['pageSize' => 2], 'known' => 5, 'pages' => [], 'prefetch' => [1 => $a, 2 => $b, 3 => $empty]],
			'predicted-one-page' => ['config' => ['pageSize' => 2], 'known' => 2, 'pages' => [$a, $empty]],
			'predicted-zero-start' => ['config' => ['pageSize' => 2], 'known' => 5, 'currentPage' => 0, 'pages' => [$a, $empty]],
			'predicted-count-throws' => ['config' => ['pageSize' => 2], 'known' => 'throw', 'pages' => [$a, $empty]],
			'predicted-next-endpoint' => ['config' => ['pageSize' => 2], 'known' => 5, 'next' => true, 'pages' => [$a]],
			'prefetch-window-clamped' => ['config' => ['prefetchConcurrency' => 99], 'pages' => [$link(2, $a)], 'prefetch' => [2 => $b]],
			'rate-limited-source-persists' => ['source' => ['rateLimitLimit' => 10], 'pages' => [$a, $b, $empty]],
			'memory-ceiling' => ['config' => ['maxMemoryPercent' => 1], 'memory' => true, 'pages' => [$a, $b]],
			'memory-ceiling-off' => ['config' => ['maxMemoryPercent' => 0], 'memory' => true, 'pages' => [$a, $empty]],
			'start-page-three' => ['currentPage' => 3, 'pages' => [$a, $empty]],
		];
	}//end cases()

	/**
	 * Every case behaves as the golden file records.
	 *
	 * @return void
	 */
	public function testEveryCaseMatchesTheGoldenFile(): void {
		$method = new ReflectionMethod(SynchronizationService::class, 'fetchAllPagesOptimized');

		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$this->case = $case;
			$service = $this->service();
			$this->log = [];
			$this->pages = $case['pages'];
			$synchronization = ['id' => 7, 'uuid' => 'sync-1', 'sourceConfig' => array_merge(['resultsPosition' => 'items'], ($case['config'] ?? []))];
			$source = array_merge(['id' => 'src-1', 'uuid' => 'src-1', 'location' => 'https://api.invalid', '_transient' => true], ($case['source'] ?? []));

			$memoryLimit = (string)ini_get('memory_limit');
			if (($case['memory'] ?? false) === true) {
				ini_set('memory_limit', (string)(memory_get_usage(true) * 2));
			}

			try {
				$outcome = ['return' => $method->invoke($service, $source, '/items', ['query' => ['q' => 1]], $synchronization, ($case['currentPage'] ?? 1), ($case['isTest'] ?? false), ($case['next'] ?? null))];
				if (($case['twice'] ?? false) === true) {
					$outcome['second'] = $method->invoke($service, $source, '/items', ['query' => ['q' => 1]], $synchronization, 1, false, null);
				}
			} catch (\Throwable $exception) {
				$outcome = ['throws' => get_class($exception), 'message' => preg_replace('/, called in .*$/', '', $exception->getMessage())];
			} finally {
				ini_set('memory_limit', $memoryLimit);
			}

			$actual[$name] = ['outcome' => $outcome, 'log' => $this->log];
		}//end foreach

		$encoded = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n";
		$encoded = (string)preg_replace('/"(used|limit)": \d+/', '"$1": "N"', $encoded);
		if (getenv('FETCH_ALL_PAGES_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $encoded);
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), json_decode($encoded, true));
	}//end testEveryCaseMatchesTheGoldenFile()
}//end class

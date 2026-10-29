<?php

/**
 * Golden characterization of SynchronizationService::getAllObjectsFromApi().
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
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\Helper\ExecutionTraceContext;
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

/**
 * Every branch of getAllObjectsFromApi(), recorded against a golden file:
 * each collaborator call with its arguments, the returned objects (or the
 * exception) and the fetch info written back by reference.
 *
 * Regenerate the golden file on the code it must describe with
 * GET_ALL_OBJECTS_GOLDEN=1.
 */
class SynchronizationGetAllObjectsFromApiTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/synchronization/get-all-objects-from-api.json';

	/**
	 * Every collaborator call, in order.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $log = [];

	/**
	 * The bodies the source answers, page by page.
	 *
	 * @var list<mixed>
	 */
	private array $pages = [];

	/**
	 * The engine over recording collaborators.
	 *
	 * @return SynchronizationService
	 */
	private function service(): SynchronizationService {
		$calls = $this->createMock(CallService::class);
		$calls->method('applyConfigDot')->willReturnCallback(
			function (array $config) {
				$this->log[] = ['call.applyConfigDot', $config];
				return $config;
			}
		);
		$calls->method('bufferCallLogs')->willReturnCallback(
			function (bool $enabled) {
				$this->log[] = ['call.bufferCallLogs', $enabled];
			}
		);
		$calls->method('flushCallLogs')->willReturnCallback(
			function () {
				$this->log[] = ['call.flushCallLogs'];
				return 0;
			}
		);
		$calls->method('call')->willReturnCallback(
			function ($source, string $endpoint = '', string $method = 'GET', array $config = [], bool $asynchronous = false, bool $createCertificates = true, bool $overruleAuth = false, bool $read = false, bool $runningSupportRequest = false, mixed $sink = null, ?ExecutionTraceContext $trace = null) {
				$this->log[] = ['call.call', $source->getUuid(), $endpoint, $method, $config];
				$page = array_shift($this->pages);
				if ($page === 'throw') {
					throw new Exception('source down');
				}

				$body = json_encode($page ?? ['items' => []]);
				if (is_array($page) === true && isset($page['__raw']) === true) {
					$body = $page['__raw'];
				}

				$callLog = new ObjectEntity();
				$callLog->setObject(['statusCode' => 200, 'response' => ['statusCode' => 200, 'headers' => [], 'body' => $body]]);
				return $callLog;
			}
		);

		$mapping = $this->createMock(MappingService::class);
		$mapping->method('renderTemplateString')->willReturnCallback(
			function (string $template, array $context) {
				$this->log[] = ['mapping.renderTemplateString', $template, $context];
				return 'R<' . $template . '>';
			}
		);

		$or = $this->createMock(ORObjectService::class);
		$or->method('find')->willReturnCallback(
			function ($id) {
				$this->log[] = ['or.find', (string)$id];
				$entity = new ObjectEntity();
				$entity->setUuid((string)$id);
				$entity->setObject(['uuid' => (string)$id, 'location' => 'https://found.invalid']);
				return $entity;
			}
		);
		$or->method('saveObject')->willReturnCallback(
			function ($object, ?string $register = null, ?string $schema = null, ?string $uuid = null) {
				$this->log[] = ['or.saveObject', $object, $register, $schema, $uuid];
				return new ObjectEntity();
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);

		return new SynchronizationService(
			$calls,
			$mapping,
			$this->createMock(ContainerInterface::class),
			$or,
			$this->createMock(ObjectService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(SynchronizationLogService::class),
			$appConfig,
			$this->createMock(SynchronizationApprovalGate::class),
		);
	}//end service()

	/**
	 * The cases: synchronization and source config overrides, arguments and pages.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$items = ['items' => [['id' => 'a'], ['id' => 'b']]];
		$relData = ['naam' => 'x', '@self' => ['relations' => ['zaak' => 'z-1', 'naam' => 'rel']]];

		return [
			'single-page' => ['pages' => [$items]],
			'single-page-test' => ['pages' => [$items], 'isTest' => true],
			'paginated' => ['config' => ['usesPagination' => true], 'pages' => [$items, ['items' => []]]],
			'pagination-string-false' => ['config' => ['usesPagination' => 'false'], 'pages' => [$items]],
			'pagination-garbage' => ['config' => ['usesPagination' => 'maybe'], 'pages' => [$items, ['items' => []]]],
			'pagination-absent' => ['config' => ['usesPagination' => null], 'unset' => ['usesPagination'], 'pages' => [$items, ['items' => []]]],
			'results-object' => ['config' => ['resultsPosition' => '_object', 'usesPagination' => true], 'pages' => [['id' => 'one', 'naam' => 'obj']]],
			'results-assoc-wrapped' => ['config' => ['resultsPosition' => 'item'], 'pages' => [['item' => ['id' => 'solo']]]],
			'endpoint-template-relations' => ['config' => ['endpoint' => '/zaken/{{ data.zaak }}'], 'data' => $relData, 'pages' => [$items]],
			'endpoint-template-no-data' => ['config' => ['endpoint' => '/zaken/{{ data.zaak }}'], 'pages' => [$items]],
			'endpoint-template-relations-not-array' => ['config' => ['endpoint' => '/z/{{ x }}'], 'data' => ['@self' => ['relations' => 'nope']], 'pages' => [$items]],
			'endpoint-half-template' => ['config' => ['endpoint' => '/z/{{ x'], 'pages' => [$items]],
			'endpoint-not-string' => ['config' => ['endpoint' => ['/z']], 'pages' => [$items]],
			'incremental-endpoint-and-query' => ['sync' => ['syncMode' => 'incremental', 'cursorWatermark' => 'w-1'], 'config' => ['endpoint' => '/z?since={{ cursor }}', 'query' => ['since' => '{{ cursor }}', 'n' => 5, 'plain' => 'p', 'half' => '{{ x']], 'data' => $relData, 'pages' => [$items]],
			'incremental-no-watermark' => ['sync' => ['syncMode' => 'incremental'], 'config' => ['query' => ['since' => '{{ cursor }}']], 'pages' => [$items]],
			'incremental-empty-query' => ['sync' => ['syncMode' => 'incremental'], 'config' => ['query' => []], 'pages' => [$items]],
			'incremental-query-relations-not-array' => ['sync' => ['syncMode' => 'incremental'], 'config' => ['query' => ['a' => '{{ b }}']], 'data' => ['@self' => ['relations' => 'nope']], 'pages' => [$items]],
			'full-mode-query-unrendered' => ['config' => ['query' => ['since' => '{{ cursor }}']], 'pages' => [$items]],
			'headers-and-query' => ['config' => ['headers' => ['X-A' => '1'], 'query' => ['q' => 'v']], 'pages' => [$items]],
			'body-hash' => ['config' => ['useDataAsRequestBody' => '#'], 'data' => ['a' => 1], 'pages' => [$items]],
			'body-dot-path' => ['config' => ['useDataAsRequestBody' => 'a.b'], 'data' => ['a' => ['b' => 'deep']], 'pages' => [$items]],
			'body-empty-string' => ['config' => ['useDataAsRequestBody' => ''], 'data' => ['a' => 1], 'pages' => [$items]],
			'merge-data-when-body-false' => ['config' => ['useDataAsRequestBody' => false], 'data' => ['extra' => 'yes'], 'pages' => [$items]],
			'no-merge-when-body-unset' => ['data' => ['extra' => 'yes'], 'pages' => [$items]],
			'no-merge-empty-data' => ['config' => ['useDataAsRequestBody' => false], 'data' => [], 'pages' => [$items]],
			'rate-limited-source-page' => ['source' => ['rateLimitLimit' => 10], 'sync' => ['currentPage' => 3], 'config' => ['usesPagination' => true], 'pages' => [$items, ['items' => []]]],
			'rate-limited-source-no-page' => ['source' => ['rateLimitLimit' => 10], 'sync' => ['currentPage' => null], 'config' => ['usesPagination' => true], 'pages' => [$items, ['items' => []]]],
			'current-page-ignored' => ['sync' => ['currentPage' => 4], 'config' => ['usesPagination' => true], 'pages' => [$items, ['items' => []]]],
			'rate-limit-exceeded' => ['source' => ['rateLimitRemaining' => 0, 'rateLimitReset' => (time() + 3600)], 'pages' => [$items]],
			'source-by-id' => ['resolved' => false, 'pages' => [$items]],
			'call-throws' => ['pages' => ['throw']],
			'jsonl-first-batch' => ['config' => ['format' => 'jsonl', 'usesPagination' => true], 'pages' => [['__raw' => "{\"id\":\"l1\"}\n{\"id\":\"l2\"}\n"]]],
			'jsonl-resume' => ['sync' => ['currentPage' => 2, 'currentPageVersion' => 'v0'], 'config' => ['format' => 'jsonl', 'pageSize' => 1, 'resultsPosition' => 'root'], 'pages' => [['__raw' => "{\"id\":\"l1\"}\n{\"id\":\"l2\"}\n{\"id\":\"l3\"}\n"]]],
			'jsonl-test' => ['isTest' => true, 'config' => ['format' => 'jsonl'], 'pages' => [['__raw' => "{\"id\":\"l1\"}\n"]]],
			'no-uuid-legacy-id' => ['sync' => ['uuid' => null, 'id' => 'legacy-id'], 'pages' => [$items]],
		];
	}//end cases()

	/**
	 * Run one case and describe what happened.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed>
	 */
	private function runCase(array $case): array {
		$sourceConfig = array_merge(['endpoint' => '/items', 'resultsPosition' => 'items', 'usesPagination' => false], ($case['config'] ?? []));
		foreach (($case['unset'] ?? []) as $key) {
			unset($sourceConfig[$key]);
		}

		$synchronization = array_merge(
			['id' => 7, 'uuid' => 'sync-1', 'sourceId' => 'src-1', 'sourceConfig' => $sourceConfig],
			($case['sync'] ?? [])
		);
		$resolved = null;
		if (($case['resolved'] ?? true) === true) {
			$resolved = array_merge(['uuid' => 'src-1', 'id' => 'src-1', 'location' => 'https://api.invalid'], ($case['source'] ?? []));
		}

		$service = $this->service();
		$this->log = [];
		$this->pages = $case['pages'];
		$fetchInfo = null;
		try {
			$outcome = ['return' => $service->getAllObjectsFromApi(
				synchronization: $synchronization,
				isTest: ($case['isTest'] ?? false),
				data: ($case['data'] ?? null),
				fetchInfo: $fetchInfo,
				resolvedSource: $resolved
			)];
		} catch (\Throwable $exception) {
			$outcome = ['throws' => get_class($exception), 'message' => preg_replace('/, called in .*$/', '', $exception->getMessage())];
		}

		return ['outcome' => $outcome, 'fetchInfo' => $fetchInfo, 'log' => $this->log];
	}//end runCase()

	/**
	 * Every case behaves as the golden file records.
	 *
	 * @return void
	 */
	public function testEveryCaseMatchesTheGoldenFile(): void {
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$actual[$name] = $this->runCase($case);
		}

		$encoded = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)) . "\n";
		if (getenv('GET_ALL_OBJECTS_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $encoded);
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), json_decode($encoded, true));
	}//end testEveryCaseMatchesTheGoldenFile()
}//end class

<?php

/**
 * Unit tests for reading the page count off a page's response headers.
 *
 * The developer.overheid.nl OSS-register answers its listing with a `Link`
 * header whose `rel` values are single-quoted and with a `Total-Pages` header.
 * The engine read only a double-quoted `rel="last"`, so a listing that fits on
 * one page was followed by a request for a page that does not exist.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\SynchronizationApprovalGate;
use OCA\Integriq\Service\SynchronizationLogService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IAppConfig;
use OCP\ISession;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * @spec openspec/changes/sources-developer-overheid/specs/developer-overheid-sources/spec.md#requirement-a-listing-that-fits-on-one-page-ends-after-one-request-req-dos-004
 */
class SynchronizationServiceLastPageTest extends TestCase {

	/**
	 * Read the page count from a set of response headers.
	 *
	 * @param array $headers The response headers.
	 *
	 * @return int|null The page count.
	 */
	private function lastPage(array $headers): ?int {
		$service = (new ReflectionClass(SynchronizationService::class))->newInstanceWithoutConstructor();
		$method = new ReflectionMethod(SynchronizationService::class, 'parseLastPageFromHeaders');
		$method->setAccessible(true);

		return $method->invoke($service, $headers);
	}//end lastPage()

	/**
	 * Every quoting of `rel` reads the page number.
	 *
	 * @param array $headers The response headers.
	 * @param int $expected The page count.
	 *
	 * @return void
	 */
	#[DataProvider('linkHeaders')]
	public function testTheLinkHeaderIsReadWhateverTheQuoting(array $headers, int $expected): void {
		$this->assertSame($expected, $this->lastPage($headers));
	}//end testTheLinkHeaderIsReadWhateverTheQuoting()

	/**
	 * Link headers and the last page they name.
	 *
	 * @return array<string, array{0: array, 1: int}>
	 */
	public static function linkHeaders(): array {
		return [
			'single-quoted, the register' => [
				['Link' => ["<https://api.example.invalid/repositories?page=2>; rel='next', <https://api.example.invalid/repositories?page=3>; rel='last'"]],
				3,
			],
			'double-quoted, GitHub' => [
				['Link' => ['<https://api.github.com/search/code?q=x&page=2>; rel="next", <https://api.github.com/search/code?q=x&page=4>; rel="last"']],
				4,
			],
			'bare' => [
				['Link' => ['<https://api.example.invalid/repositories?perPage=100&page=7>; rel=last']],
				7,
			],
			'a lower-case header name and a plain string value' => [
				['link' => "<https://api.example.invalid/repositories?page=5>; rel='last'"],
				5,
			],
			'Link wins over Total-Pages' => [
				['Link' => ['<https://api.example.invalid/repositories?page=3>; rel="last"'], 'Total-Pages' => ['9']],
				3,
			],
		];
	}//end linkHeaders()

	/**
	 * Without a Link count, a Total-Pages header gives it, in any casing.
	 *
	 * @return void
	 */
	public function testTotalPagesIsTheFallback(): void {
		$this->assertSame(1, $this->lastPage(['Total-Pages' => ['1']]));
		$this->assertSame(2, $this->lastPage(['total-pages' => ['2']]));
		$this->assertSame(
			4,
			$this->lastPage(['Link' => ["<https://api.example.invalid/repositories?page=2>; rel='next'"], 'Total-Pages' => ['4']]),
			'A Link header without a last page leaves the count to Total-Pages.'
		);
	}//end testTotalPagesIsTheFallback()

	/**
	 * Headers that do not give a count leave it unknown.
	 *
	 * @param array $headers The response headers.
	 *
	 * @return void
	 */
	#[DataProvider('noCount')]
	public function testNoUsableCountIsNull(array $headers): void {
		$this->assertNull($this->lastPage($headers));
	}//end testNoUsableCountIsNull()

	/**
	 * Headers without a usable page count.
	 *
	 * @return array<string, array{0: array}>
	 */
	public static function noCount(): array {
		return [
			'no headers' => [[]],
			'rel is not last' => [['Link' => ["<https://api.example.invalid/repositories?page=2>; rel='next'"]]],
			'rel only starts with last' => [['Link' => ['<https://api.example.invalid/repositories?page=2>; rel=lastly']]],
			'mismatched quotes' => [['Link' => ["<https://api.example.invalid/repositories?page=2>; rel=\"last'"]]],
			'no page parameter' => [['Link' => ['<https://api.example.invalid/repositories?cursor=abc>; rel="last"']]],
			'Total-Pages zero' => [['Total-Pages' => ['0']]],
			'Total-Pages not a number' => [['Total-Pages' => ['many']]],
			'Total-Pages negative' => [['Total-Pages' => ['-1']]],
		];
	}//end noCount()

	/**
	 * A later page without either header keeps the count found earlier.
	 *
	 * @return void
	 */
	public function testALaterPageWithoutACountKeepsTheEarlierOne(): void {
		$service = (new ReflectionClass(SynchronizationService::class))->newInstanceWithoutConstructor();
		(function (LoggerInterface $logger): void {
			$this->logger = $logger;
		})->call($service, $this->createMock(LoggerInterface::class));

		$fetch = new ReflectionMethod(SynchronizationService::class, 'fetchSinglePageData');
		$fetch->setAccessible(true);
		$count = new ReflectionProperty(SynchronizationService::class, 'lastPageFromLink');
		$count->setAccessible(true);
		$count->setValue($service, null);

		foreach ([1 => ['Total-Pages' => ['2']], 2 => []] as $page => $headers) {
			$callLog = $this->callLog(body: [['id' => 'repo-' . $page]], headers: $headers);
			(function (ObjectEntity $callLog, int $page): void {
				$this->pagePrefetch = [$page => $callLog];
			})->call($service, $callLog, $page);

			$fetch->invoke(
				$service,
				['location' => 'https://api.example.invalid', 'configuration' => []],
				'/repositories',
				['pagination' => ['index' => $page, 'page' => $page]],
				['sourceConfig' => ['resultsPosition' => '_root']]
			);

			$this->assertSame(2, $count->getValue($service), sprintf('After page %d the count is still 2.', $page));
		}
	}//end testALaterPageWithoutACountKeepsTheEarlierOne()

	/**
	 * Total-Pages: 1 on a one-page register means one request, never a page 2.
	 *
	 * @return void
	 */
	public function testAOnePageListingIsOneRequest(): void {
		$records = [];
		for ($i = 1; $i <= 92; $i++) {
			$records[] = ['id' => sprintf('00000000-0000-0000-0000-%012d', $i)];
		}

		[$objects, $calls] = $this->synchronize(
			pages: [
				1 => ['body' => $records, 'headers' => ['Total-Pages' => ['1'], 'Total-Count' => ['92']]],
			]
		);

		$this->assertSame(1, $calls, 'Page 2 must not be requested.');
		$this->assertCount(92, $objects);
	}//end testAOnePageListingIsOneRequest()

	/**
	 * The control: without a count, the loop asks for page 2 to learn it is empty.
	 *
	 * @return void
	 */
	public function testWithoutACountTheEmptyPageRuleStillDecides(): void {
		[$objects, $calls] = $this->synchronize(
			pages: [
				1 => ['body' => [['id' => 'repo-1']], 'headers' => []],
			]
		);

		$this->assertSame(2, $calls);
		$this->assertCount(1, $objects);
	}//end testWithoutACountTheEmptyPageRuleStillDecides()

	/**
	 * Run a paginated listing through getAllObjectsFromApi() and count its requests.
	 *
	 * @param array<int, array{body: array, headers: array}> $pages The answers by page; a missing page is empty.
	 *
	 * @return array{0: array, 1: int} The objects and the number of requests sent.
	 */
	private function synchronize(array $pages): array {
		$callService = $this->createMock(CallService::class);
		$callService->method('applyConfigDot')->willReturnArgument(0);

		$calls = 0;
		$callService->method('call')->willReturnCallback(
			function (...$args) use (&$calls, $pages) {
				$calls++;
				$page = ($pages[$calls] ?? ['body' => [], 'headers' => []]);

				return $this->callLog(body: $page['body'], headers: $page['headers']);
			}
		);

		$orObjectService = ObjectServiceMockBuilder::make($this);
		$orObjectService->method('find')->willReturn(
			ObjectServiceMockBuilder::objectEntity(
				$this,
				['location' => 'https://api.example.invalid/oss-register/v1', 'enabled' => true],
				'source-uuid-dos'
			)
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);

		$service = new SynchronizationService(
			$callService,
			$this->createMock(MappingService::class),
			$this->createMock(ContainerInterface::class),
			$orObjectService,
			$this->createMock(ObjectService::class),
			$this->createMock(LoggerInterface::class),
			new SynchronizationLogService(
				ObjectServiceMockBuilder::make($this),
				$this->createMock(IUserSession::class),
				$this->createMock(ISession::class)
			),
			$appConfig,
			$this->createMock(SynchronizationApprovalGate::class),
		);

		$fetchInfo = null;
		$objects = $service->getAllObjectsFromApi(
			synchronization: [
				'id' => 'sync-uuid-dos',
				'uuid' => 'sync-uuid-dos',
				'sourceId' => 'source-uuid-dos',
				'sourceType' => 'api',
				'targetType' => 'register/schema',
				'targetId' => '1/2',
				'sourceConfig' => [
					'endpoint' => '/repositories',
					'resultsPosition' => '_root',
					'usesPagination' => true,
				],
			],
			isTest: false,
			data: null,
			fetchInfo: $fetchInfo
		);

		$this->assertTrue($fetchInfo['complete']);

		return [$objects, $calls];
	}//end synchronize()

	/**
	 * A CallLog shaped as CallService stores it, for one JSON page.
	 *
	 * @param array $body The decoded page.
	 * @param array $headers The response headers.
	 *
	 * @return ObjectEntity The CallLog.
	 */
	private function callLog(array $body, array $headers): ObjectEntity {
		return ObjectServiceMockBuilder::objectEntity(
			$this,
			[
				'statusCode' => 200,
				'response' => [
					'statusCode' => 200,
					'headers' => $headers,
					'body' => (string)json_encode($body),
					'encoding' => 'UTF-8',
				],
			],
			'call-log-' . md5((string)json_encode([$body, $headers]))
		);
	}//end callLog()
}//end class

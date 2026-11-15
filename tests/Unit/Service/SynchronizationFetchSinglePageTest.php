<?php

/**
 * Characterization tests for SynchronizationService::fetchSinglePageData().
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * Pins how one fetched page is read: where its call log comes from, which
 * answers fail the page, how the body is decoded and which parser reads it.
 *
 * @covers \OCA\Integriq\Service\SynchronizationService
 */
class SynchronizationFetchSinglePageTest extends TestCase {

	/**
	 * @var SynchronizationService
	 */
	private SynchronizationService $service;

	/**
	 * The log lines written, as [level, message, context].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array}>
	 */
	private array $logged = [];

	/**
	 * Set up a service without its constructor, with a recording logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->service = (new ReflectionClass(SynchronizationService::class))->newInstanceWithoutConstructor();
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logged[] = [$level, $message, $context];
				}
			);
		}

		(function (LoggerInterface $logger): void {
			$this->logger = $logger;
		})->call($this->service, $logger);
	}//end setUp()

	/**
	 * A call log as CallService records it.
	 *
	 * @param int|null   $statusCode The status code, or null for none.
	 * @param array|null $response   The response, or null for none.
	 *
	 * @return ObjectEntity The call log.
	 */
	private function callLog(?int $statusCode, ?array $response): ObjectEntity {
		$body = [];
		if ($statusCode !== null) {
			$body['statusCode'] = $statusCode;
		}

		if ($response !== null) {
			$body['response'] = $response + ['headers' => [], 'encoding' => 'UTF-8'];
		}

		$callLog = new ObjectEntity();
		$callLog->setUuid('call-1');
		$callLog->setObject($body);

		return $callLog;
	}//end callLog()

	/**
	 * Fetches page 1 from a prefetched call log.
	 *
	 * @param ObjectEntity $callLog The prefetched call log.
	 * @param array        $source  The source.
	 * @param string       $endpoint The endpoint.
	 *
	 * @return array The page.
	 */
	private function fetch(ObjectEntity $callLog, array $source = [], string $endpoint = '/items'): array {
		(function (ObjectEntity $callLog): void {
			$this->pagePrefetch = [1 => $callLog];
		})->call($this->service, $callLog);

		$fetch = new ReflectionMethod(SynchronizationService::class, 'fetchSinglePageData');

		return $fetch->invoke(
			$this->service,
			$source + ['location' => 'https://source.example'],
			$endpoint,
			['pagination' => ['index' => 1, 'page' => 1]],
			['sourceConfig' => ['resultsPosition' => '_root']]
		);
	}//end fetch()

	/**
	 * A private property of the service.
	 *
	 * @param string $name The property.
	 *
	 * @return mixed The value.
	 */
	private function read(string $name): mixed {
		return (fn () => $this->$name)->call($this->service);
	}//end read()

	/**
	 * A prefetched page is consumed from the prefetch and read like any page.
	 *
	 * @return void
	 */
	public function testAPrefetchedPageIsConsumed(): void {
		$page = $this->fetch($this->callLog(200, ['body' => '[{"id":1},{"id":2}]']));

		$this->assertSame(['objects' => [['id' => 1], ['id' => 2]], 'result' => [['id' => 1], ['id' => 2]]], $page);
		$this->assertSame([], $this->read('pagePrefetch'));
	}//end testAPrefetchedPageIsConsumed()

	/**
	 * A page not prefetched is called through CallService with the page's config.
	 *
	 * @return void
	 */
	public function testAPageNotPrefetchedIsCalled(): void {
		$callService = $this->createMock(CallService::class);
		$callService->expects($this->once())->method('call')
			->with($this->isInstanceOf(ObjectEntity::class), '/items', 'GET', ['pagination' => ['page' => 3]])
			->willReturn($this->callLog(200, ['body' => '{"items":[{"id":9}]}']));
		(function (CallService $callService): void {
			$this->callService = $callService;
			$this->pagePrefetch = [1 => 'not this one'];
		})->call($this->service, $callService);

		$fetch = new ReflectionMethod(SynchronizationService::class, 'fetchSinglePageData');
		$page = $fetch->invoke($this->service, ['_transient' => true, 'uuid' => 's'], '/items', ['pagination' => ['page' => 3]], []);

		$this->assertSame([['id' => 9]], $page['objects']);
		$this->assertSame([1 => 'not this one'], $this->read('pagePrefetch'));
	}//end testAPageNotPrefetchedIsCalled()

	/**
	 * A 429 without a response raises the source's own rate limit headers.
	 *
	 * @return void
	 */
	public function testA429WithoutAResponseRaisesTheSourcesRateLimit(): void {
		try {
			$this->fetch($this->callLog(429, null), ['rateLimitLimit' => 60, 'rateLimitRemaining' => 0, 'rateLimitReset' => 99, 'rateLimitWindow' => 60]);
			$this->fail('A 429 must raise.');
		} catch (TooManyRequestsHttpException $exception) {
			$this->assertSame('Rate Limit on Source exceeded.', $exception->getMessage());
			$this->assertSame(429, $exception->getCode());
			$this->assertSame(
				['X-RateLimit-Limit' => 60, 'X-RateLimit-Remaining' => 0, 'X-RateLimit-Reset' => 99, 'X-RateLimit-Used' => 0, 'X-RateLimit-Window' => 60],
				$exception->getHeaders()
			);
		}
	}//end testA429WithoutAResponseRaisesTheSourcesRateLimit()

	/**
	 * A 429 with a body and Retry-After raises with the status in the message.
	 *
	 * @return void
	 */
	public function testA429WithABodyRaisesNamingTheStatus(): void {
		$this->expectException(TooManyRequestsHttpException::class);
		$this->expectExceptionMessage('Rate limit on source exceeded (status 429).');
		$this->fetch($this->callLog(429, ['body' => '{}', 'headers' => ['Retry-After' => '30']]));
	}//end testA429WithABodyRaisesNamingTheStatus()

	/**
	 * A 4xx/5xx page, and a call with no response, fail with their status.
	 *
	 * @return void
	 */
	public function testAnErrorStatusOrNoResponseFailsThePage(): void {
		$this->assertSame(
			['objects' => [], 'result' => [], 'failed' => true, 'statusCode' => 500],
			$this->fetch($this->callLog(500, ['body' => '[{"id":1}]']))
		);
		$this->assertSame(
			['objects' => [], 'result' => [], 'failed' => true, 'statusCode' => 400],
			$this->fetch($this->callLog(400, ['body' => '[{"id":1}]']))
		);
		$this->assertSame(
			['objects' => [], 'result' => [], 'failed' => true, 'statusCode' => null],
			$this->fetch($this->callLog(null, null))
		);
		$this->assertSame(
			['objects' => [], 'result' => [], 'failed' => true, 'statusCode' => 200],
			$this->fetch($this->callLog(200, null))
		);
	}//end testAnErrorStatusOrNoResponseFailsThePage()

	/**
	 * A 399 is not an error status.
	 *
	 * @return void
	 */
	public function testA399IsReadAsAPage(): void {
		$this->assertSame([['id' => 1]], $this->fetch($this->callLog(399, ['body' => '[{"id":1}]']))['objects']);
	}//end testA399IsReadAsAPage()

	/**
	 * A Link header's last page is recorded, and a page without one keeps the earlier count.
	 *
	 * @return void
	 */
	public function testTheLinkHeaderLastPageIsRecordedAndKept(): void {
		$this->fetch($this->callLog(200, ['body' => '[]', 'headers' => ['Link' => '<https://source.example/items?page=4>; rel="last"']]));
		$this->assertSame(4, $this->read('lastPageFromLink'));

		$this->fetch($this->callLog(200, ['body' => '[]']));
		$this->assertSame(4, $this->read('lastPageFromLink'));
	}//end testTheLinkHeaderLastPageIsRecordedAndKept()

	/**
	 * A .tar.gz endpoint is skipped with a warning, as an empty page that did not fail.
	 *
	 * @return void
	 */
	public function testATarGzEndpointIsSkipped(): void {
		$page = $this->fetch($this->callLog(200, ['body' => 'bytes']), [], '/dump.tar.gz');

		$this->assertSame(['objects' => [], 'result' => []], $page);
		$this->assertSame('warning', $this->logged[0][0]);
		$this->assertSame(['endpoint' => '/dump.tar.gz'], $this->logged[0][2]);
		$this->assertStringContainsString('.tar.gz bulk sources are not supported', $this->logged[0][1]);
	}//end testATarGzEndpointIsSkipped()

	/**
	 * A base64 body is decoded and a gzip one decompressed before parsing.
	 *
	 * @return void
	 */
	public function testABase64GzipBodyIsDecodedAndDecompressed(): void {
		$body = base64_encode((string)gzencode('[{"id":"gz"}]'));

		$page = $this->fetch($this->callLog(200, ['body' => $body, 'encoding' => 'base64']), [], '/dump.jsonl.gz');

		$this->assertSame([['id' => 'gz']], $page['objects']);
	}//end testABase64GzipBodyIsDecodedAndDecompressed()

	/**
	 * A base64 body that does not decode is read as it is; a gzip hint on plain JSON leaves it alone.
	 *
	 * @return void
	 */
	public function testUndecodableBase64AndNonGzipBodiesAreReadAsTheyAre(): void {
		$page = $this->fetch($this->callLog(200, ['body' => '[{"id":"plain"}]', 'encoding' => 'base64']), ['configuration' => ['decompress' => 'gzip']]);

		$this->assertSame([['id' => 'plain']], $page['objects']);
	}//end testUndecodableBase64AndNonGzipBodiesAreReadAsTheyAre()

	/**
	 * A markdown source is read by the markdown parser.
	 *
	 * @return void
	 */
	public function testAMarkdownSourceIsReadAsMarkdown(): void {
		$body = "## Tools\n\n- [Alpha](https://alpha.example) - The first.\n- [Beta](https://beta.example) - The second.\n";

		$page = $this->fetch($this->callLog(200, ['body' => $body]), ['configuration' => ['format' => 'Markdown']]);

		$this->assertCount(2, $page['result']);
		$this->assertSame($page['result'], $page['objects']);
		$this->assertSame('Alpha', $page['result'][0]['name']);
	}//end testAMarkdownSourceIsReadAsMarkdown()

	/**
	 * An html source is read by the html parser with the source's configuration.
	 *
	 * @return void
	 */
	public function testAnHtmlSourceIsReadAsHtml(): void {
		$body = '<html><body><table><tr><th>Name</th></tr><tr><td>Gamma</td></tr></table></body></html>';

		$page = $this->fetch($this->callLog(200, ['body' => $body]), ['configuration' => ['format' => 'html']]);

		$this->assertArrayNotHasKey('failed', $page);
		$this->assertSame($page['result'], $page['objects']);
	}//end testAnHtmlSourceIsReadAsHtml()

	/**
	 * A YAML scalar is wrapped in a list.
	 *
	 * @return void
	 */
	public function testAYamlScalarIsWrapped(): void {
		$page = $this->fetch($this->callLog(200, ['body' => 'just text']), ['configuration' => ['format' => 'yaml']]);

		$this->assertSame(['just text'], $page['result']);
	}//end testAYamlScalarIsWrapped()

	/**
	 * Malformed YAML fails the page and logs an error naming the status.
	 *
	 * @return void
	 */
	public function testMalformedYamlFailsThePage(): void {
		$page = $this->fetch($this->callLog(null, ['body' => "a: [1, 2\nb: }"]), ['configuration' => ['format' => 'yaml']]);

		$this->assertSame(['objects' => [], 'result' => [], 'failed' => true, 'statusCode' => null], $page);
		$this->assertSame('error', $this->logged[0][0]);
		$this->assertSame('/items', $this->logged[0][2]['endpoint']);
		$this->assertSame(200, $this->logged[0][2]['status']);
	}//end testMalformedYamlFailsThePage()

	/**
	 * XML is read when the body is not JSON.
	 *
	 * @return void
	 */
	public function testXmlIsReadWhenTheBodyIsNotJson(): void {
		$page = $this->fetch($this->callLog(200, ['body' => '<items><item><id>7</id></item></items>']));

		$this->assertSame(['item' => ['id' => ['#text' => '7']]], $page['result']);
	}//end testXmlIsReadWhenTheBodyIsNotJson()

	/**
	 * An empty body is an empty page; an unreadable one fails the page with an error.
	 *
	 * @return void
	 */
	public function testEmptyAndUnreadableBodies(): void {
		$this->assertSame(['objects' => [], 'result' => []], $this->fetch($this->callLog(200, ['body' => '  '])));

		$page = $this->fetch($this->callLog(200, ['body' => 'garbage{']));
		$this->assertSame(['objects' => [], 'result' => [], 'failed' => true, 'statusCode' => 200], $page);
		$this->assertSame('error', $this->logged[0][0]);
		$this->assertSame(['endpoint' => '/items', 'status' => 200, 'length' => 8, 'shape' => ''], $this->logged[0][2]);
	}//end testEmptyAndUnreadableBodies()

	/**
	 * A body total records the page count.
	 *
	 * @return void
	 */
	public function testABodyTotalRecordsThePageCount(): void {
		(function (): void {
			$this->lastPageFromLink = null;
		})->call($this->service);
		$fetch = new ReflectionMethod(SynchronizationService::class, 'fetchSinglePageData');
		(function (ObjectEntity $callLog): void {
			$this->pagePrefetch = [1 => $callLog];
		})->call($this->service, $this->callLog(200, ['body' => '{"results":[{"id":1}],"count":95}']));

		$page = $fetch->invoke(
			$this->service,
			['location' => 'https://source.example'],
			'/items',
			['pagination' => ['index' => 1, 'page' => 1]],
			['sourceConfig' => ['resultsPosition' => 'results', 'totalPosition' => 'count', 'pageSize' => 10]]
		);

		$this->assertSame([['id' => 1]], $page['objects']);
		$this->assertSame(10, $this->read('lastPageFromLink'));
	}//end testABodyTotalRecordsThePageCount()
}//end class

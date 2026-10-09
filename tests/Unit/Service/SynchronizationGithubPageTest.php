<?php

/**
 * Unit tests for the synchronization page fetch against GitHub-shaped answers.
 *
 * Drives `fetchSinglePageData()` with CallLogs shaped exactly as `CallService`
 * stores them, handed in through the page prefetch cache the fan-out uses, so
 * no HTTP client is involved. Covers the spent-quota suspension (a 403 with
 * `X-RateLimit-Remaining: 0`, the way GitHub says it) and YAML pages.
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

use OCA\Integriq\Flow\FlowRateLimit;
use OCA\Integriq\Service\SynchronizationService;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * @spec openspec/changes/sources-github-publiccode/specs/github-publiccode-source/spec.md#requirement-a-spent-quota-suspends-the-run-req-ghp-004
 */
class SynchronizationGithubPageTest extends TestCase {

	/**
	 * The service, built without its twenty collaborators.
	 *
	 * @var SynchronizationService
	 */
	private SynchronizationService $service;

	/**
	 * The page fetch under test.
	 *
	 * @var ReflectionMethod
	 */
	private ReflectionMethod $fetch;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->service = (new ReflectionClass(SynchronizationService::class))->newInstanceWithoutConstructor();

		// The logger is a readonly promoted property; it can only be set from
		// inside the class scope, once.
		$logger = $this->createMock(LoggerInterface::class);
		(function (LoggerInterface $logger): void {
			$this->logger = $logger;
		})->call($this->service, $logger);

		$this->fetch = new ReflectionMethod(SynchronizationService::class, 'fetchSinglePageData');
		$this->fetch->setAccessible(true);
	}//end setUp()

	/**
	 * GitHub's spent quota, a 403 with remaining 0, raises the rate-limit refusal with the reset.
	 *
	 * @return void
	 */
	public function testA403WithRemainingZeroRaisesTheRateLimit(): void {
		$reset = (time() + 600);

		try {
			$this->fetchPage(
				statusCode: 403,
				body: '{"message":"API rate limit exceeded for user ID 1."}',
				headers: [
					'X-RateLimit-Limit' => ['10'],
					'X-RateLimit-Remaining' => ['0'],
					'X-RateLimit-Reset' => [(string)$reset],
				]
			);
			$this->fail('A spent quota must raise the rate-limit refusal.');
		} catch (TooManyRequestsHttpException $exception) {
			$this->assertSame($reset, $exception->getHeaders()['X-RateLimit-Reset']);
			$this->assertSame(0, $exception->getHeaders()['X-RateLimit-Remaining']);
			$this->assertSame(10, $exception->getHeaders()['X-RateLimit-Limit']);

			// And the flow node would wait until that reset, not 60 s or forever.
			$resumeAt = FlowRateLimit::resetTimeFrom(exception: $exception)->getTimestamp();
			$this->assertEqualsWithDelta($reset, $resumeAt, 2);
		}

	}//end testA403WithRemainingZeroRaisesTheRateLimit()

	/**
	 * Lower-case headers, as an HTTP/2 server sends them, count the same.
	 *
	 * @return void
	 */
	public function testLowerCaseHeadersCountToo(): void {
		$this->expectException(TooManyRequestsHttpException::class);

		$this->fetchPage(
			statusCode: 403,
			body: '{"message":"API rate limit exceeded"}',
			headers: ['x-ratelimit-remaining' => ['0'], 'x-ratelimit-reset' => [(string)(time() + 60)]]
		);

	}//end testLowerCaseHeadersCountToo()

	/**
	 * A 429 with Retry-After in seconds becomes an absolute reset.
	 *
	 * @return void
	 */
	public function testA429WithRetryAfterRaisesWithAReset(): void {
		try {
			$this->fetchPage(statusCode: 429, body: 'slow down', headers: ['Retry-After' => ['120']]);
			$this->fail('A 429 with Retry-After must raise.');
		} catch (TooManyRequestsHttpException $exception) {
			$this->assertEqualsWithDelta(time() + 120, $exception->getHeaders()['X-RateLimit-Reset'], 2);
		}

	}//end testA429WithRetryAfterRaisesWithAReset()

	/**
	 * A 403 without rate-limit headers is a real refusal: a failed page, no suspension.
	 *
	 * @return void
	 */
	public function testA403WithoutRateLimitHeadersIsAnOrdinaryFailedPage(): void {
		$page = $this->fetchPage(
			statusCode: 403,
			body: '{"message":"Resource not accessible by integration"}',
			headers: ['X-RateLimit-Remaining' => ['4999']]
		);

		$this->assertTrue($page['failed']);
		$this->assertSame([], $page['objects']);

	}//end testA403WithoutRateLimitHeadersIsAnOrdinaryFailedPage()

	/**
	 * A code search page reads its items and learns the page count from `Link`.
	 *
	 * @return void
	 */
	public function testACodeSearchPageReadsItsItemsAndPageCount(): void {
		$body = (string)json_encode(
			[
				'total_count' => 250,
				'incomplete_results' => false,
				'items' => [
					['name' => 'publiccode.yml', 'path' => 'publiccode.yml', 'repository' => ['full_name' => 'ConductionNL/opencatalogi']],
					['name' => 'publiccode.yml', 'path' => 'publiccode.yml', 'repository' => ['full_name' => 'Amsterdam/signals-frontend']],
				],
			]
		);

		$page = $this->fetchPage(
			statusCode: 200,
			body: $body,
			headers: [
				'Link' => ['<https://api.github.com/search/code?q=filename%3Apubliccode.yml&page=2>; rel="next", '
					. '<https://api.github.com/search/code?q=filename%3Apubliccode.yml&page=3>; rel="last"'],
				'X-RateLimit-Remaining' => ['9'],
			],
			sourceConfig: ['resultsPosition' => 'items']
		);

		$this->assertCount(2, $page['objects']);
		$this->assertSame('ConductionNL/opencatalogi', $page['objects'][0]['repository']['full_name']);

		$lastPage = (function (): ?int {
			return $this->lastPageFromLink;
		})->call($this->service);
		$this->assertSame(3, $lastPage);

	}//end testACodeSearchPageReadsItsItemsAndPageCount()

	/**
	 * A source declaring `format: yaml` has its page parsed as YAML.
	 *
	 * @return void
	 */
	public function testAYamlSourceParsesItsPage(): void {
		$page = $this->fetchPage(
			statusCode: 200,
			body: "- name: one\n  releaseDate: 2024-01-31\n- name: two\n",
			headers: ['Content-Type' => ['text/plain; charset=utf-8']],
			sourceConfig: ['resultsPosition' => '_root'],
			sourceConfiguration: ['format' => 'yaml']
		);

		$this->assertCount(2, $page['objects']);
		$this->assertSame('one', $page['objects'][0]['name']);
		$this->assertSame('2024-01-31', $page['objects'][0]['releaseDate']);

	}//end testAYamlSourceParsesItsPage()

	/**
	 * A YAML Content-Type is enough, without a declared format.
	 *
	 * @return void
	 */
	public function testAYamlContentTypeParsesWithoutADeclaredFormat(): void {
		$page = $this->fetchPage(
			statusCode: 200,
			body: "- name: one\n",
			headers: ['Content-Type' => ['application/yaml']],
			sourceConfig: ['resultsPosition' => '_root']
		);

		$this->assertSame('one', $page['objects'][0]['name']);

	}//end testAYamlContentTypeParsesWithoutADeclaredFormat()

	/**
	 * YAML that does not parse makes a FAILED page, never an empty one.
	 *
	 * @return void
	 */
	public function testMalformedYamlIsAFailedPageNotAnEmptyOne(): void {
		$page = $this->fetchPage(
			statusCode: 200,
			body: (string)file_get_contents(__DIR__ . '/../../fixtures/publiccode/publiccode-malformed.yml'),
			headers: ['Content-Type' => ['text/plain']],
			sourceConfiguration: ['format' => 'yaml']
		);

		$this->assertTrue($page['failed']);

	}//end testMalformedYamlIsAFailedPageNotAnEmptyOne()

	/**
	 * Fetch one page whose CallLog is already in the prefetch cache.
	 *
	 * @param int $statusCode The status.
	 * @param string $body The body.
	 * @param array $headers The response headers.
	 * @param array $sourceConfig The synchronization's sourceConfig.
	 * @param array $sourceConfiguration The source's configuration.
	 *
	 * @return array The page result.
	 */
	private function fetchPage(
		int $statusCode,
		string $body,
		array $headers,
		array $sourceConfig = [],
		array $sourceConfiguration = [],
	): array {
		$callLog = new ObjectEntity();
		$callLog->setUuid('33333333-3333-3333-3333-333333333333');
		$callLog->setObject(
			[
				'statusCode' => $statusCode,
				'response' => [
					'statusCode' => $statusCode,
					'statusMessage' => '',
					'headers' => $headers,
					'body' => $body,
					'encoding' => 'UTF-8',
				],
			]
		);

		(function (ObjectEntity $callLog): void {
			$this->pagePrefetch = [1 => $callLog];
		})->call($this->service, $callLog);

		$source = [
			'location' => 'https://api.github.com',
			'configuration' => $sourceConfiguration,
		];

		return $this->fetch->invoke(
			$this->service,
			$source,
			'/search/code',
			['pagination' => ['index' => 1, 'page' => 1]],
			['sourceConfig' => $sourceConfig]
		);

	}//end fetchPage()
}//end class

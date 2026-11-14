<?php

/**
 * Integriq — a declared data feed answered page by page, within the consumer's scope.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Feed
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-a-feed-answers-only-its-declared-fields-page-by-page-req-ddf-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Feed;

use OCA\Integriq\Service\Feed\FeedRequestHandler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An in-memory ledger of 2,500 lines for two administrations behind a findAll() double.
 */
#[CoversClass(FeedRequestHandler::class)]
class FeedRequestHandlerTest extends TestCase {

	/**
	 * The feed URL.
	 *
	 * @var string
	 */
	private const URL = 'https://cloud.example.invalid/index.php/apps/integriq/api/endpoint/feeds/shillinq/ledger-lines';

	/**
	 * The ledger-lines feed as the endpoint stores it.
	 *
	 * @var array<string, mixed>
	 */
	private const FEED = [
		'app' => 'shillinq',
		'key' => 'ledger-lines',
		'title' => 'Ledger lines',
		'register' => 'shillinq',
		'schema' => 'ledger-line',
		'fields' => ['id', 'period', 'account', 'amount', 'administrationId'],
		'filter' => ['status' => 'posted'],
		'scope' => ['parameter' => 'administrationId', 'field' => 'administrationId'],
		'pageSize' => 1000,
	];

	/**
	 * Every stored line.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $lines = [];

	/**
	 * The findAll() configurations asked for.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $asked = [];

	/**
	 * The handler under test.
	 *
	 * @var FeedRequestHandler
	 */
	private FeedRequestHandler $handler;

	/**
	 * Build the ledger and the handler.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		for ($i = 1; $i <= 2500; $i++) {
			$this->lines[] = [
				'id' => 'line-' . $i,
				'period' => '2026-0' . (1 + ($i % 9)),
				'account' => '4000',
				'amount' => $i,
				'administrationId' => 'voorbeeld',
				'status' => 'posted',
				'iban' => 'NL00BANK0123456789',
			];
		}

		$this->lines[] = ['id' => 'other-1', 'period' => '2026-09', 'administrationId' => 'elders', 'status' => 'posted', 'amount' => 1];
		$this->lines[] = ['id' => 'draft-1', 'period' => '2026-09', 'administrationId' => 'voorbeeld', 'status' => 'draft', 'amount' => 1];

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				$this->asked[] = $config;
				$filters = $config['filters'];
				unset($filters['register'], $filters['schema']);
				$matching = array_values(
					array_filter(
						$this->lines,
						static function (array $line) use ($filters): bool {
							foreach ($filters as $field => $value) {
								if (is_array($value) === true ? in_array(($line[$field] ?? null), $value, true) === false : ($line[$field] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);

				$entities = [];
				foreach (array_slice($matching, $config['offset'], $config['limit']) as $line) {
					$entity = new ObjectEntity();
					$entity->setObject($line);
					$entities[] = $entity;
				}

				return ['results' => $entities];
			}
		);

		$this->handler = new FeedRequestHandler($objectService);
	}//end setUp()

	/**
	 * A consumer with the given feed scopes.
	 *
	 * @param mixed $feedScopes The consumer's `feedScopes`.
	 *
	 * @return ObjectEntity The consumer.
	 */
	private static function consumer(mixed $feedScopes): ObjectEntity {
		$consumer = new ObjectEntity();
		$consumer->setObject(['name' => 'Power BI Gemeente Voorbeeld', 'feedScopes' => $feedScopes]);
		return $consumer;
	}//end consumer()

	/**
	 * The query of a next link.
	 *
	 * @param string $link The link.
	 *
	 * @return array<string, mixed> The query.
	 */
	private static function queryOf(string $link): array {
		parse_str((string)parse_url($link, PHP_URL_QUERY), $query);
		return $query;
	}//end queryOf()

	/**
	 * A BI tool following next links gets three pages holding every posted line of its administration once.
	 *
	 * @return void
	 */
	public function testFollowingNextLinksReturnsEveryLineOnce(): void {
		$query = [];
		$pages = 0;
		$ids = [];
		do {
			$body = $this->handler->answer(feed: self::FEED, bound: ['voorbeeld'], query: $query, baseUrl: self::URL)->getData();
			$pages++;
			foreach ($body['value'] as $line) {
				$this->assertSame(['id', 'period', 'account', 'amount', 'administrationId'], array_keys($line));
				$ids[] = $line['id'];
			}

			$query = null;
			if (isset($body['@odata.nextLink']) === true) {
				$this->assertStringStartsWith(self::URL . '?', $body['@odata.nextLink']);
				$query = self::queryOf(link: $body['@odata.nextLink']);
			}
		} while ($query !== null);

		$this->assertSame(3, $pages);
		$this->assertCount(2500, $ids);
		$this->assertCount(2500, array_unique($ids));
		$this->assertNotContains('other-1', $ids);
		$this->assertNotContains('draft-1', $ids);
	}//end testFollowingNextLinksReturnsEveryLineOnce()

	/**
	 * The fixed filter, the scope and the paging reach the object query.
	 *
	 * @return void
	 */
	public function testTheQueryCarriesTheFixedFilterTheScopeAndThePage(): void {
		$this->handler->answer(feed: self::FEED, bound: ['voorbeeld'], query: ['$top' => '10', '$skip' => '20'], baseUrl: self::URL);

		$this->assertSame(
			[
				'filters' => ['administrationId' => 'voorbeeld', 'status' => 'posted', 'register' => 'shillinq', 'schema' => 'ledger-line'],
				'limit' => 11,
				'offset' => 20,
			],
			$this->asked[0]
		);
	}//end testTheQueryCarriesTheFixedFilterTheScopeAndThePage()

	/**
	 * $top is capped at the page size, and the last page has no next link.
	 *
	 * @return void
	 */
	public function testTopIsCappedAtThePageSize(): void {
		$body = $this->handler->answer(feed: self::FEED, bound: ['voorbeeld'], query: ['$top' => '5000'], baseUrl: self::URL)->getData();
		$this->assertCount(1000, $body['value']);
		$this->assertSame('1000', self::queryOf(link: $body['@odata.nextLink'])['$top']);

		$last = $this->handler->answer(feed: self::FEED, bound: ['voorbeeld'], query: ['$skip' => '2400'], baseUrl: self::URL)->getData();
		$this->assertCount(100, $last['value']);
		$this->assertArrayNotHasKey('@odata.nextLink', $last);
	}//end testTopIsCappedAtThePageSize()

	/**
	 * $select narrows to declared fields; $filter eq on a declared field narrows the lines.
	 *
	 * @return void
	 */
	public function testSelectAndFilter(): void {
		$body = $this->handler->answer(
			feed: self::FEED,
			bound: ['voorbeeld'],
			query: ['$select' => 'id,amount', '$filter' => "period eq '2026-09' and account eq '4000'"],
			baseUrl: self::URL
		)->getData();

		$this->assertNotSame([], $body['value']);
		foreach ($body['value'] as $line) {
			$this->assertSame(['id', 'amount'], array_keys($line));
		}

		$this->assertSame('2026-09', $this->asked[0]['filters']['period']);
		$this->assertSame('4000', $this->asked[0]['filters']['account']);
	}//end testSelectAndFilter()

	/**
	 * $filter literals: quoted strings with doubled quotes, numbers and booleans.
	 *
	 * @return void
	 */
	public function testFilterLiterals(): void {
		$this->handler->answer(feed: self::FEED, bound: ['voorbeeld'], query: ['$filter' => "account eq 'O''Brien' and amount eq 12 and period eq 1.5 and id eq true"], baseUrl: self::URL);

		$filters = $this->asked[0]['filters'];
		$this->assertSame("O'Brien", $filters['account']);
		$this->assertSame(12, $filters['amount']);
		$this->assertSame(1.5, $filters['period']);
		$this->assertTrue($filters['id']);
	}//end testFilterLiterals()

	/**
	 * Requests a feed refuses, each naming what is not supported.
	 *
	 * @return array<string, array{array<string, string>, string}>
	 */
	public static function unsupported(): array {
		return [
			'$orderby' => [['$orderby' => 'amount'], '$orderby is not supported'],
			'an unknown parameter' => [['format' => 'csv'], 'format is not supported'],
			'$select of an undeclared field' => [['$select' => 'id,iban'], '$select names iban'],
			'$filter on an undeclared field' => [['$filter' => "iban eq 'x'"], '$filter names iban'],
			'$filter with gt' => [['$filter' => 'amount gt 5'], '"amount gt 5" is not supported'],
			'negative $skip' => [['$skip' => '-1'], '$top and $skip are whole numbers'],
			'zero $top' => [['$top' => '0'], '$top and $skip are whole numbers'],
		];
	}//end unsupported()

	/**
	 * An unsupported query option is a 400 that names it, and nothing is read.
	 *
	 * @param array<string, string> $query   The query.
	 * @param string                $message What the message names.
	 *
	 * @return void
	 */
	#[DataProvider('unsupported')]
	public function testUnsupportedOptionsAreRefusedByName(array $query, string $message): void {
		$response = $this->handler->answer(feed: self::FEED, bound: ['voorbeeld'], query: $query, baseUrl: self::URL);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString($message, $response->getData()['message']);
		$this->assertSame([], $this->asked);
	}//end testUnsupportedOptionsAreRefusedByName()

	/**
	 * A consumer asking for another administration gets 403.
	 *
	 * @return void
	 */
	public function testAnotherAdministrationIsForbidden(): void {
		$consumer = self::consumer(['shillinq' => ['voorbeeld']]);

		$this->assertNull($this->handler->scopeRefusal(feed: self::FEED, consumer: $consumer, query: ['administrationId' => 'voorbeeld']));
		$this->assertNull($this->handler->scopeRefusal(feed: self::FEED, consumer: $consumer, query: []));
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->handler->scopeRefusal(feed: self::FEED, consumer: $consumer, query: ['administrationId' => 'elders'])?->getStatus());
		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$this->handler->scopeRefusal(feed: self::FEED, consumer: $consumer, query: ['$filter' => "administrationId eq 'elders'"])?->getStatus()
		);
	}//end testAnotherAdministrationIsForbidden()

	/**
	 * Consumers without a binding for the app, and an anonymous caller, are refused.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function unbound(): array {
		return [
			'no feedScopes' => [null],
			'another app only' => [['learniq' => ['voorbeeld']]],
			'an empty list' => [['shillinq' => []]],
			'blank values' => [['shillinq' => [' ', '']]],
		];
	}//end unbound()

	/**
	 * A consumer with no binding for the declaring app gets 403 on every feed.
	 *
	 * @param mixed $feedScopes The consumer's `feedScopes`.
	 *
	 * @return void
	 */
	#[DataProvider('unbound')]
	public function testAnUnboundConsumerIsForbidden(mixed $feedScopes): void {
		$refusal = $this->handler->scopeRefusal(feed: self::FEED, consumer: self::consumer($feedScopes), query: []);

		$this->assertSame(Http::STATUS_FORBIDDEN, $refusal?->getStatus());
		$this->assertSame('scope_not_allowed', $refusal->getData()['error']);
	}//end testAnUnboundConsumerIsForbidden()

	/**
	 * Without a consumer the feed is unauthorised.
	 *
	 * @return void
	 */
	public function testWithoutAConsumerTheFeedIsUnauthorised(): void {
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->handler->scopeRefusal(feed: self::FEED, consumer: null, query: [])?->getStatus());
	}//end testWithoutAConsumerTheFeedIsUnauthorised()

	/**
	 * A consumer bound to several administrations reads all of them, or the one it names.
	 *
	 * @return void
	 */
	public function testSeveralBoundValues(): void {
		$consumer = self::consumer(['shillinq' => ['voorbeeld', 'elders', 'voorbeeld']]);
		$this->assertSame(['voorbeeld', 'elders'], $this->handler->boundScope(feed: self::FEED, consumer: $consumer));
		$this->assertSame(['solo'], $this->handler->boundScope(feed: self::FEED, consumer: self::consumer(['shillinq' => 'solo'])));

		$this->handler->answer(feed: self::FEED, bound: ['voorbeeld', 'elders'], query: [], baseUrl: self::URL);
		$this->handler->answer(feed: self::FEED, bound: ['voorbeeld', 'elders'], query: ['administrationId' => 'elders'], baseUrl: self::URL);

		$this->assertSame(['voorbeeld', 'elders'], $this->asked[0]['filters']['administrationId']);
		$this->assertSame('elders', $this->asked[1]['filters']['administrationId']);
	}//end testSeveralBoundValues()
}//end class

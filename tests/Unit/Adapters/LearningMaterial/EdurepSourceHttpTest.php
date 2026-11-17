<?php

/**
 * Unit tests for the live Edurep transport and source.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Adapters\LearningMaterial
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/edurep-source/spec.md#requirement-search-maps-filters-to-cql-and-asks-for-facets
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Adapters\LearningMaterial;

use OCA\Integriq\Adapters\LearningMaterial\EdurepHttpTransport;
use OCA\Integriq\Adapters\LearningMaterial\EdurepSourceHttp;
use OCA\Integriq\Adapters\LearningMaterial\EdurepStatusStore;
use OCA\Integriq\Adapters\LearningMaterial\SourceUnavailableException;
use OCA\Integriq\Tests\Helpers\ArrayCache;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The live source sends small pages with facets, and a failure is typed and not cached.
 */
class EdurepSourceHttpTest extends TestCase {
	/**
	 * @var IClient&MockObject
	 */
	private IClient $client;

	private ArrayCache $cache;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->client = $this->createMock(IClient::class);
		$this->cache = new ArrayCache();
	}//end setUp()

	/**
	 * @return EdurepSourceHttp
	 */
	private function source(): EdurepSourceHttp {
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($this->client);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->cache);
		$transport = new EdurepHttpTransport(clientService: $clients, status: new EdurepStatusStore(cacheFactory: $cacheFactory));

		return new EdurepSourceHttp(transport: $transport, logger: $this->createMock(LoggerInterface::class));
	}//end source()

	/**
	 * @param int $status The status code.
	 * @param string $body The body.
	 *
	 * @return IResponse
	 */
	private function response(int $status, string $body): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);

		return $response;
	}//end response()

	/**
	 * @return void
	 */
	public function testARequestAsksForASmallPageAndTheFourFacets(): void {
		$body = (string)file_get_contents(__DIR__ . '/../../../fixtures/edurep/betoog-nl-facets.xml');
		$this->client->expects($this->once())
			->method('get')
			->with(
				$this->callback(static function (string $url): bool {
					parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
					return str_starts_with($url, EdurepHttpTransport::ENDPOINT . '?')
						&& $query['query'] === 'betoog AND lom.general.language=nl'
						&& $query['startRecord'] === '51' && $query['maximumRecords'] === '50'
						&& $query['x-term-drilldown'] === 'lom.rights.copyrightandotherrestrictions:20,meta.repository.id:20,lom.educational.context:12,lom.technical.format:12';
				}),
				$this->callback(static fn (array $options): bool => $options['timeout'] === 10)
			)
			->willReturn($this->response(status: 200, body: $body));

		$result = $this->source()->search(query: 'betoog', filters: ['pageSize' => 1000], page: 2);

		$this->assertSame(242, $result->total);
		$this->assertSame(50, $result->pageSize);
		$this->assertIsString($this->cache->get('lastSuccess'));
	}//end testARequestAsksForASmallPageAndTheFourFacets()

	/**
	 * @return void
	 */
	public function testATimeoutThrowsTheTypedExceptionWithTheLastSuccess(): void {
		$this->cache->set('lastSuccess', '2026-11-05T10:00:00+00:00');
		$this->client->method('get')->willThrowException(new RuntimeException('cURL error 28: Operation timed out after 10000 milliseconds'));

		try {
			$this->source()->search(query: 'betoog', filters: [], page: 1);
			$this->fail('No exception');
		} catch (SourceUnavailableException $error) {
			$this->assertSame('edurep', $error->source);
			$this->assertSame('2026-11-05T10:00:00+00:00', $error->lastSuccess);
		}

		$this->assertSame('cURL error 28: Operation timed out after 10000 milliseconds', $this->cache->get('lastFailure')['reason']);
		$this->assertNull($this->cache->get('health'));
	}//end testATimeoutThrowsTheTypedExceptionWithTheLastSuccess()

	/**
	 * @return void
	 */
	public function testANon200AnswerThrowsTheTypedException(): void {
		$this->client->method('get')->willReturn($this->response(status: 503, body: 'down'));
		$this->expectException(SourceUnavailableException::class);

		$this->source()->find(id: 'urn:wikiwijsmaken:194413');
	}//end testANon200AnswerThrowsTheTypedException()

	/**
	 * @return void
	 */
	public function testABodyThatIsNoSruResponseThrowsTheTypedException(): void {
		$this->client->method('get')->willReturn($this->response(status: 200, body: '<html>maintenance</html>'));
		$this->expectException(SourceUnavailableException::class);

		$this->source()->search(query: 'betoog', filters: [], page: 1);
	}//end testABodyThatIsNoSruResponseThrowsTheTypedException()
}//end class

<?php

/**
 * Integriq — the endpoint pipeline's path for a declared data feed.
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
 *
 * @spec openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-a-consumer-reads-only-the-scope-it-is-bound-to-req-ddf-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Exception\AuthenticationException;
use OCA\Integriq\Rule\AvgBsnPolicyRule;
use OCA\Integriq\Rule\CompositeFanoutRule;
use OCA\Integriq\Rule\ReferenceNumberRule;
use OCA\Integriq\Service\ApprovalService;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridge;
use OCA\Integriq\Service\ConsumerScopeService;
use OCA\Integriq\Service\EndpointService;
use OCA\Integriq\Service\Feed\FeedRequestHandler;
use OCA\Integriq\Service\FlowRunnerService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\RateLimit\InboundRateLimitService;
use OCA\Integriq\Service\RuleService;
use OCA\Integriq\Service\StorageService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IRequestId;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Order of the checks: consumer, source allowlist, scope, rate limit, page.
 */
class EndpointServiceFeedTest extends TestCase {

	/**
	 * The authentication bridge.
	 *
	 * @var OpenRegisterCredentialBridge&MockObject
	 */
	private OpenRegisterCredentialBridge $auth;

	/**
	 * The source allowlist.
	 *
	 * @var ConsumerScopeService&MockObject
	 */
	private ConsumerScopeService $allowlist;

	/**
	 * The rate limiter.
	 *
	 * @var InboundRateLimitService&MockObject
	 */
	private InboundRateLimitService $rateLimit;

	/**
	 * The object service the feed reads.
	 *
	 * @var ORObjectService&MockObject
	 */
	private ORObjectService $objects;

	/**
	 * The service under test.
	 *
	 * @var EndpointService
	 */
	private EndpointService $service;

	/**
	 * Wire the service with a real feed handler.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->auth = $this->createMock(OpenRegisterCredentialBridge::class);
		$this->allowlist = $this->createMock(ConsumerScopeService::class);
		$this->rateLimit = $this->createMock(InboundRateLimitService::class);
		$this->objects = $this->createMock(ORObjectService::class);
		$logger = $this->createMock(LoggerInterface::class);

		$this->service = new EndpointService(
			$this->createMock(ObjectService::class),
			$this->createMock(CallService::class),
			$logger,
			$this->createMock(IURLGenerator::class),
			$this->createMock(MappingService::class),
			$this->objects,
			$this->createMock(IConfig::class),
			$this->createMock(StorageService::class),
			$this->auth,
			$this->createMock(ContainerInterface::class),
			$this->createMock(SynchronizationService::class),
			$this->createMock(RuleService::class),
			new WebhookSignatureService($logger),
			$this->rateLimit,
			new CompositeFanoutRule($this->objects, $logger),
			new ReferenceNumberRule(),
			new AvgBsnPolicyRule(),
			$this->createMock(ApprovalService::class),
			$this->createMock(IRequestId::class),
			$this->createMock(FlowRunnerService::class),
			$this->allowlist,
			$this->createMock(\OCA\OpenRegister\Db\SchemaMapper::class),
			$this->createMock(\OCA\OpenRegister\Service\FileService::class),
			null,
			null,
			null,
			new FeedRequestHandler($this->objects),
		);
	}//end setUp()

	/**
	 * The ledger-lines feed endpoint.
	 *
	 * @return ObjectEntity The endpoint.
	 */
	private static function endpoint(): ObjectEntity {
		$endpoint = new ObjectEntity();
		$endpoint->setUuid('endpoint-1');
		$endpoint->setObject(
			[
				'endpoint' => 'feeds/shillinq/ledger-lines',
				'method' => 'GET',
				'targetType' => 'feed',
				'feed' => [
					'app' => 'shillinq',
					'key' => 'ledger-lines',
					'register' => 'shillinq',
					'schema' => 'ledger-line',
					'fields' => ['id', 'amount'],
					'filter' => [],
					'scope' => ['parameter' => 'administrationId', 'field' => 'administrationId'],
					'pageSize' => 2,
				],
			]
		);

		return $endpoint;
	}//end endpoint()

	/**
	 * A request.
	 *
	 * @param array<string, string> $params        The parameters.
	 * @param string                $authorization The Authorization header.
	 * @param string                $method        The method.
	 *
	 * @return IRequest The request.
	 */
	private function request(array $params, string $authorization = '', string $method = 'GET'): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getMethod')->willReturn($method);
		$request->method('getParams')->willReturn(array_merge(['_path' => 'feeds/shillinq/ledger-lines'], $params));
		$request->method('getHeader')->willReturnCallback(static fn (string $name): string => $name === 'Authorization' ? $authorization : '');
		$request->method('getRequestUri')->willReturn('/index.php/apps/integriq/api/endpoint/feeds/shillinq/ledger-lines?x=1');
		$request->method('getServerProtocol')->willReturn('https');
		$request->method('getServerHost')->willReturn('cloud.example.invalid');
		return $request;
	}//end request()

	/**
	 * A consumer bound to the administration `voorbeeld`.
	 *
	 * @return ObjectEntity The consumer.
	 */
	private static function consumer(): ObjectEntity {
		$consumer = new ObjectEntity();
		$consumer->setUuid('consumer-1');
		$consumer->setObject(['name' => 'Power BI Gemeente Voorbeeld', 'feedScopes' => ['shillinq' => ['voorbeeld']]]);
		return $consumer;
	}//end consumer()

	/**
	 * Run the feed path.
	 *
	 * @param IRequest $request The request.
	 *
	 * @return \OCP\AppFramework\Http\Response The response.
	 */
	private function serve(IRequest $request): \OCP\AppFramework\Http\Response {
		return (new ReflectionMethod(EndpointService::class, 'handleFeedRequest'))->invoke($this->service, self::endpoint(), $request, true);
	}//end serve()

	/**
	 * A bound consumer reads a page with only the declared fields and a next link.
	 *
	 * @return void
	 */
	public function testABoundConsumerReadsAPage(): void {
		$this->auth->method('getResolvedConsumer')->willReturn(self::consumer());
		$this->allowlist->method('isAllowed')->willReturn(true);
		$lines = [];
		foreach ([1, 2, 3] as $i) {
			$line = new ObjectEntity();
			$line->setObject(['id' => 'l' . $i, 'amount' => $i, 'iban' => 'NL00']);
			$lines[] = $line;
		}

		$asked = [];
		$this->objects->method('findAll')->willReturnCallback(
			static function (array $config = []) use (&$asked, $lines): array {
				$asked[] = $config;
				return ($config['filters']['schema'] ?? null) === 'ledger-line' ? ['results' => $lines] : ['results' => []];
			}
		);

		$response = $this->serve(request: $this->request(params: []));

		$feedQueries = array_values(array_filter($asked, static fn (array $config): bool => ($config['filters']['schema'] ?? null) === 'ledger-line'));
		$this->assertSame(
			[['filters' => ['administrationId' => 'voorbeeld', 'register' => 'shillinq', 'schema' => 'ledger-line'], 'limit' => 3, 'offset' => 0]],
			$feedQueries
		);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([['id' => 'l1', 'amount' => 1], ['id' => 'l2', 'amount' => 2]], $response->getData()['value']);
		$this->assertSame(
			'https://cloud.example.invalid/index.php/apps/integriq/api/endpoint/feeds/shillinq/ledger-lines?%24skip=2&%24top=2',
			$response->getData()['@odata.nextLink']
		);
	}//end testABoundConsumerReadsAPage()

	/**
	 * Another administration is 403, before the rate limit is counted and before anything is read.
	 *
	 * @return void
	 */
	public function testAnotherAdministrationIsForbiddenBeforeTheRateLimit(): void {
		$this->auth->method('getResolvedConsumer')->willReturn(self::consumer());
		$this->allowlist->method('isAllowed')->willReturn(true);
		$this->rateLimit->expects($this->never())->method('enforce');
		$this->objects->expects($this->never())->method('findAll');

		$response = $this->serve(request: $this->request(params: ['administrationId' => 'elders']));

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('scope_not_allowed', $response->getData()['error']);
	}//end testAnotherAdministrationIsForbiddenBeforeTheRateLimit()

	/**
	 * The source allowlist runs before the scope check.
	 *
	 * @return void
	 */
	public function testTheSourceAllowlistRunsFirst(): void {
		$this->auth->method('getResolvedConsumer')->willReturn(self::consumer());
		$this->allowlist->method('isAllowed')->willReturn(false);
		$this->objects->expects($this->never())->method('findAll');

		$response = $this->serve(request: $this->request(params: ['administrationId' => 'elders']));

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('source_not_allowed', $response->getData()['error']);
	}//end testTheSourceAllowlistRunsFirst()

	/**
	 * Without a consumer and without a key the feed is 401.
	 *
	 * @return void
	 */
	public function testWithoutAKeyTheFeedIsUnauthorised(): void {
		$this->auth->method('getResolvedConsumer')->willReturn(null);
		$this->auth->expects($this->never())->method('authorizeApiKey');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->serve(request: $this->request(params: []))->getStatus());
	}//end testWithoutAKeyTheFeedIsUnauthorised()

	/**
	 * A consumer key is checked against the consumers; a refused key is 401.
	 *
	 * @return void
	 */
	public function testAConsumerKeyIsAuthenticated(): void {
		$this->auth->method('getResolvedConsumer')->willReturn(null);
		$this->auth->expects($this->once())->method('authorizeApiKey')->with('key-123', [])
			->willThrowException(new AuthenticationException('Invalid API key', []));
		$this->objects->expects($this->never())->method('findAll');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->serve(request: $this->request(params: [], authorization: 'key-123'))->getStatus());
	}//end testAConsumerKeyIsAuthenticated()

	/**
	 * A bearer token with three parts is checked as a consumer JWT.
	 *
	 * @return void
	 */
	public function testABearerTokenIsAuthenticatedAsAJwt(): void {
		$authenticated = false;
		$this->auth->method('getResolvedConsumer')->willReturnCallback(
			static function () use (&$authenticated): ?ObjectEntity {
				return $authenticated === true ? self::consumer() : null;
			}
		);
		$this->auth->expects($this->once())->method('authorizeJwt')->with('Bearer a.b.c')->willReturnCallback(
			static function () use (&$authenticated): void {
				$authenticated = true;
			}
		);
		$this->allowlist->method('isAllowed')->willReturn(true);
		$this->objects->method('findAll')->willReturn(['results' => []]);

		$response = $this->serve(request: $this->request(params: [], authorization: 'Bearer a.b.c'));

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['value' => []], $response->getData());
	}//end testABearerTokenIsAuthenticatedAsAJwt()

	/**
	 * A feed answers GET only.
	 *
	 * @return void
	 */
	public function testAFeedIsReadOnly(): void {
		$this->auth->expects($this->never())->method('getResolvedConsumer');

		$this->assertSame(Http::STATUS_METHOD_NOT_ALLOWED, $this->serve(request: $this->request(params: [], method: 'POST'))->getStatus());
	}//end testAFeedIsReadOnly()
}//end class

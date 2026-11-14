<?php

/**
 * Unit tests for routing an endpoint's request over several targets.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Endpoint
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/gateway-upstream-routing/specs/endpoint-runtime/spec.md#requirement-a-target-that-is-down-is-skipped-req-uprt-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Endpoint;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\Endpoint\UpstreamRequest;
use OCA\Integriq\Service\Endpoint\UpstreamRouter;
use OCA\Integriq\Service\Endpoint\UpstreamSelector;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Fake sources and a fake call engine: which source is called, how often, and what the call log records.
 */
#[CoversClass(UpstreamRouter::class)]
#[UsesClass(UpstreamSelector::class)]
#[UsesClass(UpstreamRequest::class)]
class UpstreamRouterTest extends TestCase {

	/**
	 * The source objects by uuid.
	 *
	 * @var array<string, ObjectEntity>
	 */
	private array $sources = [];

	/**
	 * The stored call logs by uuid, as CallService persisted them (redacted).
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $stored = [];

	/**
	 * The status each source answers.
	 *
	 * @var array<string, int>
	 */
	private array $answers = [];

	/**
	 * The sources called, in order.
	 *
	 * @var list<string>
	 */
	private array $called = [];

	/**
	 * The call log rows saved by the router.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * The object service double.
	 *
	 * @var ORObjectService&MockObject
	 */
	private ORObjectService $objects;

	/**
	 * Wire the fakes.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objects = $this->createMock(ORObjectService::class);
		$this->objects->method('find')->willReturnCallback(
			function (int|string $id, mixed $register = null, mixed $schema = null): ?ObjectEntity {
				if ($schema === 'source') {
					return ($this->sources[$id] ?? null);
				}

				if (isset($this->stored[$id]) === false) {
					return null;
				}

				return self::entity(uuid: (string)$id, body: $this->stored[$id]);
			}
		);
		$this->objects->method('saveObject')->willReturnCallback(
			function (array $object, mixed $register = null, mixed $schema = null, ?string $uuid = null): ObjectEntity {
				$this->saved[] = ['uuid' => $uuid, 'schema' => $schema] + $object;
				return self::entity(uuid: (string)$uuid, body: $object);
			}
		);
	}//end setUp()

	/**
	 * An entity with a uuid, a body and an optional organisation.
	 *
	 * @param string               $uuid         The uuid.
	 * @param array<string, mixed> $body         The object.
	 * @param string|null          $organisation The organisation uuid.
	 *
	 * @return ObjectEntity
	 */
	private static function entity(string $uuid, array $body, ?string $organisation = null): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($body);
		if ($organisation !== null) {
			$entity->setOrganisation($organisation);
		}

		return $entity;
	}//end entity()

	/**
	 * Register a fake source.
	 *
	 * @param string               $uuid         The source uuid.
	 * @param int                  $answer       The status it answers.
	 * @param array<string, mixed> $state        Breaker fields.
	 * @param string|null          $organisation Its organisation.
	 *
	 * @return void
	 */
	private function source(string $uuid, int $answer = 200, array $state = [], ?string $organisation = null): void {
		$this->sources[$uuid] = self::entity(uuid: $uuid, body: ['name' => $uuid] + $state, organisation: $organisation);
		$this->answers[$uuid] = $answer;
	}//end source()

	/**
	 * The router over the fakes, with a fixed random point.
	 *
	 * @param int $point The random point the weighted pick lands on.
	 *
	 * @return UpstreamRouter
	 */
	private function router(int $point = 0): UpstreamRouter {
		$calls = $this->createMock(CallService::class);
		$calls->method('call')->willReturnCallback(
			function (ObjectEntity $source, string $endpoint = '', string $method = 'GET'): ObjectEntity {
				$uuid = (string)$source->getUuid();
				$this->called[] = $uuid;
				$log = 'log-' . count($this->called);
				$this->stored[$log] = ['statusCode' => $this->answers[$uuid], 'response' => ['body' => '[redacted]']];

				return self::entity(uuid: $log, body: ['statusCode' => $this->answers[$uuid], 'response' => ['body' => 'secret in memory']]);
			}
		);

		return new UpstreamRouter(
			objects: $this->objects,
			calls: $calls,
			selector: new UpstreamSelector(random: static fn (int $max): int => min($point, ($max - 1))),
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end router()

	/**
	 * A request of an endpoint with these targets.
	 *
	 * @param list<array<string, mixed>> $targets    The targets.
	 * @param string                     $method     The method.
	 * @param array<string, mixed>       $parameters The query parameters.
	 * @param array<string, mixed>       $extra      More endpoint fields.
	 * @param string|null                $consumer     The resolved consumer.
	 * @param string|null                $organisation The endpoint's organisation.
	 *
	 * @return UpstreamRequest
	 */
	private static function request(
		array $targets,
		string $method = 'GET',
		array $parameters = [],
		array $extra = [],
		?string $consumer = null,
		?string $organisation = null,
	): UpstreamRequest {
		return new UpstreamRequest(
			endpoint: self::entity(uuid: 'endpoint-1', body: ['targetType' => 'api', 'targets' => $targets] + $extra, organisation: $organisation),
			method: $method,
			upstreamPath: '/zaken',
			parameters: $parameters,
			rawBody: '',
			consumer: $consumer
		);
	}//end request()

	/**
	 * Only an endpoint with targets is routed.
	 *
	 * @return void
	 */
	public function testOnlyAnEndpointWithTargetsIsRouted(): void {
		$router = $this->router();

		$this->assertFalse($router->routes(endpointData: ['targetType' => 'api', 'targetId' => 'src']));
		$this->assertTrue($router->routes(endpointData: ['targets' => [['source' => 'a']]]));
	}//end testOnlyAnEndpointWithTargetsIsRouted()

	/**
	 * With one of two targets down, every call goes to the other, and the call log names it.
	 *
	 * @return void
	 */
	public function testATargetWithAnOpenBreakerIsSkipped(): void {
		$this->source(uuid: 'zaken-oud', state: ['circuitBreakerState' => 'open', 'circuitBreakerOpenedAt' => time()]);
		$this->source(uuid: 'zaken-nieuw');

		$result = $this->router(point: 0)->call(
			request: self::request(targets: [['source' => 'zaken-oud', 'weight' => 95], ['source' => 'zaken-nieuw', 'weight' => 5]])
		);

		$this->assertInstanceOf(ObjectEntity::class, $result);
		$this->assertSame(['zaken-nieuw'], $this->called);
		$this->assertSame('zaken-nieuw', $result->getObject()['routedTarget']);
		$this->assertSame('weight', $result->getObject()['routedReason']);
	}//end testATargetWithAnOpenBreakerIsSkipped()

	/**
	 * The routing is merged onto the stored, redacted call log, never onto the bodies in memory.
	 *
	 * @return void
	 */
	public function testTheStampKeepsTheStoredRedactedCallLog(): void {
		$this->source(uuid: 'a');

		$this->router()->call(request: self::request(targets: [['source' => 'a']]));

		$this->assertCount(1, $this->saved);
		$this->assertSame('log-1', $this->saved[0]['uuid']);
		$this->assertSame('call_log', $this->saved[0]['schema']);
		$this->assertSame('[redacted]', $this->saved[0]['response']['body']);
		$this->assertSame('a', $this->saved[0]['routedTarget']);
		$this->assertSame('endpoint-1', $this->saved[0]['routedEndpoint']);
	}//end testTheStampKeepsTheStoredRedactedCallLog()

	/**
	 * A GET that got a 503 is tried once on the other target, which the call log names with reason retry.
	 *
	 * @return void
	 */
	public function testAnIdempotentCallIsRetriedOnceOnAnotherTarget(): void {
		$this->source(uuid: 'a', answer: 503);
		$this->source(uuid: 'b', answer: 503);
		$this->source(uuid: 'c', answer: 200);

		$result = $this->router(point: 0)->call(request: self::request(targets: [['source' => 'a'], ['source' => 'b'], ['source' => 'c']]));

		$this->assertSame(['a', 'b'], $this->called, 'one retry, not a walk over every target');
		$this->assertSame('b', $result->getObject()['routedTarget']);
		$this->assertSame('retry', $result->getObject()['routedReason']);
		$this->assertSame(
			[['log-1', 'a', 'weight', 'endpoint-1'], ['log-2', 'b', 'retry', 'endpoint-1']],
			array_map(static fn (array $row): array => [$row['uuid'], $row['routedTarget'], $row['routedReason'], $row['routedEndpoint']], $this->saved)
		);
	}//end testAnIdempotentCallIsRetriedOnceOnAnotherTarget()

	/**
	 * A POST that got a 503 is not retried, though another target is healthy.
	 *
	 * @return void
	 */
	public function testAPostIsNeverRetried(): void {
		$this->source(uuid: 'a', answer: 503);
		$this->source(uuid: 'b');

		$result = $this->router(point: 0)->call(request: self::request(targets: [['source' => 'a'], ['source' => 'b']], method: 'POST'));

		$this->assertSame(['a'], $this->called);
		$this->assertSame(503, $result->getObject()['statusCode']);
		$this->assertSame('weight', $result->getObject()['routedReason']);
	}//end testAPostIsNeverRetried()

	/**
	 * With no healthy target the answer is 503 naming the group, and nothing is called.
	 *
	 * @return void
	 */
	public function testNoHealthyTargetAnswers503WithoutACall(): void {
		$this->source(uuid: 'a', state: ['circuitBreakerState' => 'open', 'circuitBreakerOpenedAt' => time()]);

		$result = $this->router()->call(
			request: self::request(targets: [['source' => 'a'], ['source' => 'missing'], ['source' => 'x', 'when' => ['==' => [1, 2]]]])
		);

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertSame(503, $result->getStatus());
		$this->assertSame('', $result->getData()['group']);
		$this->assertSame([], $this->called);
		$this->assertSame([], $this->saved);
	}//end testNoHealthyTargetAnswers503WithoutACall()

	/**
	 * A request matching a rule goes to that rule's target, and the call log gives the rule.
	 *
	 * @return void
	 */
	public function testARuleRoutesTheRequestAndIsTheReason(): void {
		$this->source(uuid: 'zaken-oud');
		$this->source(uuid: 'zaken-archief');
		$targets = [
			['source' => 'zaken-oud'],
			['source' => 'zaken-archief', 'when' => ['==' => [['var' => 'parameters.archief'], 'true']]],
		];

		$result = $this->router()->call(request: self::request(targets: $targets, parameters: ['archief' => 'true']));

		$this->assertSame(['zaken-archief'], $this->called);
		$this->assertSame('rule:1', $result->getObject()['routedReason']);
	}//end testARuleRoutesTheRequestAndIsTheReason()

	/**
	 * A target of another organisation is never called.
	 *
	 * @return void
	 */
	public function testATargetOfAnotherOrganisationIsLeftOut(): void {
		$this->source(uuid: 'foreign', organisation: 'org-b');
		$this->source(uuid: 'own', organisation: 'org-a');

		$this->router(point: 0)->call(request: self::request(targets: [['source' => 'foreign'], ['source' => 'own']], organisation: 'org-a'));

		$this->assertSame(['own'], $this->called);
	}//end testATargetOfAnotherOrganisationIsLeftOut()

	/**
	 * A sticky endpoint keeps a consumer on one target and says so.
	 *
	 * @return void
	 */
	public function testAStickyEndpointKeepsTheConsumerOnOneTarget(): void {
		$this->source(uuid: 'a');
		$this->source(uuid: 'b');
		$targets = [['source' => 'a'], ['source' => 'b']];
		$expected = ['a', 'b'][(crc32('consumer-1') % 2)];

		foreach ([0, 1] as $point) {
			$result = $this->router(point: $point)->call(request: self::request(targets: $targets, extra: ['sticky' => true], consumer: 'consumer-1'));
			$this->assertSame($expected, $result->getObject()['routedTarget']);
			$this->assertSame('sticky', $result->getObject()['routedReason']);
		}
	}//end testAStickyEndpointKeepsTheConsumerOnOneTarget()
}//end class

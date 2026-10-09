<?php

/**
 * The ORI resources as seeded integriq endpoints (ori-public-serving Task 1).
 *
 * decidiq's OriController serves eleven anonymous ORI resources from a PHP
 * dispatch table. `register.d/ori-public-serving.json` declares them as
 * integriq endpoints, mappings and rules. These tests run the seeded
 * configuration through the real engine pieces: every seed validated against
 * its register schema, the two `after` rules run with a real MappingService
 * and real JsonLogic over a list and over one object (TC-7, the Gap 1
 * envelope), a target named by slug resolved to the register's own schema
 * (REQ-EP-011), and the fixed filters narrowing the list over what the caller
 * sent (REQ-EP-012).
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/ori-public-serving/specs/ori-public-serving/spec.md#requirement-field-projection-to-ori-popolo-json-ld-shape-req-oripub-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Rule\AvgBsnPolicyRule;
use OCA\Integriq\Rule\CompositeFanoutRule;
use OCA\Integriq\Rule\ReferenceNumberRule;
use OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridge;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\EndpointService;
use OCA\Integriq\Service\EndpointTargetResolver;
use OCA\Integriq\Service\FlowRunnerService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\RateLimit\InboundRateLimitService;
use OCA\Integriq\Service\RuleService;
use OCA\Integriq\Service\StorageService;
use OCA\Integriq\Service\SynchronizationContractService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IRequestId;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use Twig\Loader\ArrayLoader;

/**
 * Tests for the seeded ORI endpoints.
 */
class OriPublicEndpointsTest extends TestCase {

	private const CONTEXT = 'https://argu.co/ns/core';

	/**
	 * The resource table of decidiq's OriController (RESOURCE_MAP,
	 * buildFilters, ORI_TYPE_MAP): schema, fixed filters, ORI type.
	 */
	private const RESOURCES = [
		'organizations' => ['governance-body', ['lifecycle' => 'published'], 'Organization'],
		'persons' => ['person', [], 'Person'],
		'memberships' => ['membership', [], 'Membership'],
		'events' => ['meeting', ['lifecycle' => 'published'], 'Event'],
		'agendaitems' => ['agenda-item', ['lifecycle' => 'published'], 'AgendaItem'],
		'motions' => ['decision', ['isPublished' => 'public', 'decisionType' => 'motion'], 'Motion'],
		'amendments' => ['decision', ['isPublished' => 'public', 'decisionType' => 'amendment'], 'Amendment'],
		'voteevents' => ['voting-round', ['lifecycle' => 'published'], 'VoteEvent'],
		'votes' => ['vote', ['lifecycle' => 'published'], 'Vote'],
		'reports' => ['minutes', ['lifecycle' => 'published'], 'Report'],
		'publications' => ['publication-payload', [], 'Publication'],
	];

	private const MEETING_ID = '3fa85f64-5717-4562-b3fc-2c963f66afa6';

	/**
	 * The integriq object service double; it hands out the register mapper.
	 *
	 * @var ObjectService&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $objectService;


	/**
	 * The seeded objects of the fragment.
	 *
	 * @return array<int, array<string, mixed>> The objects.
	 */
	private static function seeds(): array {
		$fragment = json_decode(
			(string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/register.d/ori-public-serving.json'),
			true,
			flags: JSON_THROW_ON_ERROR
		);

		return $fragment['components']['objects'];
	}//end seeds()

	/**
	 * One seeded object by schema and slug.
	 *
	 * @param string $schema The schema slug.
	 * @param string $slug   The object slug.
	 *
	 * @return array<string, mixed>|null The object, or null.
	 */
	private static function seed(string $schema, string $slug): ?array {
		foreach (self::seeds() as $object) {
			if ($object['@self']['schema'] === $schema && $object['slug'] === $slug) {
				return $object;
			}
		}

		return null;
	}//end seed()

	/**
	 * Build the service with a real MappingService reading the seeded mappings.
	 *
	 * @param EndpointTargetResolver|null $resolver  The target resolver.
	 * @param InboundRateLimitService|null $rateLimit The inbound rate limiter.
	 *
	 * @return EndpointService The service.
	 */
	private function service(?EndpointTargetResolver $resolver = null, ?InboundRateLimitService $rateLimit = null): EndpointService {
		$logger = $this->createMock(LoggerInterface::class);
		$this->objectService = $this->createMock(ObjectService::class);

		$orObjectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
		$orObjectService->method('find')->willReturnCallback(
			function (...$args) {
				$id = (string)($args['id'] ?? $args[0]);
				$schema = (string)($args['schema'] ?? $args[2] ?? '');
				$object = self::seed(schema: $schema, slug: $id);
				if ($object === null) {
					throw new DoesNotExistException('No seed ' . $schema . '/' . $id);
				}

				unset($object['@self']);
				return ObjectServiceMockBuilder::objectEntity($this, $object, $id);
			}
		);

		$mappingService = new MappingService(
			new ArrayLoader([]),
			$this->createMock(CallService::class),
			$this->createMock(\OCA\OpenRegister\Service\FileService::class),
			$this->createMock(ObjectService::class),
			$orObjectService,
			$this->createMock(SynchronizationContractService::class),
		);

		$schema = new class {
			/**
			 * No uri-typed properties.
			 *
			 * @return array
			 */
			public function getProperties(): array {
				return [];
			}
		};
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($schema);

		$consumerScope = $this->createMock(\OCA\Integriq\Service\ConsumerScopeService::class);
		$consumerScope->method('isAllowed')->willReturn(true);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getAbsoluteURL')->willReturnArgument(0);

		return new EndpointService(
			$this->objectService,
			$this->createMock(CallService::class),
			$logger,
			$urlGenerator,
			$mappingService,
			$orObjectService,
			$this->createMock(IConfig::class),
			$this->createMock(StorageService::class),
			$this->createMock(OpenRegisterCredentialBridge::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(SynchronizationService::class),
			$this->createMock(RuleService::class),
			new \OCA\Integriq\Service\WebhookSignatureService($logger),
			($rateLimit ?? $this->createMock(InboundRateLimitService::class)),
			new CompositeFanoutRule(ObjectServiceMockBuilder::make($this), $logger),
			new ReferenceNumberRule(),
			new AvgBsnPolicyRule(),
			$this->createMock(\OCA\Integriq\Service\ApprovalService::class),
			$this->createMock(IRequestId::class),
			$this->createMock(FlowRunnerService::class),
			$consumerScope,
			$schemaMapper,
			$this->createMock(\OCA\OpenRegister\Service\FileService::class),
			null,
			$resolver,
		);
	}//end service()

	/**
	 * Run a resource's `after` rules over a body, as processRules() does:
	 * in order, each only when its conditions pass.
	 *
	 * @param string $resource The ORI resource.
	 * @param mixed  $body     The body the register answered.
	 *
	 * @return mixed The body after the rules.
	 */
	private function afterRules(string $resource, mixed $body): mixed {
		$service = $this->service();
		$endpoint = self::seed(schema: 'endpoint', slug: 'ori-parity-' . $resource);
		$this->assertNotNull($endpoint);

		$conditions = new ReflectionMethod(EndpointService::class, 'checkRuleConditions');
		$conditions->setAccessible(true);
		$mappingRule = new ReflectionMethod(EndpointService::class, 'processMappingRule');
		$mappingRule->setAccessible(true);

		$rules = array_map(
			fn (string $slug) => self::seed(schema: 'rule', slug: $slug),
			$endpoint['rules']
		);
		usort($rules, fn ($a, $b) => ($a['order'] <=> $b['order']));

		$data = ['method' => 'GET', 'body' => $body];
		foreach ($rules as $rule) {
			$this->assertSame('after', $rule['timing']);
			$ruleEntity = ObjectServiceMockBuilder::objectEntity($this, $rule, $rule['slug']);
			$logicResult = null;
			if ($conditions->invokeArgs($service, [$ruleEntity, $data, &$logicResult]) === false) {
				continue;
			}

			$data = $mappingRule->invoke($service, $ruleEntity, $data);
		}

		return $data['body'];
	}//end afterRules()

	/**
	 * A published meeting as the register answers it.
	 *
	 * @param string $id Its uuid.
	 *
	 * @return array<string, mixed> The meeting.
	 */
	private function meeting(string $id = self::MEETING_ID): array {
		return [
			'id' => $id,
			'title' => 'Raadsvergadering 1 september',
			'scheduledDate' => '2026-09-01T19:00:00+00:00',
			'location' => 'Raadszaal',
			'lifecycle' => 'published',
			'meetingType' => 'council-meeting',
			'secretNotes' => 'never public',
			'@self' => ['id' => 'internal-ref', 'register' => 3, 'schema' => 7],
		];
	}//end meeting()

	/**
	 * Every seeded endpoint, mapping and rule is accepted by its register schema.
	 *
	 * @return void
	 */
	public function testEverySeededObjectIsAcceptedByItsSchema(): void {
		$this->assertCount(55, self::seeds());
		foreach (self::seeds() as $object) {
			$schema = $object['@self']['schema'];
			unset($object['@self']);
			$this->assertSame([], RegisterSchemaValidator::errors($schema, $object), $schema . ' ' . $object['slug']);
		}
	}//end testEverySeededObjectIsAcceptedByItsSchema()

	/**
	 * REQ-ORIPUB-001/002: eleven anonymous endpoints, each on decidiq's schema
	 * by slug, each gated by OriController's own filter set, list and item
	 * served by one path pattern under the validation prefix.
	 *
	 * @return void
	 */
	public function testElevenAnonymousEndpointsCarryOriControllersFilters(): void {
		foreach (self::RESOURCES as $resource => [$schema, $filters]) {
			$endpoint = self::seed(schema: 'endpoint', slug: 'ori-parity-' . $resource);
			$this->assertNotNull($endpoint, $resource);
			$this->assertSame('GET', $endpoint['method']);
			$this->assertSame('register/schema', $endpoint['targetType']);
			$this->assertSame('decidiq/' . $schema, $endpoint['targetId']);
			$this->assertSame($filters, ($endpoint['fixedFilters'] ?? []), $resource);

			foreach ($endpoint['rules'] as $ruleSlug) {
				$rule = self::seed(schema: 'rule', slug: $ruleSlug);
				$this->assertSame('mapping', $rule['type'], 'Anonymous: no authentication rule on ' . $resource);
			}

			$this->assertSame(1, preg_match($endpoint['endpointRegex'], 'ori-parity/v1/' . $resource));
			$this->assertSame(1, preg_match($endpoint['endpointRegex'], 'ori-parity/v1/' . $resource . '/' . self::MEETING_ID));
			$this->assertSame(0, preg_match($endpoint['endpointRegex'], 'ori-parity/v1/' . $resource . 'x'));
			$this->assertSame(0, preg_match($endpoint['endpointRegex'], 'ori/v1/' . $resource), 'Only the validation prefix, never decidiq\'s public path.');
		}
	}//end testElevenAnonymousEndpointsCarryOriControllersFilters()

	/**
	 * TC-13 (DECISIONS row 39): every ORI endpoint declares decidiq's CORS
	 * values, so its preflight and its answers allow only the instance's own
	 * origin, GET and OPTIONS, and OriController::applyCorsHeaders()'s headers.
	 *
	 * Red before: the ORI endpoints declared no CORS policy and answered with
	 * integriq's default, which echoes any caller's origin.
	 *
	 * @return void
	 */
	public function testEveryOriEndpointDeclaresDecidiqsCorsValues(): void {
		foreach (array_keys(self::RESOURCES) as $resource) {
			$endpoint = self::seed(schema: 'endpoint', slug: 'ori-parity-' . $resource);
			$this->assertSame(
				[
					'allowedOrigin' => 'self',
					'allowedMethods' => ['GET', 'OPTIONS'],
					'allowedHeaders' => ['Authorization', 'Content-Type', 'X-Requested-With'],
				],
				($endpoint['cors'] ?? null),
				$resource
			);
		}
	}//end testEveryOriEndpointDeclaresDecidiqsCorsValues()

	/**
	 * REQ-EP-014: the endpoint schema types the CORS policy, so a policy that
	 * would grant credentials or name methods as one string is refused.
	 *
	 * @return void
	 */
	public function testTheEndpointSchemaRefusesAMalformedCorsPolicy(): void {
		$endpoint = self::seed(schema: 'endpoint', slug: 'ori-parity-motions');
		unset($endpoint['@self']);
		$this->assertSame([], RegisterSchemaValidator::errors('endpoint', $endpoint), 'Control: the seed itself is accepted.');

		$asString = $endpoint;
		$asString['cors']['allowedMethods'] = 'GET, OPTIONS';
		$this->assertNotSame([], RegisterSchemaValidator::errors('endpoint', $asString));

		$withCredentials = $endpoint;
		$withCredentials['cors']['allowCredentials'] = true;
		$this->assertNotSame([], RegisterSchemaValidator::errors('endpoint', $withCredentials));
	}//end testTheEndpointSchemaRefusesAMalformedCorsPolicy()

	/**
	 * TC-7 (Gap 1): a list answers exactly @context, @type, count and items,
	 * each item in OriSerializer's shape; no paging key leaks through.
	 *
	 * @return void
	 */
	public function testAListAnswersInTheOriShape(): void {
		$body = $this->afterRules(
			'events',
			['count' => 2, 'next' => 'page-2', 'results' => [$this->meeting(), $this->meeting('b2c3')]]
		);

		$this->assertSame(['@context', '@type', 'count', 'items'], array_keys($body));
		$this->assertSame(self::CONTEXT, $body['@context']);
		$this->assertSame('Event', $body['@type']);
		$this->assertSame(2, $body['count']);
		$this->assertSame(
			[
				'@context' => self::CONTEXT,
				'@type' => 'Event',
				'id' => self::MEETING_ID,
				'name' => 'Raadsvergadering 1 september',
				'start_date' => '2026-09-01T19:00:00+00:00',
				'location' => 'Raadszaal',
				'status' => 'published',
				'classification' => 'council-meeting',
			],
			$body['items'][0]
		);
		$this->assertSame('b2c3', $body['items'][1]['id']);
	}//end testAListAnswersInTheOriShape()

	/**
	 * An empty list still answers an empty items list, not the rule's text.
	 *
	 * @return void
	 */
	public function testAnEmptyListAnswersNoItems(): void {
		$body = $this->afterRules('events', ['count' => 0, 'results' => []]);

		$this->assertSame(['@context' => self::CONTEXT, '@type' => 'Event', 'count' => 0, 'items' => []], $body);
	}//end testAnEmptyListAnswersNoItems()

	/**
	 * One object answers the item shape alone: the list rule does not wrap it.
	 *
	 * @return void
	 */
	public function testOneObjectAnswersTheItemWithoutTheListEnvelope(): void {
		$body = $this->afterRules('events', $this->meeting());

		$this->assertSame('Event', $body['@type']);
		$this->assertSame(self::MEETING_ID, $body['id']);
		$this->assertArrayNotHasKey('items', $body);
		$this->assertArrayNotHasKey('secretNotes', $body, 'Only the ORI fields leave the register.');
		$this->assertArrayNotHasKey('end_date', $body, 'A field the object lacks is left out, as OriSerializer leaves it out.');
	}//end testOneObjectAnswersTheItemWithoutTheListEnvelope()

	/**
	 * EMAIL_TYPES: a person carries its email, a membership never does.
	 *
	 * @return void
	 */
	public function testOnlyOrganizationsAndPersonsCarryAnEmail(): void {
		$person = $this->afterRules('persons', ['id' => 'p1', 'name' => 'A. Jansen', 'email' => 'a@example.nl']);
		$membership = $this->afterRules('memberships', ['id' => 'm1', 'email' => 'a@example.nl']);

		$this->assertSame('A. Jansen', $person['name'], 'name falls back to name when there is no title.');
		$this->assertSame('a@example.nl', $person['email']);
		$this->assertArrayNotHasKey('email', $membership);
	}//end testOnlyOrganizationsAndPersonsCarryAnEmail()

	/**
	 * A publication payload declares its own @type and keeps its payload
	 * fields, arrays as arrays.
	 *
	 * @return void
	 */
	public function testAPublicationDeclaresItsOwnTypeAndKeepsItsPayload(): void {
		$body = $this->afterRules(
			'publications',
			[
				'count' => 1,
				'results' => [
					[
						'id' => 'pub-1',
						'title' => 'Besluit over de Raadszaal',
						'oriType' => 'Besluit',
						'voteTotals' => ['for' => 20, 'against' => 5],
						'legalRemedyClause' => 'Bezwaar binnen zes weken.',
						'publicationDate' => '2026-09-01T00:00:00+00:00',
					],
				],
			]
		);

		$this->assertSame('Publication', $body['@type']);
		$item = $body['items'][0];
		$this->assertSame('Besluit', $item['@type']);
		$this->assertSame('Besluit', $item['oriType']);
		$this->assertSame(['for' => 20, 'against' => 5], $item['vote_totals']);
		$this->assertSame('Bezwaar binnen zes weken.', $item['legal_remedy_clause']);
		$this->assertSame('2026-09-01T00:00:00+00:00', $item['published_at']);
		$this->assertArrayNotHasKey('agenda_items', $item);
	}//end testAPublicationDeclaresItsOwnTypeAndKeepsItsPayload()

	/**
	 * A resolver over decidiq's register: id 3, schemas 5 (person) and 7 (meeting).
	 *
	 * @return EndpointTargetResolver The resolver.
	 */
	private function decidiqResolver(): EndpointTargetResolver {
		$register = new class {
			/**
			 * The id.
			 *
			 * @return integer
			 */
			public function getId(): int {
				return 3;
			}

			/**
			 * The register's own schemas.
			 *
			 * @return array
			 */
			public function getSchemas(): array {
				return [5, 7];
			}
		};
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturnCallback(
			function (...$args) use ($register) {
				if (($args['id'] ?? $args[0]) === 'decidiq') {
					return $register;
				}

				throw new DoesNotExistException('no register');
			}
		);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			function (...$args) {
				$id = (int)($args['id'] ?? $args[0]);
				$slug = [5 => 'person', 7 => 'meeting', 99 => 'meeting'][$id];
				return new class($id, $slug) {
					/**
					 * Constructor.
					 *
					 * @param integer $id   The id.
					 * @param string  $slug The slug.
					 */
					public function __construct(private int $id, private string $slug) {
					}

					/**
					 * The id.
					 *
					 * @return integer
					 */
					public function getId(): int {
						return $this->id;
					}

					/**
					 * The slug.
					 *
					 * @return string
					 */
					public function getSlug(): string {
						return $this->slug;
					}
				};
			}
		);

		return new EndpointTargetResolver($registerMapper, $schemaMapper);
	}//end decidiqResolver()

	/**
	 * REQ-EP-011: a target named by slug resolves to the register's own schema.
	 *
	 * @return void
	 */
	public function testATargetNamedBySlugResolvesWithinItsRegister(): void {
		$resolver = $this->decidiqResolver();

		$this->assertSame([3, 7], $resolver->resolve('decidiq/meeting'));
		$this->assertSame([3, 5], $resolver->resolve('decidiq/person'));
		$this->assertSame([20, 111], $resolver->resolve('20/111'), 'Ids are used as they are.');
	}//end testATargetNamedBySlugResolvesWithinItsRegister()

	/**
	 * REQ-EP-011: an unknown register, or a schema outside the register, is not found.
	 *
	 * @return void
	 */
	public function testATargetOutsideTheRegisterIsNotFound(): void {
		$resolver = $this->decidiqResolver();

		try {
			$resolver->resolve('decidiq/vote');
			$this->fail('A schema the register does not hold must not resolve.');
		} catch (DoesNotExistException $e) {
			$this->assertStringContainsString('vote', $e->getMessage());
		}

		$this->expectException(DoesNotExistException::class);
		$resolver->resolve('nope/meeting');
	}//end testATargetOutsideTheRegisterIsNotFound()

	/**
	 * REQ-EP-011 and REQ-EP-012 through the engine: the ORI events endpoint
	 * reaches decidiq's meeting schema by slug, and its list is narrowed to
	 * published meetings even when the caller asks for drafts.
	 *
	 * Red before: the target was cast to (int), so "decidiq/meeting" asked
	 * for register 0 and schema 0, and the list path ignored fixedFilters.
	 *
	 * @return void
	 */
	public function testTheEventsListReachesDecidiqsMeetingsAndOnlyPublishedOnes(): void {
		$service = $this->service($this->decidiqResolver());

		$mapper = $this->createMock(\OCA\OpenRegister\Service\ObjectServiceMapperAdapter::class);
		$mapper->method('getSchema')->willReturn(7);
		$requested = null;
		$mapper->method('findAllPaginated')->willReturnCallback(
			function (array $requestParams) use (&$requested) {
				$requested = $requestParams;
				return ['results' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
			}
		);
		$this->objectService->expects($this->once())
			->method('getMapper')
			->with($this->anything(), 7, 3)
			->willReturn($mapper);

		$flowToken = $this->createMock(\OCA\Integriq\Service\Helper\FlowToken::class);
		$flowToken->method('getRequestAmended')->willReturn(
			['method' => 'GET', 'parameters' => ['lifecycle' => 'draft'], 'headers' => []]
		);

		$endpoint = self::seed(schema: 'endpoint', slug: 'ori-parity-events');
		unset($endpoint['@self']);
		$handle = new ReflectionMethod(EndpointService::class, 'handleSchemaRequest');
		$handle->setAccessible(true);
		$response = $handle->invokeArgs(
			$service,
			[ObjectServiceMockBuilder::objectEntity($this, $endpoint, 'ori-parity-events'), &$flowToken, 'ori-parity/v1/events']
		);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('published', $requested['lifecycle'], 'The fixed filter wins over the caller.');
	}//end testTheEventsListReachesDecidiqsMeetingsAndOnlyPublishedOnes()
	/**
	 * REQ-EP-013 (TC-12's shape): the 121st anonymous request in a minute from
	 * one address answers 429, as decidiq's AnonRateLimit(120, 60) does;
	 * another address, and another ORI endpoint, keep their own count.
	 *
	 * Red before: a caller no consumer identifies was never throttled.
	 *
	 * @return void
	 */
	public function testThe121stAnonymousRequestInAMinuteIsRefused(): void {
		$store = [];
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(
			function (string $key) use (&$store) {
				return ($store[$key] ?? null);
			}
		);
		$cache->method('set')->willReturnCallback(
			function (string $key, $value) use (&$store) {
				$store[$key] = $value;
				return true;
			}
		);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);
		$service = $this->service(null, new InboundRateLimitService($cacheFactory, $this->createMock(LoggerInterface::class)));

		$enforce = new ReflectionMethod(EndpointService::class, 'enforceInboundRateLimit');
		$enforce->setAccessible(true);
		$call = function (string $slug, string $address) use ($service, $enforce) {
			$endpoint = self::seed(schema: 'endpoint', slug: $slug);
			unset($endpoint['@self']);
			$request = $this->createMock(IRequest::class);
			$request->method('getRemoteAddress')->willReturn($address);
			return $enforce->invoke($service, $request, ObjectServiceMockBuilder::objectEntity($this, $endpoint, $slug));
		};

		for ($i = 1; $i <= 120; $i++) {
			$this->assertNull($call('ori-parity-events', '192.0.2.10'), 'Request ' . $i . ' is within the ceiling.');
		}

		$refused = $call('ori-parity-events', '192.0.2.10');
		$this->assertNotNull($refused);
		$this->assertSame(429, $refused->getStatus());
		$this->assertNull($call('ori-parity-events', '192.0.2.11'), 'Another address has its own count.');
		$this->assertNull($call('ori-parity-motions', '192.0.2.10'), 'Another endpoint has its own count.');
	}//end testThe121stAnonymousRequestInAMinuteIsRefused()

	/**
	 * An endpoint without anonymousRateLimit keeps today's behaviour: an
	 * unidentified caller is not throttled and the limiter is not asked.
	 *
	 * @return void
	 */
	public function testAnEndpointWithoutAnAnonymousLimitIsNotThrottled(): void {
		$rateLimit = $this->createMock(InboundRateLimitService::class);
		$rateLimit->expects($this->never())->method('enforce');
		$service = $this->service(null, $rateLimit);

		$enforce = new ReflectionMethod(EndpointService::class, 'enforceInboundRateLimit');
		$enforce->setAccessible(true);

		$this->assertNull(
			$enforce->invoke(
				$service,
				$this->createMock(IRequest::class),
				ObjectServiceMockBuilder::objectEntity($this, ['name' => 'Zaken', 'targetType' => 'register/schema'], 'ep-1')
			)
		);
	}//end testAnEndpointWithoutAnAnonymousLimitIsNotThrottled()
}//end class

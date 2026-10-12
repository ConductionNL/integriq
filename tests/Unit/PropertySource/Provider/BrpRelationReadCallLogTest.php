<?php

/**
 * Unit tests: a BRP relation read leaves a call record that names who asked and holds no body.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\PropertySource\Provider
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/brp-relations-read/specs/registry-field-source/spec.md#requirement-brp-relations-are-read-only-where-the-source-says-the-municipality-may
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\PropertySource\Provider;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use OCA\Integriq\Outbound\Call\BodyCapturePolicy;
use OCA\Integriq\PropertySource\Provider\BrpRelationReader;
use OCA\Integriq\PropertySource\RegistrySourceGateway;
use OCA\Integriq\Service\AuthenticationService;
use OCA\Integriq\Service\BrokeredCallService;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\ConnectionStore;
use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCA\Integriq\PropertySource\PropertySourceResolver;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use Twig\Loader\ArrayLoader;

/**
 * The real reader over the real gateway over the real call engine. Only the
 * HTTP client, the object store and the session are doubles, so what is
 * asserted is the record the call engine hands to the store (decision 229).
 *
 * The source has an OPEN investigation window, the worst case: a recorded
 * read would store both bodies there.
 *
 * @covers \OCA\Integriq\PropertySource\Provider\BrpRelationReader
 * @covers \OCA\Integriq\PropertySource\RegistrySourceGateway
 * @uses \OCA\Integriq\Service\CallService
 * @uses \OCA\Integriq\Outbound\Call\BodyCapturePolicy
 * @uses \OCA\Integriq\Service\Security\SensitiveFieldRegistry
 */
class BrpRelationReadCallLogTest extends TestCase {
	/**
	 * The person asked about.
	 *
	 * @var string
	 */
	private const ASKED = '999990019';

	/**
	 * The source's uuid.
	 *
	 * @var string
	 */
	private const SOURCE = '0b0f4d6e-52a7-4b63-9f1c-2f5d1a7c9e10';

	/**
	 * Everything handed to ObjectService::saveObject().
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $saved = [];

	/**
	 * The answer the seeded source ships.
	 *
	 * @return array<string, mixed>
	 */
	private static function shipped(): array {
		$file = dirname(__DIR__, 4) . '/lib/Settings/register.d/brp-haalcentraal-source.json';
		$data = json_decode((string)file_get_contents($file), true);

		return (array)$data['components']['objects'][0]['configuration']['mockResponse'];
	}//end shipped()

	/**
	 * A reader whose registry answers with the given response.
	 *
	 * @param Response $answer What the registry answers.
	 *
	 * @return BrpRelationReader
	 */
	private function reader(Response $answer): BrpRelationReader {
		$source = new ObjectEntity();
		$source->setUuid(self::SOURCE);
		$source->setObject(
			[
				'name' => 'BRP',
				'slug' => 'brp-haalcentraal',
				'isEnabled' => true,
				'location' => 'https://brp.example.invalid/haalcentraal/api/brp',
				'bodyCaptureUntil' => '2999-01-01T00:00:00+00:00',
				'configuration' => ['relationFields' => true],
			]
		);
		$store = $this->createMock(ConnectionStore::class);
		$store->method('findSourceBySlug')->willReturn($source);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('saveObject')->willReturnCallback(
			function ($object = [], $register = null, $schema = null) {
				$this->saved[] = ['object' => $object, 'schema' => $schema];
				$entity = new ObjectEntity();
				$entity->setObject($object);
				return $entity;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);
		$appConfig->method('getValueInt')->willReturnArgument(2);

		$brokered = $this->createMock(BrokeredCallService::class);
		$brokered->method('hasCredentialRef')->willReturn(false);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('m.jansen');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$calls = new CallService(
			$objectService,
			new ArrayLoader([]),
			$this->createMock(AuthenticationService::class),
			$appConfig,
			$this->createMock(LoggerInterface::class),
			$brokered,
			new SensitiveFieldRegistry(),
			null,
			new BodyCapturePolicy($appConfig, null, $session),
		);
		$client = $this->createMock(Client::class);
		$client->method('request')->willReturn($answer);
		(new ReflectionProperty(CallService::class, 'client'))->setValue($calls, $client);

		return new BrpRelationReader(
			new RegistrySourceGateway($store, $calls, $this->createMock(LoggerInterface::class)),
			$this->createMock(LoggerInterface::class)
		);
	}//end reader()

	/**
	 * Every burgerservicenummer in the shipped answer, plus the one asked about.
	 *
	 * @return array<int,string>
	 */
	private static function numbers(): array {
		preg_match_all('/\d{9}/', (string)json_encode(self::shipped()), $found);

		return array_values(array_unique([self::ASKED, ...$found[0]]));
	}//end numbers()

	/**
	 * The stored record, searched as a string.
	 *
	 * @return void
	 */
	private function assertTheOnlyRecordHoldsNoBody(): void {
		$this->assertCount(1, $this->saved, 'Exactly one row is written, and nothing else is stored');
		$this->assertSame('call_log', $this->saved[0]['schema']);
		$log = $this->saved[0]['object'];
		$stored = (string)json_encode($log);

		foreach (self::numbers() as $number) {
			$this->assertStringNotContainsString($number, $stored);
		}

		foreach (['burgerservicenummer', 'partners', 'ouders', 'kinderen', 'RaadpleegMetBurgerservicenummer', 'personen":'] as $part) {
			$this->assertStringNotContainsString($part, $stored);
		}

		$this->assertSame(self::SOURCE, $log['source']);
		$this->assertSame('m.jansen', $log['userId']);
		$this->assertSame('outbound', $log['direction']);
		$this->assertNotSame('', (string)$log['created']);
		$this->assertFalse($log['bodyCaptured']);
		$this->assertArrayNotHasKey('replayRequest', $log);
		$this->assertSame(
			['url' => 'https://brp.example.invalid/haalcentraal/api/brp/personen', 'method' => 'POST'],
			$log['request']
		);
	}//end assertTheOnlyRecordHoldsNoBody()

	/**
	 * An answered read: the relations come back, and the record names the
	 * asking user, the source and the outcome, without any body.
	 *
	 * @return void
	 */
	public function testAnAnsweredReadLeavesARecordWithoutBodies(): void {
		$answer = $this->reader(new Response(200, [], (string)json_encode(self::shipped())))->read(self::ASKED);

		$this->assertTrue($answer['available']);
		$this->assertNotSame([], $answer['relations'], 'Control: the answer was read, so the shipped numbers were in the response');
		$this->assertGreaterThan(1, count(self::numbers()), 'Control: the search has numbers to look for');
		$this->assertTheOnlyRecordHoldsNoBody();
		$this->assertSame(200, $this->saved[0]['object']['statusCode']);
	}//end testAnAnsweredReadLeavesARecordWithoutBodies()

	/**
	 * A refused read keeps no replay request: the record still holds no body.
	 *
	 * @return void
	 */
	public function testARefusedReadLeavesARecordWithoutBodies(): void {
		$answer = $this->reader(new Response(403, [], '{"detail":"geen autorisatie voor ' . self::ASKED . '"}'))->read(self::ASKED);

		$this->assertFalse($answer['available']);
		$this->assertSame(BrpRelationReader::REASON_UNREACHABLE, $answer['reason']);
		$this->assertTheOnlyRecordHoldsNoBody();
		$this->assertSame(403, $this->saved[0]['object']['statusCode']);
	}//end testARefusedReadLeavesARecordWithoutBodies()

	/**
	 * The value cache stays untouched: it lives in PropertySourceResolver, and
	 * no class on the read's path is handed the resolver, a cache or a cache
	 * factory. The control shows the search finds the resolver's own factory.
	 *
	 * @return void
	 */
	public function testNoClassOnTheReadsPathIsHandedTheValueCache(): void {
		$cacheTypes = [ICache::class, ICacheFactory::class, PropertySourceResolver::class];
		$handed = static function (string $class) use ($cacheTypes): array {
			$found = [];
			foreach (((new ReflectionClass($class))->getConstructor()?->getParameters() ?? []) as $parameter) {
				$type = $parameter->getType();
				if ($type instanceof ReflectionNamedType && in_array($type->getName(), $cacheTypes, true) === true) {
					$found[] = $type->getName();
				}
			}

			return $found;
		};

		$this->assertSame([ICacheFactory::class], $handed(PropertySourceResolver::class), 'Control: the resolver is where the cache is');
		foreach ([BrpRelationReader::class, RegistrySourceGateway::class, CallService::class, BodyCapturePolicy::class] as $class) {
			$this->assertSame([], $handed($class), $class);
		}
	}//end testNoClassOnTheReadsPathIsHandedTheValueCache()

	/**
	 * The container can hand the session to the policy and the policy to the
	 * call engine: both are typed constructor parameters. Without that the
	 * record would be written without a user, in silence.
	 *
	 * @return void
	 */
	public function testTheSessionReachesTheCallEngineThroughTypedConstructorParameters(): void {
		$types = static function (string $class): array {
			$found = [];
			foreach (((new ReflectionClass($class))->getConstructor()?->getParameters() ?? []) as $parameter) {
				$type = $parameter->getType();
				if ($type instanceof ReflectionNamedType) {
					$found[] = $type->getName();
				}
			}

			return $found;
		};

		$this->assertContains(BodyCapturePolicy::class, $types(CallService::class));
		$this->assertContains(IUserSession::class, $types(BodyCapturePolicy::class));
	}//end testTheSessionReachesTheCallEngineThroughTypedConstructorParameters()
}//end class

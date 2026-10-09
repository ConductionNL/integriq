<?php

/**
 * A contact moment is written only when the agent asks (kcc-cti-adapter REQ-007).
 *
 * Drives the real path: CtiEventIntake accepts a webhook event and writes it
 * on CallEventLog; KissSyncService::pushCustomerContact() records the contact
 * moment for that call. Both share one in-memory OpenRegister, and every row
 * either writes is validated against the merged register schema it lands in.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Kiss
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-contact-moment-is-written-only-when-the-agent-asks-req-007
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Kiss;

use DateInterval;
use DateTimeImmutable;
use OCA\Integriq\Event\CallEvent;
use OCA\Integriq\Exception\CallEventNotFoundException;
use OCA\Integriq\Repair\InitializeRegister;
use OCA\Integriq\Service\KissSyncService;
use OCA\Integriq\Service\Kiss\CallContextService;
use OCA\Integriq\Service\Kiss\CallerDirectory;
use OCA\Integriq\Service\Kiss\CallerLookup;
use OCA\Integriq\Service\Kiss\CallEventDeduplicator;
use OCA\Integriq\Service\Kiss\CallEventLog;
use OCA\Integriq\Service\Kiss\CtiEventIntake;
use OCA\Integriq\Service\Kiss\CtiProviderInterface;
use OCA\Integriq\Service\Kiss\CtiSourceResolver;
use OCA\Integriq\Service\Kiss\KlantinteractiesClient;
use OCA\Integriq\Service\Kiss\LogKlantinteractiesProvider;
use OCA\Integriq\Service\Security\RawSourceResolver;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IL10N;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * Contact moment on request, and the 30-day call event log.
 */
class ContactMomentForCallTest extends TestCase {

	/**
	 * The in-memory OpenRegister: schema slug => list of [uuid, object].
	 *
	 * @var array<string, list<array{uuid: string, object: array}>>
	 */
	private array $store = [];

	/**
	 * Every saveObject() call: schema slug, object and the _rbac flag.
	 *
	 * @var list<array{schema: string, object: array, rbac: bool}>
	 */
	private array $saves = [];

	/**
	 * Payloads the KISS provider was asked to create.
	 *
	 * @var list<array>
	 */
	private array $wire = [];

	/**
	 * Events dispatched by the intake.
	 *
	 * @var list<Event>
	 */
	private array $dispatched = [];

	/**
	 * The merged register, loaded once.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $register = null;

	/**
	 * Builds the shared in-memory OpenRegister.
	 *
	 * @return ORObjectService
	 */
	private function openRegister(): ORObjectService {
		$objectService = $this->getMockBuilder(ORObjectService::class)
			->disableOriginalConstructor()
			->getMock();

		$objectService->method('saveObject')->willReturnCallback(
			function (...$args): ObjectEntity {
				// Positional, as PHPUnit hands them over: object, register,
				// schema, uuid, _rbac (OpenRegister's saveObject() order).
				$object = $args[0];
				$schema = (string) ($args[2] ?? '');
				$uuid   = ($args[3] ?? null);
				$this->saves[] = ['schema' => $schema, 'object' => $object, 'rbac' => (bool) ($args[4] ?? true)];
				$uuid ??= $schema.'-'.(count($this->store[$schema] ?? []) + 1);
				$this->store[$schema][] = ['uuid' => $uuid, 'object' => $object];

				return ObjectServiceMockBuilder::objectEntity($this, $object, $uuid);
			}
		);

		$objectService->method('findAll')->willReturnCallback(
			function (...$args): array {
				$config  = ($args[0] ?? []);
				$filters = ($config['filters'] ?? []);
				$schema  = (string) ($filters['schema'] ?? '');
				unset($filters['register'], $filters['schema']);

				$results = [];
				foreach (($this->store[$schema] ?? []) as $row) {
					foreach ($filters as $key => $value) {
						if (($row['object'][$key] ?? null) !== $value) {
							continue 2;
						}
					}

					$results[] = ObjectServiceMockBuilder::objectEntity($this, $row['object'], $row['uuid']);
				}

				return ['results' => $results];
			}
		);

		return $objectService;

	}//end openRegister()

	/**
	 * The intake, with a CTI provider that hands back the given events.
	 *
	 * @param ORObjectService $objectService The shared OpenRegister.
	 * @param array           $events        Normalised events the provider returns.
	 *
	 * @return CtiEventIntake
	 */
	private function intake(ORObjectService $objectService, array $events): CtiEventIntake {
		$provider = $this->createMock(originalClassName: CtiProviderInterface::class);
		$provider->method('normalize')->willReturn($events);

		$resolver = $this->createMock(originalClassName: CtiSourceResolver::class);
		$resolver->method('provider')->willReturn($provider);

		$seen  = [];
		$cache = $this->createMock(originalClassName: ICache::class);
		$cache->method('hasKey')->willReturnCallback(
			static function (string $key) use (&$seen): bool {
				return array_key_exists($key, $seen);
			}
		);
		$cache->method('set')->willReturnCallback(
			static function (string $key) use (&$seen): bool {
				$seen[$key] = 1;
				return true;
			}
		);
		$factory = $this->createMock(originalClassName: ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(true);
		$factory->method('createDistributed')->willReturn($cache);

		$dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event): void {
				$this->dispatched[] = $event;
			}
		);

		return new CtiEventIntake(
			sources: $resolver,
			deduplicator: new CallEventDeduplicator(cacheFactory: $factory),
			context: new CallContextService(
				lookup: new CallerLookup(),
				directory: new CallerDirectory(search: null),
				logger: new NullLogger()
			),
			dispatcher: $dispatcher,
			logger: new NullLogger(),
			callEvents: new CallEventLog(objectService: $objectService)
		);

	}//end intake()

	/**
	 * The KISS service, on the same OpenRegister, with one active log-provider source.
	 *
	 * @param ORObjectService $objectService The shared OpenRegister.
	 *
	 * @return KissSyncService
	 */
	private function kiss(ORObjectService $objectService): KissSyncService {
		$this->store[KissSyncService::SCHEMA_SOURCE][] = [
			'uuid'   => 'kiss-source-1',
			'object' => [
				'type'          => 'kiss',
				'isEnabled'     => true,
				'configuration' => ['provider' => 'log'],
			],
		];

		$wire     = &$this->wire;
		$recorder = new class($wire) extends LogKlantinteractiesProvider {

			/**
			 * Constructor.
			 *
			 * @param array $wire Where created payloads are written.
			 */
			public function __construct(private array &$wire) {
			}//end __construct()

			/**
			 * Records the payload, then answers as the log provider does.
			 *
			 * @param array $sourceConfiguration Source configuration.
			 * @param array $payload             The wire payload.
			 *
			 * @return string
			 */
			public function createCustomerContact(array $sourceConfiguration, array $payload): string {
				$this->wire[] = $payload;
				return parent::createCustomerContact(sourceConfiguration: $sourceConfiguration, payload: $payload);
			}//end createCustomerContact()
		};

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);

		return new KissSyncService(
			$objectService,
			$recorder,
			$this->getMockBuilder(KlantinteractiesClient::class)->disableOriginalConstructor()->getMock(),
			$l,
			new NullLogger(),
			new RawSourceResolver($objectService, new NullLogger()),
			new CallEventLog(objectService: $objectService)
		);

	}//end kiss()

	/**
	 * An ended event as a CTI provider normalises it.
	 *
	 * @param string $callId   The call id.
	 * @param string $kind     The event kind.
	 * @param int    $duration Duration in seconds.
	 *
	 * @return array
	 */
	private static function event(string $callId, string $kind=CallEvent::KIND_ENDED, int $duration=184): array {
		return [
			'kind'            => $kind,
			'callId'          => $callId,
			'callerNumber'    => '+31612345678',
			'agentId'         => 'agent-7',
			'at'              => '2026-10-05T09:15:00+02:00',
			'durationSeconds' => $duration,
		];

	}//end event()

	/**
	 * The register as InitializeRegister imports it: base plus every fragment.
	 *
	 * @return array<string, mixed>
	 */
	private static function register(): array {
		if (self::$register !== null) {
			return self::$register;
		}

		$root       = dirname(__DIR__, 4);
		$descriptor = json_decode((string) file_get_contents($root.'/lib/Settings/integriq_register.json'), true, flags: JSON_THROW_ON_ERROR);
		$merge      = new ReflectionMethod(InitializeRegister::class, 'deepMergeConfig');
		$fragments  = glob($root.'/lib/Settings/register.d/*.json');
		sort($fragments);
		foreach ($fragments as $fragmentPath) {
			$fragment = json_decode((string) file_get_contents($fragmentPath), true);
			if (is_array($fragment) === true) {
				$descriptor = $merge->invoke(null, $descriptor, $fragment);
			}
		}

		self::$register = $descriptor;
		return $descriptor;

	}//end register()

	/**
	 * Validation errors of an object against a merged register schema.
	 *
	 * @param array  $object The object as saved.
	 * @param string $slug   The schema slug.
	 *
	 * @return array Errors, empty when valid.
	 */
	private static function schemaErrors(array $object, string $slug): array {
		$schema = (self::register()['components']['schemas'][$slug] ?? null);
		self::assertIsArray($schema, 'schema '.$slug.' is not in the merged register');

		$result = (new Validator())->validate(
			json_decode(json_encode($object, JSON_THROW_ON_ERROR)),
			json_encode($schema, JSON_THROW_ON_ERROR)
		);
		if ($result->isValid() === true) {
			return [];
		}

		return (new ErrorFormatter())->format($result->error());

	}//end schemaErrors()

	/**
	 * Saved objects of one schema.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return list<array{schema: string, object: array, rbac: bool}>
	 */
	private function savesOf(string $schema): array {
		return array_values(array_filter($this->saves, static fn (array $save): bool => $save['schema'] === $schema));

	}//end savesOf()

	/**
	 * Scenario "The agent records the call": one klantcontact, kanaal telefoon,
	 * the duration, linked to the case.
	 *
	 * @return void
	 */
	public function testTheAgentRecordsTheCall(): void {
		$openRegister = $this->openRegister();
		$this->intake($openRegister, [self::event('42')])->handle(source: [], sourceId: 'pbx-1', payload: []);

		$result = $this->kiss($openRegister)->pushCustomerContact(
			input: [
				'onderwerp'     => 'Vraag over de aanvraag',
				'channel'       => 'e-mail',
				'callId'        => '42',
				'caseReference' => 'case-uuid-1',
				'sourceApp'     => 'dossiq',
			]
		);

		$this->assertCount(1, $this->wire, 'exactly one klantcontact is created');
		$this->assertSame('telefoon', $this->wire[0]['kanaal'], 'a call is recorded on the phone channel, whatever the panel posted');
		$this->assertSame('2026-10-05T09:15:00+02:00', $this->wire[0]['plaatsgevondenOp']);
		$this->assertArrayNotHasKey('durationSeconds', $this->wire[0], 'the Klantinteracties API has no duration field');

		$mirrors = $this->savesOf(KissSyncService::SCHEMA_KLANTCONTACT);
		$this->assertCount(1, $mirrors);
		$mirror = $mirrors[0]['object'];
		$this->assertSame('42', $mirror['callId']);
		$this->assertSame(184, $mirror['durationSeconds']);
		$this->assertSame('telefoon', $mirror['channel']);
		$this->assertSame('case-uuid-1', $mirror['caseReference']);
		$this->assertSame($result['id'], $mirror['kissId']);
		$this->assertSame([], self::schemaErrors($mirror, KissSyncService::SCHEMA_KLANTCONTACT));

	}//end testTheAgentRecordsTheCall()

	/**
	 * A second push for the same call answers with the first contact moment.
	 *
	 * @return void
	 */
	public function testASecondPushForTheSameCallCreatesNothing(): void {
		$openRegister = $this->openRegister();
		$this->intake($openRegister, [self::event('42')])->handle(source: [], sourceId: 'pbx-1', payload: []);
		$kiss = $this->kiss($openRegister);

		$first  = $kiss->pushCustomerContact(input: ['onderwerp' => 'Vraag', 'channel' => 'telefoon', 'callId' => '42']);
		$second = $kiss->pushCustomerContact(input: ['onderwerp' => 'Vraag', 'channel' => 'telefoon', 'callId' => '42']);

		$this->assertCount(1, $this->wire);
		$this->assertSame($first, $second);

	}//end testASecondPushForTheSameCallCreatesNothing()

	/**
	 * A callId the log does not hold, or a call that has not ended, is refused
	 * before anything reaches KISS.
	 *
	 * @return void
	 */
	public function testAnUnknownOrUnendedCallIsRefused(): void {
		$openRegister = $this->openRegister();
		$this->intake($openRegister, [self::event('43', CallEvent::KIND_RINGING, 0)])->handle(source: [], sourceId: 'pbx-1', payload: []);
		$kiss = $this->kiss($openRegister);

		foreach (['43', '44'] as $callId) {
			try {
				$kiss->pushCustomerContact(input: ['onderwerp' => 'Vraag', 'channel' => 'telefoon', 'callId' => $callId]);
				$this->fail('call '.$callId.' was accepted');
			} catch (CallEventNotFoundException $e) {
				$this->assertStringNotContainsString('+316', $e->getMessage());
			}
		}

		$this->assertSame([], $this->wire);
		$this->assertSame([], $this->savesOf(KissSyncService::SCHEMA_KLANTCONTACT));

	}//end testAnUnknownOrUnendedCallIsRefused()

	/**
	 * One callId ended on two sources is two calls: refused without a
	 * sourceId, recorded on the named one with it.
	 *
	 * @return void
	 */
	public function testOneCallIdOnTwoSourcesNeedsTheSource(): void {
		$openRegister = $this->openRegister();
		$this->intake($openRegister, [self::event('7', duration: 30)])->handle(source: [], sourceId: 'pbx-1', payload: []);
		$this->intake($openRegister, [self::event('7', duration: 95)])->handle(source: [], sourceId: 'pbx-2', payload: []);
		$kiss = $this->kiss($openRegister);

		try {
			$kiss->pushCustomerContact(input: ['onderwerp' => 'Vraag', 'channel' => 'telefoon', 'callId' => '7']);
			$this->fail('an ambiguous callId was accepted');
		} catch (CallEventNotFoundException) {
			$this->assertSame([], $this->wire);
		}

		$kiss->pushCustomerContact(input: ['onderwerp' => 'Vraag', 'channel' => 'telefoon', 'callId' => '7', 'callSourceId' => 'pbx-2']);
		$this->assertSame(95, $this->savesOf(KissSyncService::SCHEMA_KLANTCONTACT)[0]['object']['durationSeconds']);

	}//end testOneCallIdOnTwoSourcesNeedsTheSource()

	/**
	 * Scenario "Nobody asks": the intake writes the call event, in system
	 * context and valid against the schema, and never a klantcontact. A retry
	 * writes nothing twice.
	 *
	 * @return void
	 */
	public function testTheIntakeLogsTheCallAndNeverWritesAContactMoment(): void {
		$openRegister = $this->openRegister();
		$intake       = $this->intake($openRegister, [self::event('42', CallEvent::KIND_RINGING, 0), self::event('42')]);

		$this->assertSame(2, $intake->handle(source: [], sourceId: 'pbx-1', payload: []));
		$this->assertSame(0, $intake->handle(source: [], sourceId: 'pbx-1', payload: []), 'a retry is claimed once');

		$logged = $this->savesOf(CallEventLog::SCHEMA);
		$this->assertCount(2, $logged);
		$this->assertCount(2, $this->dispatched);
		$this->assertSame(['ringing', 'ended'], array_column(array_column($logged, 'object'), 'kind'));
		foreach ($logged as $save) {
			$this->assertFalse($save['rbac'], 'the webhook has no session; the log is written in system context');
			$this->assertSame([], self::schemaErrors($save['object'], CallEventLog::SCHEMA));
		}

		$this->assertSame(184, $logged[1]['object']['durationSeconds']);
		$this->assertSame('pbx-1', $logged[1]['object']['sourceId']);
		$this->assertSame([], $this->savesOf(KissSyncService::SCHEMA_KLANTCONTACT));

	}//end testTheIntakeLogsTheCallAndNeverWritesAContactMoment()

	/**
	 * A withheld number is logged as empty, and the schema accepts that.
	 *
	 * @return void
	 */
	public function testAWithheldNumberIsLoggedEmpty(): void {
		$openRegister = $this->openRegister();
		$event        = self::event('50');
		$event['callerNumber'] = '';
		$this->intake($openRegister, [$event])->handle(source: [], sourceId: 'pbx-1', payload: []);

		$logged = $this->savesOf(CallEventLog::SCHEMA);
		$this->assertSame('', $logged[0]['object']['callerNumber']);
		$this->assertSame([], self::schemaErrors($logged[0]['object'], CallEventLog::SCHEMA));

	}//end testAWithheldNumberIsLoggedEmpty()

	/**
	 * Scenario "Nobody asks", the retention half: the schema declares 30 days
	 * and nothing longer, and is closed to non-administrators. Fixed clock.
	 *
	 * @return void
	 */
	public function testCallEventsAreKeptThirtyDays(): void {
		$schema = (self::register()['components']['schemas'][CallEventLog::SCHEMA] ?? null);
		$this->assertIsArray($schema);
		$this->assertContains(CallEventLog::SCHEMA, self::register()['components']['registers']['integriq']['schemas']);

		$retention = ($schema['x-openregister-archival']['retention'] ?? []);
		$this->assertSame('P30D', ($retention['default'] ?? null));
		$this->assertSame([], ($retention['rules'] ?? []), 'no rule may keep a phone number longer');

		$written   = new DateTimeImmutable('2026-09-01T10:00:00+00:00');
		$expiresAt = $written->add(new DateInterval($retention['default']));
		$this->assertTrue(new DateTimeImmutable('2026-10-01T10:00:01+00:00') > $expiresAt, 'gone after 30 days');
		$this->assertTrue(new DateTimeImmutable('2026-09-30T23:59:59+00:00') < $expiresAt, 'kept within 30 days');

		$this->assertSame(
			['create' => [], 'read' => [], 'update' => [], 'delete' => []],
			$schema['authorization'],
			'a row holds a phone number: admin and owner only'
		);

	}//end testCallEventsAreKeptThirtyDays()

}//end class

<?php

/**
 * Unit tests for IntakeRoutingService and IntakeReplyService.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Intake;

use OCA\Integriq\Event\IntakeMessageRoutedEvent;
use OCA\Integriq\Exception\IntakeChannelException;
use OCA\Integriq\Exception\IntakeRoutingException;
use OCA\Integriq\Intake\Adapter\FormSubmissionAdapter;
use OCA\Integriq\Intake\Adapter\MessagingChannelAdapter;
use OCA\Integriq\Intake\Adapter\PublicSpaceReportAdapter;
use OCA\Integriq\Intake\InboundMessage;
use OCA\Integriq\Intake\IntakeChannelRegistry;
use OCA\Integriq\Intake\IntakeChannelSourceResolver;
use OCA\Integriq\Intake\IntakeReplyService;
use OCA\Integriq\Intake\IntakeRoutingService;
use OCA\Integriq\Intake\ReplyResult;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Http\Client\IClientService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests routing, holding, per-item isolation, mapping validation and replies.
 *
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-routing-rule-maps-a-channel-and-a-payload-onto-a-case-type-req-ic-002
 */
class IntakeRoutingServiceTest extends TestCase {

	/**
	 * The OR object service double.
	 *
	 * @var ORObjectService|MockObject
	 */
	private $objectService;

	/**
	 * The event dispatcher double.
	 *
	 * @var IEventDispatcher|MockObject
	 */
	private $eventDispatcher;

	/**
	 * The schema mapper double.
	 *
	 * @var SchemaMapper|MockObject
	 */
	private $schemaMapper;

	/**
	 * Every payload the service saved, in order.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $saved = [];

	/**
	 * The rules findAll() answers with.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $rules = [];

	/**
	 * The service under test.
	 *
	 * @var IntakeRoutingService
	 */
	private IntakeRoutingService $service;

	/**
	 * Set up the service with recording doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->saved = [];
		$this->rules = [];

		$this->objectService = ObjectServiceMockBuilder::make($this);
		$this->objectService->method('findAll')->willReturnCallback(
			function (): array {
				$rows = [];
				foreach ($this->rules as $index => $rule) {
					$rows[] = ObjectServiceMockBuilder::objectEntity($this, $rule, 'rule-' . $index);
				}

				return ['results' => $rows, 'total' => count($rows)];
			}
		);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) {
				$this->saved[] = $object;
				return ObjectServiceMockBuilder::objectEntity($this, $object, 'intake-uuid');
			}
		);

		$this->eventDispatcher = $this->createMock(IEventDispatcher::class);
		$this->schemaMapper = $this->getMockBuilder(SchemaMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['find'])
			->getMock();

		$this->service = new IntakeRoutingService(
			$this->objectService,
			$this->eventDispatcher,
			$this->schemaMapper,
			$this->createMock(LoggerInterface::class),
		);

	}//end setUp()

	/**
	 * Two channels land on the two case types their rules name.
	 *
	 * @return void
	 */
	public function testTwoChannelsLandOnTwoDifferentCaseTypes(): void {
		$this->rules = [
			[
				'name' => 'Meldingen',
				'channelId' => 'public-space-report',
				'targetSchema' => 'melding_openbare_ruimte',
				'fieldMapping' => ['omschrijving' => 'text'],
				'locationField' => 'locatie',
				'isEnabled' => true,
				'order' => 10,
			],
			[
				'name' => 'Berichten',
				'channelId' => 'messaging',
				'targetSchema' => 'algemene_vraag',
				'fieldMapping' => ['vraag' => 'text'],
				'isEnabled' => true,
				'order' => 10,
			],
		];

		$seen = [];
		$this->listenWith(
			static function (IntakeMessageRoutedEvent $event) use (&$seen): void {
				$seen[] = ['target' => $event->getTargetSchema(), 'payload' => $event->getTargetPayload()];
				$event->setCreatedRef('zaak/' . count($seen));
			}
		);

		$this->service->route($this->report());
		$this->service->route($this->chat());

		$this->assertSame('melding_openbare_ruimte', $seen[0]['target']);
		$this->assertSame('De lantaarnpaal brandt niet.', $seen[0]['payload']['omschrijving']);
		$this->assertSame(52.0907, $seen[0]['payload']['locatie']['latitude']);
		$this->assertSame('algemene_vraag', $seen[1]['target']);

		$final = end($this->saved);
		$this->assertSame(IntakeRoutingService::STATUS_ROUTED, $final['status']);
		$this->assertSame('zaak/2', $final['targetRef']);

	}//end testTwoChannelsLandOnTwoDifferentCaseTypes()

	/**
	 * A message matching no rule is held with its reason, and no case opens.
	 *
	 * @return void
	 */
	public function testAnUnroutableMessageIsHeldNotLost(): void {
		$this->rules = [];
		$this->eventDispatcher->expects($this->never())->method('dispatchTyped');

		$stored = $this->service->route($this->chat());
		$object = $stored->getObject();

		$this->assertSame(IntakeRoutingService::STATUS_HELD, $object['status']);
		$this->assertStringContainsString('messaging', $object['reason']);
		$this->assertSame('', (string)($object['targetRef'] ?? ''));

	}//end testAnUnroutableMessageIsHeldNotLost()

	/**
	 * A rule that matched but that nobody answered holds the message too: a
	 * routed message with no object behind it would read as handled.
	 *
	 * @return void
	 */
	public function testAMessageNobodyClaimsIsHeld(): void {
		$this->rules = [
			[
				'name' => 'Berichten',
				'channelId' => 'messaging',
				'targetSchema' => 'algemene_vraag',
				'fieldMapping' => ['vraag' => 'text'],
				'isEnabled' => true,
			],
		];
		$this->listenWith(static function (IntakeMessageRoutedEvent $event): void {
		});

		$object = $this->service->route($this->chat())->getObject();

		$this->assertSame(IntakeRoutingService::STATUS_HELD, $object['status']);
		$this->assertStringContainsString('algemene_vraag', $object['reason']);

	}//end testAMessageNobodyClaimsIsHeld()

	/**
	 * A condition decides which of two rules on one channel applies.
	 *
	 * @return void
	 */
	public function testTheFirstMatchingRuleWins(): void {
		$this->rules = [
			[
				'name' => 'Spoed',
				'channelId' => 'messaging',
				'targetSchema' => 'spoedmelding',
				'condition' => ['field' => 'text', 'operator' => 'contains', 'value' => 'spoed'],
				'fieldMapping' => ['vraag' => 'text'],
				'isEnabled' => true,
				'order' => 10,
			],
			[
				'name' => 'Rest',
				'channelId' => 'messaging',
				'targetSchema' => 'algemene_vraag',
				'fieldMapping' => ['vraag' => 'text'],
				'isEnabled' => true,
				'order' => 20,
			],
		];

		$targets = [];
		$this->listenWith(
			static function (IntakeMessageRoutedEvent $event) use (&$targets): void {
				$targets[] = $event->getTargetSchema();
				$event->setCreatedRef('zaak/1');
			}
		);

		$this->service->route($this->chat('Dit is spoed, water op straat'));
		$this->service->route($this->chat('Wanneer komt de reiniging?'));

		$this->assertSame(['spoedmelding', 'algemene_vraag'], $targets);

	}//end testTheFirstMatchingRuleWins()

	/**
	 * One message that cannot be handled does not stop its batch.
	 *
	 * @return void
	 */
	public function testOneBadMessageDoesNotStopTheChannel(): void {
		$this->rules = [
			[
				'name' => 'Berichten',
				'channelId' => 'messaging',
				'targetSchema' => 'algemene_vraag',
				'fieldMapping' => ['vraag' => 'text'],
				'isEnabled' => true,
			],
		];

		$calls = 0;
		$this->eventDispatcher->method('dispatchTyped')->willReturnCallback(
			static function (Event $event) use (&$calls): void {
				$calls++;
				if ($calls === 2) {
					throw new \RuntimeException('the listener blew up on this one');
				}

				if (($event instanceof IntakeMessageRoutedEvent) === true) {
					$event->setCreatedRef('zaak/' . $calls);
				}
			}
		);

		$result = $this->service->routeBatch([$this->chat('een'), $this->chat('twee'), $this->chat('drie')]);

		$this->assertSame(2, $result['routed']);
		$this->assertSame(1, $result['failed']);
		$this->assertStringContainsString('blew up', $result['failures'][0]);

		$captured = array_values(
			array_filter(
				$this->saved,
				static fn (array $object): bool => (($object['status'] ?? '') === IntakeRoutingService::STATUS_FAILED)
			)
		);
		$this->assertCount(1, $captured);

	}//end testOneBadMessageDoesNotStopTheChannel()

	/**
	 * A mapping onto a field the case type does not have is refused when the
	 * rule is saved, naming the field.
	 *
	 * @return void
	 */
	public function testAMappingOntoAMissingFieldIsRefusedAtSave(): void {
		$this->schemaMapper->method('find')->willReturn($this->caseType(['omschrijving', 'locatie']));

		$this->expectException(IntakeRoutingException::class);
		$this->expectExceptionMessage('spoedeisend');

		$this->service->validateRule(
			[
				'name' => 'Meldingen',
				'channelId' => 'public-space-report',
				'targetSchema' => 'melding_openbare_ruimte',
				'fieldMapping' => ['omschrijving' => 'text', 'spoedeisend' => 'fields.category'],
			],
			$this->registry()
		);

	}//end testAMappingOntoAMissingFieldIsRefusedAtSave()

	/**
	 * A rule naming a channel nothing answers to is refused as well.
	 *
	 * @return void
	 */
	public function testARuleOnAnUnknownChannelIsRefused(): void {
		$this->expectException(IntakeRoutingException::class);
		$this->expectExceptionMessage('telegram');

		$this->service->validateRule(
			['name' => 'Telegram', 'channelId' => 'telegram', 'targetSchema' => 'algemene_vraag'],
			$this->registry()
		);

	}//end testARuleOnAnUnknownChannelIsRefused()

	/**
	 * A rule whose mapping the case type can hold is accepted.
	 *
	 * @return void
	 */
	public function testAValidRuleIsAccepted(): void {
		$this->schemaMapper->method('find')->willReturn($this->caseType(['omschrijving', 'locatie']));

		$this->service->validateRule(
			[
				'name' => 'Meldingen',
				'channelId' => 'public-space-report',
				'targetSchema' => 'melding_openbare_ruimte',
				'fieldMapping' => ['omschrijving' => 'text'],
				'locationField' => 'locatie',
			],
			$this->registry()
		);

		$this->expectNotToPerformAssertions();

	}//end testAValidRuleIsAccepted()

	/**
	 * A channel supplying no location writes none, rather than a zero that
	 * looks measured.
	 *
	 * @return void
	 */
	public function testNoLocationMeansNoLocationField(): void {
		$rule = [
			'name' => 'Berichten',
			'channelId' => 'messaging',
			'targetSchema' => 'algemene_vraag',
			'fieldMapping' => ['vraag' => 'text'],
			'locationField' => 'locatie',
		];

		$payload = $this->service->buildTargetPayload($rule, $this->chat());

		$this->assertArrayNotHasKey('locatie', $payload);

	}//end testNoLocationMeansNoLocationField()

	/**
	 * A reply on a channel that cannot carry one reports it and sends nothing.
	 *
	 * @return void
	 */
	public function testAReplyOnAOneWayChannelIsUnsupported(): void {
		$stored = ObjectServiceMockBuilder::objectEntity(
			$this,
			['channelId' => 'public-space-report', 'externalId' => 'MOR-1', 'correspondent' => []],
			'intake-uuid'
		);
		$objectService = ObjectServiceMockBuilder::make($this);
		$objectService->method('find')->willReturn($stored);
		$recorded = [];
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$recorded) {
				$recorded[] = $object;
				return ObjectServiceMockBuilder::objectEntity($this, $object, 'intake-uuid');
			}
		);

		$service = new IntakeReplyService($objectService, $this->registry());
		$result = $service->reply('intake-uuid', 'Dank voor uw melding.');

		$this->assertSame(ReplyResult::STATUS_UNSUPPORTED, $result->getStatus());
		$this->assertCount(1, $recorded);
		$this->assertSame(ReplyResult::STATUS_UNSUPPORTED, $recorded[0]['replies'][0]['status']);

	}//end testAReplyOnAOneWayChannelIsUnsupported()

	/**
	 * Replying to a message that does not exist fails rather than inventing
	 * somewhere to send it.
	 *
	 * @return void
	 */
	public function testAReplyToAnUnknownMessageFails(): void {
		$objectService = ObjectServiceMockBuilder::make($this);
		$objectService->method('find')->willThrowException(
			new \OCP\AppFramework\Db\DoesNotExistException('no such object')
		);

		$service = new IntakeReplyService($objectService, $this->registry());

		$this->expectException(IntakeChannelException::class);
		$service->reply('missing', 'Hallo');

	}//end testAReplyToAnUnknownMessageFails()

	/**
	 * Wire a listener into the dispatcher double.
	 *
	 * @param callable $listener What the listener does with the event.
	 *
	 * @return void
	 */
	private function listenWith(callable $listener): void {
		$this->eventDispatcher->method('dispatchTyped')->willReturnCallback(
			static function (Event $event) use ($listener): void {
				if (($event instanceof IntakeMessageRoutedEvent) === true) {
					$listener($event);
				}
			}
		);

	}//end listenWith()

	/**
	 * The registry holding the three shipped adapters.
	 *
	 * @return IntakeChannelRegistry The registry.
	 */
	private function registry(): IntakeChannelRegistry {
		$resolver = $this->getMockBuilder(IntakeChannelSourceResolver::class)
			->disableOriginalConstructor()
			->onlyMethods(['sourceFor', 'configurationFor'])
			->getMock();
		$resolver->method('configurationFor')->willReturn(['mock' => true]);

		return new IntakeChannelRegistry(
			$this->createMock(LoggerInterface::class),
			[
				new FormSubmissionAdapter(),
				new MessagingChannelAdapter($resolver, $this->createMock(IClientService::class)),
				new PublicSpaceReportAdapter(),
			]
		);

	}//end registry()

	/**
	 * A case type declaring the given fields.
	 *
	 * @param array<int,string> $fields The field names.
	 *
	 * @return object The schema stand-in.
	 */
	private function caseType(array $fields): object {
		$properties = [];
		foreach ($fields as $field) {
			$properties[$field] = ['type' => 'string'];
		}

		return new class($properties) {

			/**
			 * Constructor.
			 *
			 * @param array<string,mixed> $properties The declared properties.
			 */
			public function __construct(private readonly array $properties) {

			}

			/**
			 * The declared properties.
			 *
			 * @return array<string,mixed> The properties.
			 */
			public function getProperties(): array {
				return $this->properties;
			}
		};

	}//end caseType()

	/**
	 * A public space report.
	 *
	 * @return InboundMessage The message.
	 */
	private function report(): InboundMessage {
		return (new PublicSpaceReportAdapter())->receive(
			[
				'id' => 'MOR-1',
				'reporter' => ['name' => 'Jan Burger'],
				'description' => 'De lantaarnpaal brandt niet.',
				'category' => 'verlichting',
				'location' => ['latitude' => 52.0907, 'longitude' => 5.1214],
				'reportedAt' => '2026-09-15T10:00:00+02:00',
			]
		);

	}//end report()

	/**
	 * A messaging message.
	 *
	 * @param string $text What it says.
	 *
	 * @return InboundMessage The message.
	 */
	private function chat(string $text = 'Wanneer komt de reiniging?'): InboundMessage {
		return new InboundMessage(
			'messaging',
			'WA-' . substr(sha1($text), 0, 6),
			['phone' => '+31612345678', 'name' => 'Jan'],
			$text,
			[],
			null,
			[],
			[],
			'2026-09-15T11:00:00+02:00',
			['text' => $text],
		);

	}//end chat()

}//end class

<?php

/**
 * Unit tests for the opt-out, the unsubscribe token, the hold window and the
 * no-reply handler.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Outbound
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Outbound;

use DateTime;
use OCA\Integriq\Outbound\BodyRedactor;
use OCA\Integriq\Outbound\ChannelReportingCapabilities;
use OCA\Integriq\Outbound\Identity\HoldQueue;
use OCA\Integriq\Outbound\Identity\MessageComposer;
use OCA\Integriq\Outbound\Identity\NoReplyHandler;
use OCA\Integriq\Outbound\Identity\OptOutRegistry;
use OCA\Integriq\Outbound\Identity\UnsubscribeTokenService;
use OCA\Integriq\Outbound\MessageRecorder;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests who may be written to, and what can still be taken back.
 *
 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md
 */
class OptOutAndHoldTest extends TestCase {

	/**
	 * The OR object service double.
	 *
	 * @var ORObjectService|MockObject
	 */
	private $objectService;

	/**
	 * Everything the double holds, by uuid.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $records = [];

	/**
	 * How many objects the double has created.
	 *
	 * @var int
	 */
	private int $created = 0;

	/**
	 * The app config double.
	 *
	 * @var IAppConfig|MockObject
	 */
	private $appConfig;

	/**
	 * What the app config answers, by key.
	 *
	 * @var array<string,string>
	 */
	private array $config = [];

	/**
	 * Set up doubles that behave like storage and configuration.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->records = [];
		$this->created = 0;
		$this->config = [];

		$this->objectService = ObjectServiceMockBuilder::make($this);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, string $register = '', string $schema = '', ?string $uuid = null) {
				if ($uuid === null || $uuid === '') {
					$this->created++;
					$uuid = 'object-' . $this->created;
				}

				$this->records[$uuid] = $object;
				return ObjectServiceMockBuilder::objectEntity($this, $object, $uuid);
			}
		);
		$this->objectService->method('find')->willReturnCallback(
			function (string $id) {
				return ObjectServiceMockBuilder::objectEntity($this, ($this->records[$id] ?? []), $id);
			}
		);
		$this->objectService->method('findAll')->willReturnCallback(
			function (): array {
				$rows = [];
				foreach ($this->records as $uuid => $record) {
					$rows[] = ObjectServiceMockBuilder::objectEntity($this, $record, $uuid);
				}

				return ['results' => $rows, 'total' => count($rows)];
			}
		);

		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = ''): string {
				return ($this->config[$key] ?? $default);
			}
		);
		$this->appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);

	}//end setUp()

	/**
	 * An ordinary update to an opted-out address is suppressed, with a reason.
	 *
	 * @return void
	 */
	public function testAnOptedOutAddressIsNotWrittenTo(): void {
		$registry = $this->registry();
		$registry->add('jan@example.org', OptOutRegistry::SCOPE_INSTANCE, null, 'administrator');

		$decision = $registry->decide('jan@example.org', 'status-update');

		$this->assertFalse($decision['send']);
		$this->assertFalse($decision['overridden']);
		$this->assertStringContainsString('opted out', $decision['reason']);

	}//end testAnOptedOutAddressIsNotWrittenTo()

	/**
	 * A besluit is still delivered, and the override is reported so it can be
	 * recorded and shown afterwards.
	 *
	 * @return void
	 */
	public function testABesluitIsStillDelivered(): void {
		$registry = $this->registry();
		$registry->add('jan@example.org', OptOutRegistry::SCOPE_INSTANCE);

		$decision = $registry->decide('jan@example.org', 'besluit');

		$this->assertTrue($decision['send']);
		$this->assertTrue($decision['overridden']);
		$this->assertStringContainsString('besluit', $decision['reason']);

	}//end testABesluitIsStillDelivered()

	/**
	 * A case opt-out stops that case and leaves the rest alone.
	 *
	 * @return void
	 */
	public function testACaseOptOutStopsOnlyThatCase(): void {
		$registry = $this->registry();
		$registry->add('jan@example.org', OptOutRegistry::SCOPE_CASE, 'zaak/1');

		$this->assertFalse($registry->decide('jan@example.org', 'status-update', 'zaak/1')['send']);
		$this->assertTrue($registry->decide('jan@example.org', 'status-update', 'zaak/2')['send']);

	}//end testACaseOptOutStopsOnlyThatCase()

	/**
	 * An instance may name its own protected categories.
	 *
	 * @return void
	 */
	public function testAnInstanceCanDeclareItsOwnProtectedCategories(): void {
		$this->config[OptOutRegistry::CONFIG_PROTECTED] = json_encode(['aanslag']);
		$registry = $this->registry();
		$registry->add('jan@example.org', OptOutRegistry::SCOPE_INSTANCE);

		$this->assertTrue($registry->decide('jan@example.org', 'aanslag')['overridden']);
		$this->assertFalse($registry->decide('jan@example.org', 'besluit')['send']);

	}//end testAnInstanceCanDeclareItsOwnProtectedCategories()

	/**
	 * Following the link stops that case's updates, with no account made.
	 *
	 * @return void
	 */
	public function testTheUnsubscribeLinkStopsOneCase(): void {
		$registry = $this->registry();
		$tokens = $this->tokens($registry);

		$token = $tokens->mint('jan@example.org', 'zaak/1');
		$claim = $tokens->verify($token);

		$this->assertSame('jan@example.org', $claim['address']);
		$this->assertSame('zaak/1', $claim['caseRef']);

		$registry->add($claim['address'], OptOutRegistry::SCOPE_CASE, $claim['caseRef']);
		$this->assertFalse($registry->decide('jan@example.org', 'status-update', 'zaak/1')['send']);

	}//end testTheUnsubscribeLinkStopsOneCase()

	/**
	 * A tampered token is not read at all.
	 *
	 * @return void
	 */
	public function testATamperedTokenDoesNotVerify(): void {
		$tokens = $this->tokens($this->registry());
		$token = $tokens->mint('jan@example.org', 'zaak/1');

		[$payload] = explode('.', $token);

		$this->assertNull($tokens->verify($payload . '.' . str_repeat('0', 64)));
		$this->assertNull($tokens->verify('rubbish'));

	}//end testATamperedTokenDoesNotVerify()

	/**
	 * A statutory notice carries no link at all, rather than a link that
	 * refuses: nobody is told they can stop something they cannot.
	 *
	 * @return void
	 */
	public function testAStatutoryMessageCarriesNoUnsubscribeLink(): void {
		$registry = $this->registry();
		$composer = new MessageComposer($this->tokens($registry));

		$besluit = $composer->compose(
			[],
			'Hierbij ontvangt u het besluit.',
			[],
			['recipient' => 'jan@example.org', 'caseRef' => 'zaak/1', 'category' => 'besluit']
		);
		$update = $composer->compose(
			[],
			'Uw zaak is bijgewerkt.',
			[],
			['recipient' => 'jan@example.org', 'caseRef' => 'zaak/1', 'category' => 'status-update']
		);

		$this->assertNull($besluit['unsubscribeLink']);
		$this->assertStringNotContainsString('unsubscribe', $besluit['body']);
		$this->assertNotNull($update['unsubscribeLink']);
		$this->assertStringContainsString('unsubscribe', $update['body']);

	}//end testAStatutoryMessageCarriesNoUnsubscribeLink()

	/**
	 * A wrong letter is pulled back inside the window, and the withdrawal is
	 * recorded with who did it.
	 *
	 * @return void
	 */
	public function testAMessageIsWithdrawnInsideItsWindow(): void {
		$clock = new DateTime('2026-09-18T10:00:00+02:00');
		$queue = $this->queue($clock);
		$uuid = $this->heldMessage($queue, 30);

		$clock->modify('+10 seconds');

		$this->assertTrue($queue->isWithdrawable($uuid));
		$queue->withdraw($uuid, 'behandelaar');

		$this->assertSame(HoldQueue::STATUS_WITHDRAWN, $this->records[$uuid]['hold']['status']);
		$this->assertSame('behandelaar', $this->records[$uuid]['hold']['withdrawnBy']);
		$this->assertSame(MessageRecorder::STATUS_FAILED, $this->records[$uuid]['status']);
		$this->assertSame('withdrawn', end($this->records[$uuid]['steps'])['step']);

	}//end testAMessageIsWithdrawnInsideItsWindow()

	/**
	 * After the window nothing is recalled, and the refusal says why.
	 *
	 * @return void
	 */
	public function testAfterTheWindowNothingIsRecalled(): void {
		$clock = new DateTime('2026-09-18T10:00:00+02:00');
		$queue = $this->queue($clock);
		$uuid = $this->heldMessage($queue, 30);

		$clock->modify('+40 seconds');

		$this->assertFalse($queue->isWithdrawable($uuid));
		$this->expectException(RuntimeException::class);
		$queue->withdraw($uuid, 'behandelaar');

	}//end testAfterTheWindowNothingIsRecalled()

	/**
	 * A zero window, which is the default, sends at once and withdraws nothing.
	 *
	 * @return void
	 */
	public function testAZeroWindowIsDueImmediately(): void {
		$clock = new DateTime('2026-09-18T10:00:00+02:00');
		$queue = $this->queue($clock);
		$uuid = $this->heldMessage($queue, 0);

		$this->assertSame(HoldQueue::STATUS_DUE, $this->records[$uuid]['hold']['status']);
		$this->assertFalse($queue->isWithdrawable($uuid));

	}//end testAZeroWindowIsDueImmediately()

	/**
	 * A held message becomes due once its window has passed, and not before.
	 *
	 * @return void
	 */
	public function testAHeldMessageIsReleasedWhenTheWindowPasses(): void {
		$clock = new DateTime('2026-09-18T10:00:00+02:00');
		$queue = $this->queue($clock);
		$uuid = $this->heldMessage($queue, 30);

		$this->assertFalse($queue->release($uuid));

		$clock->modify('+31 seconds');

		$this->assertTrue($queue->release($uuid));
		$this->assertSame(HoldQueue::STATUS_DUE, $this->records[$uuid]['hold']['status']);

	}//end testAHeldMessageIsReleasedWhenTheWindowPasses()

	/**
	 * A reply to a no-reply address is diverted to a mailbox somebody reads,
	 * and the diversion is recorded.
	 *
	 * @return void
	 */
	public function testAReplyToANoReplyAddressIsDiverted(): void {
		$handler = new NoReplyHandler($this->objectService);

		$result = $handler->handle(
			[
				'address' => 'noreply@gemeente.nl',
				'noReply' => ['enabled' => true, 'mode' => NoReplyHandler::MODE_DIVERT, 'divertTo' => 'kcc@gemeente.nl'],
			],
			['from' => 'jan@example.org', 'text' => 'Ik heb nog een vraag.', 'messageId' => 'WA-1']
		);

		$this->assertSame(NoReplyHandler::MODE_DIVERT, $result['outcome']);
		$this->assertSame('kcc@gemeente.nl', $result['target']);
		$this->assertCount(1, $this->records);
		$this->assertStringContainsString('kcc@gemeente.nl', reset($this->records)['reason']);

	}//end testAReplyToANoReplyAddressIsDiverted()

	/**
	 * A refusal tells the sender where to write instead.
	 *
	 * @return void
	 */
	public function testARefusalSaysWhereToWrite(): void {
		$handler = new NoReplyHandler($this->objectService);

		$result = $handler->handle(
			[
				'address' => 'noreply@gemeente.nl',
				'noReply' => [
					'enabled' => true,
					'mode' => NoReplyHandler::MODE_REFUSE,
					'writeInstead' => 'info@gemeente.nl',
				],
			],
			['from' => 'jan@example.org', 'text' => 'Ik heb nog een vraag.']
		);

		$this->assertSame(NoReplyHandler::MODE_REFUSE, $result['outcome']);
		$this->assertStringContainsString('info@gemeente.nl', $result['notice']);
		$this->assertCount(1, $this->records);

	}//end testARefusalSaysWhereToWrite()

	/**
	 * Configured to divert with nowhere to divert to, the reply is refused
	 * rather than dropped, which is the one outcome this must never have.
	 *
	 * @return void
	 */
	public function testADiversionWithNoMailboxRefusesRatherThanDrops(): void {
		$handler = new NoReplyHandler($this->objectService);

		$result = $handler->handle(
			['address' => 'noreply@gemeente.nl', 'noReply' => ['enabled' => true, 'mode' => 'divert']],
			['from' => 'jan@example.org', 'text' => 'Hallo']
		);

		$this->assertSame(NoReplyHandler::MODE_REFUSE, $result['outcome']);
		$this->assertCount(1, $this->records, 'the message is recorded either way, never dropped');

	}//end testADiversionWithNoMailboxRefusesRatherThanDrops()

	/**
	 * An identity that takes replies is left alone.
	 *
	 * @return void
	 */
	public function testAnOrdinaryIdentityAcceptsReplies(): void {
		$handler = new NoReplyHandler($this->objectService);

		$result = $handler->handle(['address' => 'zaken@gemeente.nl'], ['from' => 'jan@example.org']);

		$this->assertSame('accepted', $result['outcome']);
		$this->assertSame([], $this->records);

	}//end testAnOrdinaryIdentityAcceptsReplies()

	/**
	 * An opt-out registry over the doubles.
	 *
	 * @return OptOutRegistry The registry.
	 */
	private function registry(): OptOutRegistry {
		return new OptOutRegistry($this->objectService, $this->appConfig);

	}//end registry()

	/**
	 * A token service over the doubles.
	 *
	 * @param OptOutRegistry $registry The registry it asks about protected categories.
	 *
	 * @return UnsubscribeTokenService The service.
	 */
	private function tokens(OptOutRegistry $registry): UnsubscribeTokenService {
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn('test-secret-0123456789');

		return new UnsubscribeTokenService($this->appConfig, $random, $registry);

	}//end tokens()

	/**
	 * A hold queue on a clock the test moves.
	 *
	 * @param DateTime $clock The clock.
	 *
	 * @return HoldQueue The queue.
	 */
	private function queue(DateTime $clock): HoldQueue {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(
			static fn (): DateTime => clone $clock
		);

		return new HoldQueue(
			$this->objectService,
			new MessageRecorder($this->objectService, new BodyRedactor(), new ChannelReportingCapabilities()),
			$time,
		);

	}//end queue()

	/**
	 * A recorded message put on hold for a window.
	 *
	 * @param HoldQueue $queue The queue.
	 * @param int $window The hold window in seconds.
	 *
	 * @return string The message uuid.
	 */
	private function heldMessage(HoldQueue $queue, int $window): string {
		$recorder = new MessageRecorder(
			$this->objectService,
			new BodyRedactor(),
			new ChannelReportingCapabilities()
		);
		$entity = $recorder->start(
			'zaak/1',
			'mail',
			'Besluit',
			'Hierbij het besluit.',
			[['address' => 'jan@example.org', 'accountId' => 'jan']],
		);

		$uuid = (string)$entity->getUuid();
		$queue->hold($uuid, ['holdWindowSeconds' => $window]);

		return $uuid;

	}//end heldMessage()

}//end class

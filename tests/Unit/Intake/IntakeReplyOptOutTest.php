<?php

/**
 * Unit tests for the intake reply asking the opt-out list as a reply.
 *
 * Through the real IntakeReplyService, the real MessagingChannelAdapter (in
 * its mock mode, so no gateway is called), and the real opt-out services over
 * in-memory tables.
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
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-a-direct-reply-to-a-citizen-s-message-passes-an-opt-out-req-ooa-009
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Intake;

use OCA\Integriq\Intake\Adapter\MessagingChannelAdapter;
use OCA\Integriq\Intake\InboundMessage;
use OCA\Integriq\Intake\IntakeChannelRegistry;
use OCA\Integriq\Intake\IntakeChannelSourceResolver;
use OCA\Integriq\Intake\IntakeReplyService;
use OCA\Integriq\Intake\ReplyResult;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\Integriq\Tests\Helpers\OptOutFixture;
use OCP\Http\Client\IClientService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A reply to an opted-out citizen's own message is sent, without a link.
 */
class IntakeReplyOptOutTest extends TestCase {

	/**
	 * The texts the adapter was asked to send.
	 *
	 * @var list<string>
	 */
	public array $sentTexts = [];

	/**
	 * The last intake_message payload saved.
	 *
	 * @var array<string,mixed>
	 */
	private array $saved = [];

	/**
	 * An opted-out sender still gets the answer to their own question.
	 *
	 * @return void
	 */
	public function testAnOptedOutCitizenGetsTheAnswerToTheirOwnQuestion(): void {
		$fx = new OptOutFixture($this, $this->createMock(IDBConnection::class));
		$fx->registry()->add('+31612345678');

		$result = $this->service(fx: $fx)->reply('intake-uuid', 'Uw melding is in behandeling.');

		$this->assertSame(ReplyResult::STATUS_SENT, $result->getStatus());
		$this->assertSame(['Uw melding is in behandeling.'], $this->sentTexts, 'sent, with no unsubscribe line');
		$this->assertSame([], $fx->log->ofKind('suppressed'));
		$asked = $fx->log->ofKind('allowed-count');
		$this->assertCount(1, $asked, 'the reply was asked, not skipped');
		$this->assertSame('reply', $asked[0]->getCategory());
		$this->assertSame('intake-uuid', $asked[0]->getCorrelationId());
		$this->assertSame('', $this->saved['replies'][0]['detail'], 'the schema types detail as a string, so never null');

	}//end testAnOptedOutCitizenGetsTheAnswerToTheirOwnQuestion()

	/**
	 * When the list cannot be read the reply is still exempt from the opt-out,
	 * but a reply is not on the floor: it is refused as authority-unavailable.
	 *
	 * @return void
	 */
	public function testAnUnreadableListRefusesTheReply(): void {
		$fx = new OptOutFixture($this, $this->createMock(IDBConnection::class));
		$fx->table->failReads = true;
		$fx->log = new class($this->createMock(IDBConnection::class)) extends \OCA\Integriq\Tests\Helpers\InMemoryOptOutLogMapper {

			/**
			 * Throw as a broken table does.
			 *
			 * @param \OCA\Integriq\Db\OptOutLogEntry $entry The row.
			 *
			 * @return \OCA\Integriq\Db\OptOutLogEntry Never.
			 */
			public function append(\OCA\Integriq\Db\OptOutLogEntry $entry): \OCA\Integriq\Db\OptOutLogEntry {
				throw new \RuntimeException('the log cannot be written');

			}//end append()
		};

		$result = $this->service(fx: $fx)->reply('intake-uuid', 'Antwoord');

		$this->assertSame(ReplyResult::STATUS_FAILED, $result->getStatus());
		$this->assertStringStartsWith('authority-unavailable', (string)$result->getDetail());
		$this->assertSame([], $this->sentTexts);

	}//end testAnUnreadableListRefusesTheReply()

	/**
	 * The reply service over a stored messaging message and a spying adapter.
	 *
	 * @param OptOutFixture $fx The opt-out services.
	 *
	 * @return IntakeReplyService The service.
	 */
	private function service(OptOutFixture $fx): IntakeReplyService {
		$objectService = ObjectServiceMockBuilder::make($this);
		$objectService->method('find')->willReturn(
			ObjectServiceMockBuilder::objectEntity(
				$this,
				['channelId' => 'messaging', 'externalId' => 'wa-1', 'correspondent' => ['id' => '0612345678', 'phone' => '0612345678'], 'text' => 'Wanneer?', 'status' => 'routed'],
				'intake-uuid'
			)
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object) {
				$this->saved = $object;
				return ObjectServiceMockBuilder::objectEntity($this, $object, 'intake-uuid');
			}
		);

		$resolver = $this->getMockBuilder(IntakeChannelSourceResolver::class)
			->disableOriginalConstructor()
			->onlyMethods(['sourceFor', 'configurationFor'])
			->getMock();
		$resolver->method('configurationFor')->willReturn(['mock' => true]);
		$test = $this;
		$adapter = new class($resolver, $this->createMock(IClientService::class), $test) extends MessagingChannelAdapter {

			/**
			 * Constructor.
			 *
			 * @param IntakeChannelSourceResolver $resolver The source resolver.
			 * @param IClientService $clients The http clients.
			 * @param IntakeReplyOptOutTest $spy Where the sent texts go.
			 */
			public function __construct(IntakeChannelSourceResolver $resolver, IClientService $clients, private readonly IntakeReplyOptOutTest $spy) {
				parent::__construct($resolver, $clients);

			}//end __construct()

			/**
			 * Keep the text, then reply as the real adapter does.
			 *
			 * @param InboundMessage $message The message.
			 * @param string $text The text.
			 *
			 * @return ReplyResult The result.
			 */
			public function reply(InboundMessage $message, string $text): ReplyResult {
				$this->spy->sentTexts[] = $text;

				return parent::reply($message, $text);

			}//end reply()
		};

		return new IntakeReplyService(
			$objectService,
			new IntakeChannelRegistry(new NullLogger(), [$adapter]),
			$fx->gate()
		);

	}//end service()

}//end class

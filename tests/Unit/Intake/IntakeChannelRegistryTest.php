<?php

/**
 * Unit tests for the intake channel registry and the adapters it holds.
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

use OCA\Integriq\Exception\IntakeChannelException;
use OCA\Integriq\Intake\Adapter\FormSubmissionAdapter;
use OCA\Integriq\Intake\Adapter\MessagingChannelAdapter;
use OCA\Integriq\Intake\Adapter\PublicSpaceReportAdapter;
use OCA\Integriq\Intake\IntakeChannelRegistry;
use OCA\Integriq\Intake\IntakeChannelSourceResolver;
use OCA\Integriq\Intake\ReplyResult;
use OCP\Http\Client\IClientService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests discovery, the collision policy and what each adapter normalises.
 *
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-channel-is-a-declared-adapter-behind-one-contract-req-ic-001
 */
class IntakeChannelRegistryTest extends TestCase {

	/**
	 * The registry under test, holding the three shipped adapters.
	 *
	 * @var IntakeChannelRegistry
	 */
	private IntakeChannelRegistry $registry;

	/**
	 * Set up the registry.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$resolver = $this->getMockBuilder(IntakeChannelSourceResolver::class)
			->disableOriginalConstructor()
			->onlyMethods(['sourceFor', 'configurationFor'])
			->getMock();
		$resolver->method('configurationFor')->willReturn(['mock' => true]);

		$this->registry = new IntakeChannelRegistry(
			$this->createMock(LoggerInterface::class),
			[
				new FormSubmissionAdapter(),
				new MessagingChannelAdapter($resolver, $this->createMock(IClientService::class)),
				new PublicSpaceReportAdapter(),
			]
		);

	}//end setUp()

	/**
	 * A channel arrives as an adapter, and the registry hands it back by id.
	 *
	 * @return void
	 */
	public function testAChannelIsFoundByItsId(): void {
		$this->assertSame(
			['form-submission', 'messaging', 'public-space-report'],
			$this->registry->getChannelIds()
		);
		$this->assertSame('messaging', $this->registry->get('messaging')->getChannelId());

	}//end testAChannelIsFoundByItsId()

	/**
	 * An unknown channel id fails naming itself, and nothing is created.
	 *
	 * @return void
	 */
	public function testAnUnknownChannelIdFailsLoudly(): void {
		$this->expectException(IntakeChannelException::class);
		$this->expectExceptionMessage('telegram');

		$this->registry->get('telegram');

	}//end testAnUnknownChannelIdFailsLoudly()

	/**
	 * A second adapter claiming a taken id is refused: the channel keeps the
	 * behaviour it had rather than quietly gaining another.
	 *
	 * @return void
	 */
	public function testACollisionKeepsTheFirstAdapter(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$registry = new IntakeChannelRegistry($logger, [new FormSubmissionAdapter()]);
		$first = $registry->get('form-submission');

		$this->assertFalse($registry->register(new FormSubmissionAdapter()));
		$this->assertSame($first, $registry->get('form-submission'));

	}//end testACollisionKeepsTheFirstAdapter()

	/**
	 * describe() says what a channel can do, so the reply path learns it
	 * before send time rather than at it.
	 *
	 * @return void
	 */
	public function testDescribeSaysWhetherAChannelCanReply(): void {
		$described = [];
		foreach ($this->registry->describeAll() as $channel) {
			$described[$channel['channelId']] = $channel;
		}

		$this->assertTrue($described['messaging']['canReply']);
		$this->assertFalse($described['public-space-report']['canReply']);
		$this->assertTrue($described['public-space-report']['supportsLocation']);
		$this->assertFalse($described['form-submission']['supportsLocation']);

	}//end testDescribeSaysWhetherAChannelCanReply()

	/**
	 * A report arrives with its coordinates and its photos, as files.
	 *
	 * @return void
	 */
	public function testAReportCarriesItsLocationAndItsMedia(): void {
		$message = $this->registry->get('public-space-report')->receive(
			[
				'id' => 'MOR-1',
				'reporter' => ['name' => 'Jan Burger', 'email' => 'jan@example.org'],
				'description' => 'De lantaarnpaal brandt niet.',
				'category' => 'verlichting',
				'location' => ['latitude' => 52.0907, 'longitude' => 5.1214, 'address' => 'Domplein 1'],
				'photos' => [
					['name' => 'paal.jpg', 'mime' => 'image/jpeg', 'contentBase64' => base64_encode('foto-een')],
					['name' => 'straat.jpg', 'mime' => 'image/jpeg', 'contentBase64' => base64_encode('foto-twee')],
				],
				'reportedAt' => '2026-09-15T10:00:00+02:00',
			]
		);

		$this->assertSame('MOR-1', $message->getExternalId());
		$this->assertSame(52.0907, $message->getLocation()['latitude']);
		$this->assertCount(2, $message->getMedia());
		$this->assertSame('foto-een', $message->getMedia()[0]['content']);
		$this->assertSame('verlichting', $message->getFields()['category']);

	}//end testAReportCarriesItsLocationAndItsMedia()

	/**
	 * A report without coordinates gets none: an invented one looks measured.
	 *
	 * @return void
	 */
	public function testAReportWithoutCoordinatesGetsNoLocation(): void {
		$message = $this->registry->get('public-space-report')->receive(
			[
				'id' => 'MOR-2',
				'description' => 'Losliggende stoeptegel.',
				'location' => ['address' => 'Ergens in de stad'],
			]
		);

		$this->assertNull($message->getLocation());

	}//end testAReportWithoutCoordinatesGetsNoLocation()

	/**
	 * A report with no id is refused, because the same report could then
	 * arrive twice and nobody would know.
	 *
	 * @return void
	 */
	public function testAReportWithoutAnIdIsRefused(): void {
		$this->expectException(IntakeChannelException::class);

		$this->registry->get('public-space-report')->receive(['description' => 'Geen id']);

	}//end testAReportWithoutAnIdIsRefused()

	/**
	 * A submission's own data becomes the fields a mapping names.
	 *
	 * @return void
	 */
	public function testASubmissionExposesItsDataAsFields(): void {
		$message = $this->registry->get('form-submission')->receive(
			[
				'submissionId' => 'SUB-1',
				'formId' => 'bezwaar',
				'submitter' => ['name' => 'Jan Burger', 'email' => 'jan@example.org'],
				'data' => ['onderwerp' => 'Bezwaar', 'toelichting' => 'Ik ben het er niet mee eens.'],
				'submittedAt' => '2026-09-15T09:00:00+02:00',
			]
		);

		$this->assertSame('Bezwaar', $message->getFields()['onderwerp']);
		$this->assertSame('bezwaar', $message->getFields()['formId']);
		$this->assertSame('jan@example.org', $message->getCorrespondent()['address']);

	}//end testASubmissionExposesItsDataAsFields()

	/**
	 * A channel that cannot reply says so, and sends nothing anywhere else.
	 *
	 * @return void
	 */
	public function testAChannelThatCannotReplySaysSo(): void {
		$message = $this->registry->get('form-submission')->receive(
			['submissionId' => 'SUB-2', 'formId' => 'bezwaar']
		);

		$result = $this->registry->get('form-submission')->reply($message, 'Dank voor uw melding.');

		$this->assertSame(ReplyResult::STATUS_UNSUPPORTED, $result->getStatus());
		$this->assertFalse($result->isSent());

	}//end testAChannelThatCannotReplySaysSo()

	/**
	 * A messaging reply leaves over messaging, to the handle that wrote in.
	 *
	 * @return void
	 */
	public function testAMessagingReplyLeavesOverMessaging(): void {
		$adapter = $this->registry->get('messaging');
		$message = $adapter->receive(
			[
				'messageId' => 'WA-1',
				'from' => ['phone' => '+31612345678', 'name' => 'Jan'],
				'text' => 'Wanneer komt de reiniging?',
			]
		);

		$result = $adapter->reply($message, 'Morgen tussen 9 en 12.');

		$this->assertSame(ReplyResult::STATUS_SENT, $result->getStatus());
		$this->assertSame('messaging', $result->getChannelId());

	}//end testAMessagingReplyLeavesOverMessaging()

	/**
	 * A messaging payload without a sender handle is refused: a reply would
	 * have nowhere to go, and that is the whole point of this channel.
	 *
	 * @return void
	 */
	public function testAMessagingPayloadWithoutAHandleIsRefused(): void {
		$this->expectException(IntakeChannelException::class);

		$this->registry->get('messaging')->receive(['messageId' => 'WA-2', 'text' => 'Hallo']);

	}//end testAMessagingPayloadWithoutAHandleIsRefused()

}//end class

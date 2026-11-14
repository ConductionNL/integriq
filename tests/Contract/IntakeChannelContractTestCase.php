<?php

/**
 * IntakeChannelContractTestCase
 *
 * The rules every intake channel adapter keeps, whoever ships it. An extension app extends this case, returns its adapter and a payload its channel delivers, and runs the rules in its own test suite.
 *
 * @category  Tests
 * @package   OCA\Integriq\Tests\Contract
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-a-developer-builds-an-extension-from-a-guide-a-skeleton-and-contract-tests-req-cxk-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Contract;

use OCA\Integriq\Exception\IntakeChannelException;
use OCA\Integriq\Intake\InboundMessage;
use OCA\Integriq\Intake\IntakeChannelAdapterInterface;
use OCA\Integriq\Intake\ReplyResult;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for an IntakeChannelAdapterInterface implementation.
 */
abstract class IntakeChannelContractTestCase extends TestCase {

	/**
	 * A channel id: lower-case letters, digits and hyphens, starting with a letter.
	 */
	public const CHANNEL_ID_PATTERN = '/^[a-z][a-z0-9-]{1,63}$/';

	/**
	 * The adapter under test.
	 *
	 * @return IntakeChannelAdapterInterface
	 */
	abstract protected function createAdapter(): IntakeChannelAdapterInterface;

	/**
	 * A payload the channel really delivers, which the adapter must read.
	 *
	 * @return array<string,mixed>
	 */
	abstract protected function validPayload(): array;

	/**
	 * A payload the adapter must refuse. Override when an empty payload is a valid one.
	 *
	 * @return array<string,mixed>
	 */
	protected function unreadablePayload(): array {
		return [];
	}//end unreadablePayload()

	/**
	 * The channel id is a stable slug.
	 *
	 * @return void
	 */
	public function testTheChannelIdIsAStableSlug(): void {
		$adapter = $this->createAdapter();
		$this->assertMatchesRegularExpression(self::CHANNEL_ID_PATTERN, $adapter->getChannelId());
		$this->assertSame($adapter->getChannelId(), $adapter->getChannelId());
	}//end testTheChannelIdIsAStableSlug()

	/**
	 * describe() names the same channel and gives it a label.
	 *
	 * @return void
	 */
	public function testTheCapabilitiesDescribeThisChannel(): void {
		$adapter = $this->createAdapter();
		$capabilities = $adapter->describe();
		$this->assertSame($adapter->getChannelId(), $capabilities->getChannelId());
		$this->assertNotSame('', trim($capabilities->getLabel()));
		$this->assertContainsOnly('string', $capabilities->getFields());
	}//end testTheCapabilitiesDescribeThisChannel()

	/**
	 * A delivered payload becomes a message of this channel, keeping what was sent.
	 *
	 * @return void
	 */
	public function testAValidPayloadBecomesAMessageOfThisChannel(): void {
		$adapter = $this->createAdapter();
		$message = $adapter->receive($this->validPayload());
		$this->assertInstanceOf(InboundMessage::class, $message);
		$this->assertSame($adapter->getChannelId(), $message->getChannelId());
		$this->assertNotSame('', trim($message->getExternalId()), 'A message needs the id the channel gave it, so a repeat delivery is recognised.');
		$this->assertSame($this->validPayload(), $message->getRawPayload());
	}//end testAValidPayloadBecomesAMessageOfThisChannel()

	/**
	 * An unreadable payload is refused with an IntakeChannelException, never half-read.
	 *
	 * @return void
	 */
	public function testAnUnreadablePayloadIsRefused(): void {
		$this->expectException(IntakeChannelException::class);
		$this->createAdapter()->receive($this->unreadablePayload());
	}//end testAnUnreadablePayloadIsRefused()

	/**
	 * A reply answers for this channel; a channel that cannot reply says so.
	 *
	 * @return void
	 */
	public function testAReplyAnswersForThisChannel(): void {
		$adapter = $this->createAdapter();
		$result = $adapter->reply($adapter->receive($this->validPayload()), 'Dank voor uw bericht.');
		$this->assertInstanceOf(ReplyResult::class, $result);
		$this->assertSame($adapter->getChannelId(), $result->getChannelId());
		if ($adapter->describe()->canReply() === false) {
			$this->assertSame(ReplyResult::STATUS_UNSUPPORTED, $result->getStatus(), 'A channel that cannot reply must not pretend it did.');
		}
	}//end testAReplyAnswersForThisChannel()
}//end class

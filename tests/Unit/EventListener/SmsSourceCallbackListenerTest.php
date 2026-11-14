<?php

/**
 * Unit tests for SmsSourceCallbackListener.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\EventListener;

use OCA\Integriq\EventListener\SmsSourceCallbackListener;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * An SMS source's callback token and signing key on OpenRegister's save path.
 *
 * @covers \OCA\Integriq\EventListener\SmsSourceCallbackListener
 *
 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-vendor-delivery-status-reaches-the-stored-message-req-smsp-004
 */
class SmsSourceCallbackListenerTest extends TestCase {

	private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

	/**
	 * The listener; the mappers resolve to the given schema slug in the integriq register.
	 *
	 * @param string $schemaSlug The schema slug.
	 *
	 * @return SmsSourceCallbackListener
	 */
	private function listener(string $schemaSlug = 'source'): SmsSourceCallbackListener {
		$registerMapper = $this->createMock(RegisterMapper::class);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$registerMapper->method('find')->willReturn($this->slugObject(slug: 'integriq'));
		$schemaMapper->method('find')->willReturn($this->slugObject(slug: $schemaSlug));
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(static fn (string $plain): string => 'enc(' . $plain . ')');

		return new SmsSourceCallbackListener(registerMapper: $registerMapper, schemaMapper: $schemaMapper, crypto: $crypto, logger: new NullLogger());

	}//end listener()

	/**
	 * A slug-bearing value standing in for a Register or Schema.
	 *
	 * @param string $slug The slug.
	 *
	 * @return object
	 */
	private function slugObject(string $slug): object {
		return new class($slug) {
			/**
			 * @param string $slug The slug.
			 */
			public function __construct(private string $slug) {
			}

			/**
			 * @return string
			 */
			public function getSlug(): string {
				return $this->slug;
			}
		};

	}//end slugObject()

	/**
	 * An object entity carrying the given data.
	 *
	 * @param array<string,mixed> $data The object data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($data);
		$entity->setRegister(1);
		$entity->setSchema(2);
		return $entity;

	}//end entity()

	/**
	 * A new SMS source gets a random token and its typed signing key encrypted.
	 *
	 * @return void
	 */
	public function testANewSmsSourceGetsATokenAndAnEncryptedKey(): void {
		$event = new ObjectCreatingEvent(
			$this->entity(['type' => 'sms', 'configuration' => ['provider' => 'twilio', 'webhookSigningKey' => ' auth-token ']])
		);

		$this->listener()->handle($event);

		$configuration = $event->getModifiedData()['configuration'];
		$this->assertMatchesRegularExpression('/^[0-9a-f]{48}$/', $configuration['statusCallbackToken']);
		$this->assertSame('enc(auth-token)', $configuration['encryptedWebhookSigningKey']);
		$this->assertArrayNotHasKey('webhookSigningKey', $configuration);
		$this->assertSame('twilio', $configuration['provider']);

		$other = new ObjectCreatingEvent($this->entity(['type' => 'sms', 'configuration' => ['provider' => 'twilio']]));
		$this->listener()->handle($other);
		$this->assertNotSame($configuration['statusCallbackToken'], $other->getModifiedData()['configuration']['statusCallbackToken']);

	}//end testANewSmsSourceGetsATokenAndAnEncryptedKey()

	/**
	 * An edit that leaves the token and key out keeps the stored ones; a well-formed token typed in is kept.
	 *
	 * @return void
	 */
	public function testAnEditKeepsTheStoredTokenAndKey(): void {
		$stored = ['provider' => 'twilio', 'statusCallbackToken' => self::TOKEN, 'encryptedWebhookSigningKey' => 'enc(old)'];

		$this->assertSame(
			['provider' => 'messagebird', 'statusCallbackToken' => self::TOKEN, 'encryptedWebhookSigningKey' => 'enc(old)'],
			$this->listener()->settled(configuration: ['provider' => 'messagebird', 'statusCallbackToken' => 'short'], stored: $stored)
		);
		$this->assertSame(
			['statusCallbackToken' => self::TOKEN . 'b', 'encryptedWebhookSigningKey' => 'enc(new)'],
			$this->listener()->settled(configuration: ['statusCallbackToken' => self::TOKEN . 'b', 'webhookSigningKey' => 'new'], stored: $stored)
		);

		$event = new ObjectUpdatingEvent(
			$this->entity(['type' => 'sms', 'configuration' => ['provider' => 'twilio', 'webhookSigningKey' => '']]),
			$this->entity(['type' => 'sms', 'configuration' => $stored])
		);
		$this->listener()->handle($event);
		$this->assertSame($stored, $event->getModifiedData()['configuration']);

	}//end testAnEditKeepsTheStoredTokenAndKey()

	/**
	 * An edit without a configuration, a source of another type and another schema are left alone.
	 *
	 * @return void
	 */
	public function testOtherSavesAreLeftAlone(): void {
		$withoutConfiguration = new ObjectUpdatingEvent($this->entity(['type' => 'sms', 'name' => 'x']), $this->entity(['type' => 'sms', 'configuration' => []]));
		$apiSource = new ObjectCreatingEvent($this->entity(['type' => 'api', 'configuration' => ['webhookSigningKey' => 'k']]));
		$otherSchema = new ObjectCreatingEvent($this->entity(['type' => 'sms', 'configuration' => []]));

		$this->listener()->handle($withoutConfiguration);
		$this->listener()->handle($apiSource);
		$this->listener(schemaSlug: 'endpoint')->handle($otherSchema);

		$this->assertSame([], $withoutConfiguration->getModifiedData());
		$this->assertSame([], $apiSource->getModifiedData());
		$this->assertSame([], $otherSchema->getModifiedData());

	}//end testOtherSavesAreLeftAlone()
}//end class

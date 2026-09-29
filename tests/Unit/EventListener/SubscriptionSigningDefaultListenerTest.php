<?php

/**
 * Tests for SubscriptionSigningDefaultListener.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/signed-outbound-webhooks/specs/webhook-signing/spec.md#requirement-a-push-subscription-is-signed-unless-somebody-says-otherwise-req-sow-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\EventListener;

use OCA\Integriq\EventListener\SubscriptionSigningDefaultListener;
use OCA\Integriq\Service\Subscriptions\SubscriptionSigningPolicy;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The signing default runs on OpenRegister's own create and update path.
 *
 * The events are the real OpenRegister classes (tests/stubs holds copies), so
 * a refusal is the stopPropagation() + setErrors() MagicMapper turns into a
 * HookStoppedException, and a default is the setModifiedData() it merges into
 * the object before the insert.
 */
class SubscriptionSigningDefaultListenerTest extends TestCase {

	/**
	 * Build the listener; the mappers resolve to the given slugs.
	 *
	 * @param string $schemaSlug The schema slug the object resolves to.
	 *
	 * @return SubscriptionSigningDefaultListener
	 */
	private function listener(string $schemaSlug = 'event_subscription'): SubscriptionSigningDefaultListener {
		$registerMapper = $this->createMock(RegisterMapper::class);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$registerMapper->method('find')->willReturn($this->slugObject('integriq'));
		$schemaMapper->method('find')->willReturn($this->slugObject($schemaSlug));

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('beheerder');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return new SubscriptionSigningDefaultListener(
			policy: new SubscriptionSigningPolicy(new WebhookSignatureService(new NullLogger())),
			registerMapper: $registerMapper,
			schemaMapper: $schemaMapper,
			userSession: $session,
			l10n: $l10n,
			logger: new NullLogger(),
		);

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
		$entity->setUuid('8f0c7a0e-4c1d-4b52-9d8e-2f3a1b6c7d80');
		$entity->setRegister(1);
		$entity->setSchema(2);
		$entity->setObject($data);

		return $entity;

	}//end entity()

	/**
	 * What OpenRegister stores: the object with the hook's data merged in.
	 *
	 * @param ObjectEntity $entity The entity.
	 * @param array<string,mixed> $modified The hook's modified data.
	 *
	 * @return array<string,mixed>
	 */
	private function stored(ObjectEntity $entity, array $modified): array {
		return array_merge($entity->getObject(), $modified);

	}//end stored()

	/**
	 * A new push subscription with no signing configuration gains a secret.
	 *
	 * @return void
	 */
	public function testANewPushSubscriptionIsStoredWithASecret(): void {
		$entity = $this->entity(['sink' => 'https://ontvanger.example.nl/hook', 'style' => 'push', 'types' => ['nl.example.zaak.created']]);
		$event = new ObjectCreatingEvent($entity);

		$this->listener()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$stored = $this->stored(entity: $entity, modified: $event->getModifiedData());
		$this->assertStringStartsWith('whsec_', (string)($stored['protocolSettings']['signingSecret'] ?? ''));
		$this->assertSame('signed', $stored['signingPosture']);
		$this->assertSame([], RegisterSchemaValidator::errors('event_subscription', $stored));

	}//end testANewPushSubscriptionIsStoredWithASecret()

	/**
	 * Unsigned without a reason is refused, naming the reason.
	 *
	 * @return void
	 */
	public function testUnsignedWithoutAReasonIsRefusedAtCreate(): void {
		$event = new ObjectCreatingEvent($this->entity(['sink' => 'https://ontvanger.example.nl/hook', 'style' => 'push', 'protocolSettings' => ['unsigned' => []]]));

		$this->listener()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertStringContainsString('needs a reason', (string)$event->getErrors()['message']);
		$this->assertSame(400, $event->getErrors()['status']);

	}//end testUnsignedWithoutAReasonIsRefusedAtCreate()

	/**
	 * Unsigned with a reason is stored with who and when, and no secret.
	 *
	 * @return void
	 */
	public function testUnsignedWithAReasonIsRecordedByName(): void {
		$entity = $this->entity(
			[
				'sink' => 'https://ontvanger.example.nl/hook',
				'style' => 'push',
				'protocolSettings' => ['unsigned' => ['reason' => 'receiver cannot verify HMAC yet']],
			]
		);
		$event = new ObjectCreatingEvent($entity);

		$this->listener()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$stored = $this->stored(entity: $entity, modified: $event->getModifiedData());
		$this->assertArrayNotHasKey('signingSecret', $stored['protocolSettings']);
		$this->assertSame('beheerder', $stored['protocolSettings']['unsigned']['setBy']);
		$this->assertNotSame('', $stored['protocolSettings']['unsigned']['setAt']);
		$this->assertSame('unsigned', $stored['signingPosture']);
		$this->assertSame('receiver cannot verify HMAC yet', $stored['unsignedReason']);
		$this->assertSame([], RegisterSchemaValidator::errors('event_subscription', $stored));

	}//end testUnsignedWithAReasonIsRecordedByName()

	/**
	 * A pull subscription and another schema's object are left alone.
	 *
	 * @return void
	 */
	public function testPullSubscriptionsAndOtherSchemasAreLeftAlone(): void {
		$pull = new ObjectCreatingEvent($this->entity(['style' => 'pull']));
		$this->listener()->handle($pull);
		$this->assertSame([], $pull->getModifiedData());

		$other = new ObjectCreatingEvent($this->entity(['style' => 'push', 'protocolSettings' => ['unsigned' => []]]));
		$this->listener(schemaSlug: 'endpoint')->handle($other);
		$this->assertFalse($other->isPropagationStopped());
		$this->assertSame([], $other->getModifiedData());

	}//end testPullSubscriptionsAndOtherSchemasAreLeftAlone()

	/**
	 * An update never generates a secret: an existing subscription stays as it was.
	 *
	 * @return void
	 */
	public function testAnExistingSubscriptionDoesNotGainASecretOnUpdate(): void {
		$old = $this->entity(['sink' => 'https://ontvanger.example.nl/hook', 'style' => 'push', 'protocolSettings' => ['headers' => ['X-Tenant' => 'a']]]);
		$new = $this->entity(['sink' => 'https://ontvanger.example.nl/hook2', 'style' => 'push', 'protocolSettings' => ['headers' => ['X-Tenant' => 'a']]]);
		$event = new ObjectUpdatingEvent($new, $old);

		$this->listener()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$stored = $this->stored(entity: $new, modified: $event->getModifiedData());
		$this->assertArrayNotHasKey('signingSecret', $stored['protocolSettings']);
		$this->assertSame(['X-Tenant' => 'a'], $stored['protocolSettings']['headers']);
		$this->assertSame('unsigned', $stored['signingPosture']);

	}//end testAnExistingSubscriptionDoesNotGainASecretOnUpdate()

	/**
	 * An edit that does not send protocolSettings keeps the stored ones whole.
	 *
	 * @return void
	 */
	public function testAnEditWithoutProtocolSettingsDoesNotTouchThem(): void {
		$old = $this->entity(['style' => 'push', 'protocolSettings' => ['signingSecret' => 'whsec_stored', 'headers' => ['X-A' => '1']]]);
		$new = $this->entity(['style' => 'push', 'sink' => 'https://ontvanger.example.nl/nieuw']);
		$event = new ObjectUpdatingEvent($new, $old);

		$this->listener()->handle($event);

		$this->assertArrayNotHasKey('protocolSettings', $event->getModifiedData());
		$this->assertSame('signed', $event->getModifiedData()['signingPosture']);

	}//end testAnEditWithoutProtocolSettingsDoesNotTouchThem()

	/**
	 * Setting unsigned on an update without a reason is refused.
	 *
	 * @return void
	 */
	public function testUnsignedWithoutAReasonIsRefusedAtUpdate(): void {
		$old = $this->entity(['style' => 'push', 'protocolSettings' => ['signingSecret' => 'whsec_stored']]);
		$new = $this->entity(['style' => 'push', 'protocolSettings' => ['unsigned' => ['reason' => '  ']]]);
		$event = new ObjectUpdatingEvent($new, $old);

		$this->listener()->handle($event);

		$this->assertTrue($event->isPropagationStopped());

	}//end testUnsignedWithoutAReasonIsRefusedAtUpdate()
}//end class

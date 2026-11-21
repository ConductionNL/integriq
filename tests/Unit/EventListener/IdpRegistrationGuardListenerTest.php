<?php

/**
 * Tests for IdpRegistrationGuardListener.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-yivi-is-a-provider-on-the-broker-reached-as-an-oidc-registration-req-yivi-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\EventListener;

use OCA\Integriq\EventListener\IdpRegistrationGuardListener;
use OCA\Integriq\EventListener\IdpRegistrationSaveCheck;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The refusal runs on OpenRegister's own create and update path, the one the
 * Authentication settings screen writes through. The events are the real
 * OpenRegister classes (tests/stubs holds copies).
 *
 * @covers \OCA\Integriq\EventListener\IdpRegistrationGuardListener
 * @covers \OCA\Integriq\EventListener\IdpRegistrationSaveCheck
 */
class IdpRegistrationGuardListenerTest extends TestCase {

	/**
	 * The listener; the mappers resolve to the given schema slug, or throw.
	 *
	 * @param string|null $schemaSlug The schema slug, or null for a schema that cannot be resolved.
	 *
	 * @return IdpRegistrationGuardListener
	 */
	private function listener(?string $schemaSlug = 'idpRegistration'): IdpRegistrationGuardListener {
		$registerMapper = $this->createMock(originalClassName: RegisterMapper::class);
		$schemaMapper = $this->createMock(originalClassName: SchemaMapper::class);
		$registerMapper->method('find')->willReturn($this->slugObject(slug: 'integriq'));
		if ($schemaSlug === null) {
			$schemaMapper->method('find')->willThrowException(new RuntimeException('gone'));
		} else {
			$schemaMapper->method('find')->willReturn($this->slugObject(slug: $schemaSlug));
		}

		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);

		return new IdpRegistrationGuardListener(
			check: new IdpRegistrationSaveCheck(
				schemaMapper: $schemaMapper,
				registerMapper: $registerMapper,
				l10n: $l10n,
				logger: new NullLogger(),
			),
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
	 * A registration entity.
	 *
	 * @param array<string, mixed> $data The registration.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('reg-1');
		$entity->setRegister(1);
		$entity->setSchema(2);
		$entity->setObject($data + ['organisation' => 'gemeente-x', 'issuer' => 'https://yivi-bridge.example']);

		return $entity;
	}//end entity()

	/**
	 * A Yivi registration over SAML is refused with 400, on create and on update.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-yivi-is-a-provider-on-the-broker-reached-as-an-oidc-registration-req-yivi-001
	 */
	public function testAYiviRegistrationOverSamlIsRefused(): void {
		$saml = $this->entity(data: ['provider' => 'yivi', 'kind' => 'saml-sp']);
		$events = [
			new ObjectCreatingEvent($saml),
			new ObjectUpdatingEvent($saml, $this->entity(data: ['provider' => 'yivi', 'kind' => 'oidc-rp'])),
		];

		foreach ($events as $event) {
			$this->listener()->handle(event: $event);

			$this->assertTrue(condition: $event->isPropagationStopped());
			$this->assertSame(expected: 'idp_registration_needs_oidc', actual: $event->getErrors()['code']);
			$this->assertSame(expected: 400, actual: $event->getErrors()['status']);
			$this->assertSame(
				expected: 'Yivi is reached through an OIDC bridge. Save this registration with the protocol OIDC Relying Party.',
				actual: $event->getErrors()['message']
			);
		}
	}//end testAYiviRegistrationOverSamlIsRefused()

	/**
	 * A Yivi OIDC registration, a SAML registration for another provider, another schema and an unresolvable one pass.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-yivi-is-a-provider-on-the-broker-reached-as-an-oidc-registration-req-yivi-001
	 */
	public function testEverythingElsePasses(): void {
		$cases = [
			[$this->listener(), new ObjectCreatingEvent($this->entity(data: ['provider' => 'yivi', 'kind' => 'oidc-rp']))],
			[$this->listener(), new ObjectCreatingEvent($this->entity(data: ['provider' => ' yivi ', 'kind' => ' oidc-rp ']))],
			[$this->listener(), new ObjectCreatingEvent($this->entity(data: ['provider' => 'digid', 'kind' => 'saml-sp']))],
			[$this->listener(schemaSlug: 'source'), new ObjectCreatingEvent($this->entity(data: ['provider' => 'yivi', 'kind' => 'saml-sp']))],
			[$this->listener(schemaSlug: null), new ObjectCreatingEvent($this->entity(data: ['provider' => 'yivi', 'kind' => 'saml-sp']))],
		];

		foreach ($cases as [$listener, $event]) {
			$listener->handle(event: $event);

			$this->assertFalse(condition: $event->isPropagationStopped());
			$this->assertSame(expected: [], actual: (array)$event->getErrors());
		}

		$other = new class extends Event {
		};
		$this->listener()->handle(event: $other);
		$this->assertFalse(condition: $other->isPropagationStopped());
	}//end testEverythingElsePasses()
}//end class

<?php

/**
 * Unit tests for SenderIdentityService's edge paths: an unknown identity id, the
 * broker being unavailable or returning nothing, the broker resolved through the
 * container with the identity's organisation, missing identity rows on the
 * signing path, a non-list identity lookup, and quoting-level defaults.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Outbound\Identity
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Outbound\Identity;

use OCA\Integriq\Outbound\Identity\SenderIdentityService;
use OCA\Integriq\Outbound\MessageRecorder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A service whose broker class counts as loadable (or not) by flag, while the
 * broker itself still comes from the real container seam.
 */
class BrokerFlagSenderIdentityService extends SenderIdentityService {

	/**
	 * Whether the broker class should count as loadable.
	 *
	 * @var bool
	 */
	public bool $brokerAvailable = true;

	/**
	 * Whether the broker class is loadable.
	 *
	 * @return bool The flag.
	 */
	protected function isBrokerClassAvailable(): bool {
		return $this->brokerAvailable;

	}//end isBrokerClassAvailable()
}//end class

/**
 * Tests for the edge paths of the sender identity service.
 *
 * @covers \OCA\Integriq\Outbound\Identity\SenderIdentityService
 */
class SenderIdentityServiceEdgeTest extends TestCase {

	/**
	 * OpenRegister object service mock.
	 *
	 * @var ORObjectService&MockObject
	 */
	private ORObjectService&MockObject $objectService;

	/**
	 * Container mock.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Fresh mocks per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ORObjectService::class);
		$this->container     = $this->createMock(ContainerInterface::class);
		$this->logger        = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @param bool $brokerAvailable Whether the broker class counts as loadable.
	 *
	 * @return BrokerFlagSenderIdentityService The service.
	 */
	private function service(bool $brokerAvailable = true): BrokerFlagSenderIdentityService {
		$service = new BrokerFlagSenderIdentityService(
			objectService: $this->objectService,
			container: $this->container,
			logger: $this->logger
		);
		$service->brokerAvailable = $brokerAvailable;

		return $service;

	}//end service()

	/**
	 * An identity row.
	 *
	 * @param array<string,mixed> $object       The row's object data.
	 * @param string              $uuid         The row's uuid.
	 * @param string|null         $organisation The owning organisation.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function identityRow(array $object, string $uuid = 'identity-1', ?string $organisation = null): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($object);
		if ($organisation !== null) {
			$entity->setOrganisation($organisation);
		}

		return $entity;

	}//end identityRow()

	/**
	 * An id that names no identity is refused with that id in the message.
	 *
	 * @return void
	 */
	public function testAnUnknownIdentityIdIsRefused(): void {
		$this->objectService->expects($this->once())
			->method('find')
			->with('ghost', MessageRecorder::REGISTER, 'sender_identity')
			->willThrowException(new DoesNotExistException('gone'));
		$this->objectService->expects($this->never())->method('findAll');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('No sender identity " ghost ".');

		$this->service()->resolve(identityId: ' ghost ');

	}//end testAnUnknownIdentityIdIsRefused()

	/**
	 * A found identity gets its defaults: an unknown quoting level becomes the
	 * last-message level and the hold window an integer.
	 *
	 * @return void
	 */
	public function testAResolvedIdentityGetsItsDefaults(): void {
		$this->objectService->method('find')->willReturn(
			$this->identityRow(['address' => 'team@example.org', 'quotingLevel' => 'everything', 'holdWindowSeconds' => '90'])
		);

		$resolved = $this->service()->resolve(identityId: 'identity-1');

		$this->assertSame(
			[
				'identity' => [
					'address'           => 'team@example.org',
					'quotingLevel'      => SenderIdentityService::QUOTING_LAST,
					'holdWindowSeconds' => 90,
				],
				'id'       => 'identity-1',
				'fallback' => false,
			],
			$resolved
		);

	}//end testAResolvedIdentityGetsItsDefaults()

	/**
	 * A non-list lookup result means no identities, so no default.
	 *
	 * @return void
	 */
	public function testANonListLookupHasNoIdentities(): void {
		$this->objectService->method('findAll')->willReturn(['results' => 'nope']);

		$this->assertSame([], $this->service()->all());
		$this->assertNull($this->service()->defaultIdentity());

	}//end testANonListLookupHasNoIdentities()

	/**
	 * An unloadable broker makes a referenced key unresolvable, logs it, and
	 * never reads the identity row.
	 *
	 * @return void
	 */
	public function testAnUnavailableBrokerIsUnresolvable(): void {
		$this->objectService->expects($this->never())->method('find');
		$this->logger->expects($this->once())
			->method('warning')
			->with('[SenderIdentity] the credential broker is unavailable, so identity-1 cannot sign');

		$this->assertSame(
			['key' => null, 'state' => SenderIdentityService::SIGNING_KEY_UNRESOLVABLE],
			$this->service(brokerAvailable: false)->signingMaterial(
				identity: ['smimePrivateKeyRef' => 'cred-1'],
				identityId: 'identity-1'
			)
		);

	}//end testAnUnavailableBrokerIsUnresolvable()

	/**
	 * A missing identity row on the brokered path is unresolvable and the
	 * broker is never asked.
	 *
	 * @return void
	 */
	public function testAMissingRowOnTheBrokeredPathIsUnresolvable(): void {
		$this->objectService->method('find')->willThrowException(new DoesNotExistException('gone'));
		$this->container->expects($this->never())->method('get');

		$this->assertSame(
			['key' => null, 'state' => SenderIdentityService::SIGNING_KEY_UNRESOLVABLE],
			$this->service()->signingMaterial(identity: ['smimePrivateKeyRef' => 'cred-1'], identityId: 'identity-1')
		);

	}//end testAMissingRowOnTheBrokeredPathIsUnresolvable()

	/**
	 * The broker comes from the container and is asked for the reference under
	 * the connector app id and the identity's organisation; its answer is trimmed.
	 *
	 * @return void
	 */
	public function testTheBrokerIsResolvedFromTheContainerWithTheOrganisation(): void {
		$this->objectService->expects($this->once())
			->method('find')
			->with('identity-1', MessageRecorder::REGISTER, 'sender_identity', false, false, false)
			->willReturn($this->identityRow(object: [], organisation: ' org-7 '));

		$broker = new class {
			/**
			 * Calls received.
			 *
			 * @var array<int,array<int,mixed>>
			 */
			public array $calls = [];

			/**
			 * Hand out key material.
			 *
			 * @return string The material.
			 */
			public function resolveInjectable($credentialId, $appId, $userId = null, $organisationId = null): string {
				$this->calls[] = [$credentialId, $appId, $userId, $organisationId];
				return "  KEY-MATERIAL \n";
			}
		};
		$this->container->expects($this->once())
			->method('get')
			->with('OCA\\OpenRegister\\Service\\Credential\\CredentialBrokerService')
			->willReturn($broker);

		$material = $this->service()->signingMaterial(
			identity: ['smimePrivateKeyRef' => ' cred-1 '],
			identityId: 'identity-1'
		);

		$this->assertSame(['key' => 'KEY-MATERIAL', 'state' => SenderIdentityService::SIGNING_KEY_AVAILABLE], $material);
		$this->assertSame([['cred-1', 'openconnector', null, 'org-7']], $broker->calls);

	}//end testTheBrokerIsResolvedFromTheContainerWithTheOrganisation()

	/**
	 * Blank broker material is unresolvable and logged.
	 *
	 * @return void
	 */
	public function testBlankBrokerMaterialIsUnresolvable(): void {
		$this->objectService->method('find')->willReturn($this->identityRow(object: []));
		$this->container->method('get')->willReturn(
			new class {
				/**
				 * Hand out nothing.
				 *
				 * @return string Blank material.
				 */
				public function resolveInjectable($credentialId, $appId, $userId = null, $organisationId = null): string {
					return '   ';
				}
			}
		);
		$this->logger->expects($this->once())
			->method('warning')
			->with('[SenderIdentity] the broker returned no material for identity-1');

		$this->assertSame(
			['key' => null, 'state' => SenderIdentityService::SIGNING_KEY_UNRESOLVABLE],
			$this->service()->signingMaterial(identity: ['smimePrivateKeyRef' => 'cred-1'], identityId: 'identity-1')
		);

	}//end testBlankBrokerMaterialIsUnresolvable()

	/**
	 * Without a reference, a missing or non-entity identity row means no key.
	 *
	 * @return void
	 */
	public function testAMissingRowOnTheInlinePathIsAbsent(): void {
		$this->objectService->method('find')->willReturnOnConsecutiveCalls(
			$this->throwException(new DoesNotExistException('gone')),
			['smimePrivateKey' => 'array, not an entity']
		);
		$service = $this->service();

		$absent = ['key' => null, 'state' => SenderIdentityService::SIGNING_KEY_ABSENT];
		$this->assertSame($absent, $service->signingMaterial(identity: [], identityId: 'identity-1'));
		$this->assertSame($absent, $service->signingMaterial(identity: ['smimePrivateKeyRef' => ' '], identityId: 'identity-1'));

	}//end testAMissingRowOnTheInlinePathIsAbsent()
}//end class

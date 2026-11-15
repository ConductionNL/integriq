<?php

/**
 * Unit tests for SenderIdentityService resolution: a named identity that does
 * not exist is an error, a message naming none falls back to the default, and
 * the signing key is resolved through the credential broker or read inline,
 * with every way that can fail answering a distinct state.
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
 * @spec openspec/changes/enrol-sender-identity-in-credential-broker/specs/outbound-sender-identity/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Outbound;

use OCA\Integriq\Outbound\Identity\SenderIdentityService;
use OCA\Integriq\Outbound\MessageRecorder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Identity resolution and signing-key resolution.
 *
 * @covers \OCA\Integriq\Outbound\Identity\SenderIdentityService
 *
 * @spec openspec/changes/enrol-sender-identity-in-credential-broker/specs/outbound-sender-identity/spec.md#requirement-req-osi-011-signing-keeps-working-once-the-key-is-brokered
 */
class SenderIdentityResolutionTest extends TestCase {

	/**
	 * @var ORObjectService&MockObject
	 */
	private $objects;

	/**
	 * @var ContainerInterface&MockObject
	 */
	private $container;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private $logger;

	/**
	 * Fresh doubles per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objects = $this->createMock(ORObjectService::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return SenderIdentityService
	 */
	private function service(): SenderIdentityService {
		return new SenderIdentityService($this->objects, $this->container, $this->logger);
	}//end service()

	/**
	 * A stored identity.
	 *
	 * @param string               $uuid   Its uuid.
	 * @param array<string, mixed> $object Its object.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, array $object): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($object);
		return $entity;
	}//end entity()

	/**
	 * A named identity that is not stored is an error naming it.
	 *
	 * @return void
	 */
	public function testANamedIdentityThatDoesNotExistIsAnError(): void {
		$this->objects->method('find')->willThrowException(new DoesNotExistException('gone'));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('No sender identity "id-9".');
		$this->service()->resolve('id-9');
	}//end testANamedIdentityThatDoesNotExistIsAnError()

	/**
	 * A message naming no identity leaves under the default, with defaults filled in.
	 *
	 * @return void
	 */
	public function testNoIdentityFallsBackToTheDefault(): void {
		$this->objects->expects($this->never())->method('find');
		$this->objects->expects($this->once())->method('findAll')->with(
			['filters' => ['register' => MessageRecorder::REGISTER, 'schema' => 'sender_identity']]
		)->willReturn(
			[
				'results' => [
					['isDefault' => true],
					$this->entity('id-1', ['address' => 'a@example.org']),
					$this->entity('id-2', ['address' => 'b@example.org', 'isDefault' => true, 'quotingLevel' => 'everything', 'holdWindowSeconds' => '30']),
				],
			]
		);

		$this->assertSame(
			[
				'identity' => ['address' => 'b@example.org', 'isDefault' => true, 'quotingLevel' => SenderIdentityService::QUOTING_LAST, 'holdWindowSeconds' => 30],
				'id' => 'id-2',
				'fallback' => true,
			],
			$this->service()->resolve('  ')
		);
	}//end testNoIdentityFallsBackToTheDefault()

	/**
	 * Without a default identity, a message naming none cannot be sent.
	 *
	 * @return void
	 */
	public function testNoDefaultIdentityIsAnError(): void {
		$this->objects->method('findAll')->willReturn(['results' => 'not a list']);

		$this->assertSame([], $this->service()->all());
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('no default sender identity');
		$this->service()->resolve(null);
	}//end testNoDefaultIdentityIsAnError()

	/**
	 * A brokered key resolves through the broker under the identity's organisation.
	 *
	 * @return void
	 */
	public function testABrokeredKeyResolvesThroughTheBroker(): void {
		$entity = $this->entity('id-1', []);
		$entity->setOrganisation('org-1');
		$this->objects->expects($this->once())->method('find')
			->with('id-1', MessageRecorder::REGISTER, 'sender_identity', false, false, false)
			->willReturn($entity);

		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->expects($this->once())->method('resolveInjectable')->with('cred-1', 'openconnector', null, 'org-1')->willReturn(" PEM \n");
		$this->container->expects($this->once())->method('get')->with(CredentialBrokerService::class)->willReturn($broker);

		$this->assertSame(
			['key' => 'PEM', 'state' => SenderIdentityService::SIGNING_KEY_AVAILABLE],
			$this->service()->signingMaterial(['smimePrivateKeyRef' => 'cred-1'], 'id-1')
		);
	}//end testABrokeredKeyResolvesThroughTheBroker()

	/**
	 * A broker that answers nothing leaves the key unresolvable, and says so.
	 *
	 * @return void
	 */
	public function testEmptyBrokerMaterialIsUnresolvable(): void {
		$this->objects->method('find')->willReturn($this->entity('id-1', []));
		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->method('resolveInjectable')->willReturn('   ');
		$this->container->method('get')->willReturn($broker);
		$this->logger->expects($this->once())->method('warning')->with('[SenderIdentity] the broker returned no material for id-1');

		$this->assertSame(
			['key' => null, 'state' => SenderIdentityService::SIGNING_KEY_UNRESOLVABLE],
			$this->service()->signingMaterial(['smimePrivateKeyRef' => 'cred-1'], 'id-1')
		);
	}//end testEmptyBrokerMaterialIsUnresolvable()

	/**
	 * An identity the system read cannot find leaves a brokered key unresolvable, without asking the broker.
	 *
	 * @return void
	 */
	public function testAnUnreadableIdentityLeavesABrokeredKeyUnresolvable(): void {
		$this->objects->method('find')->willThrowException(new RuntimeException('store down'));
		$this->container->expects($this->never())->method('get');

		$this->assertSame(
			['key' => null, 'state' => SenderIdentityService::SIGNING_KEY_UNRESOLVABLE],
			$this->service()->signingMaterial(['smimePrivateKeyRef' => 'cred-1'], 'id-1')
		);
	}//end testAnUnreadableIdentityLeavesABrokeredKeyUnresolvable()

	/**
	 * Without the broker class on the host, a brokered key is unresolvable.
	 *
	 * @return void
	 */
	public function testAMissingBrokerLeavesTheKeyUnresolvable(): void {
		$service = new class($this->objects, $this->container, $this->logger) extends SenderIdentityService {
			protected function isBrokerClassAvailable(): bool {
				return false;
			}
		};
		$this->objects->expects($this->never())->method('find');
		$this->logger->expects($this->once())->method('warning')->with('[SenderIdentity] the credential broker is unavailable, so id-1 cannot sign');

		$this->assertSame(
			['key' => null, 'state' => SenderIdentityService::SIGNING_KEY_UNRESOLVABLE],
			$service->signingMaterial(['smimePrivateKeyRef' => 'cred-1'], 'id-1')
		);
	}//end testAMissingBrokerLeavesTheKeyUnresolvable()

	/**
	 * An inline key is read from the stored identity; a missing identity or key is absent.
	 *
	 * @return void
	 */
	public function testAnInlineKeyIsReadFromTheStoredIdentity(): void {
		$this->objects->method('find')->willReturnOnConsecutiveCalls(
			$this->entity('id-1', ['smimePrivateKey' => ' KEY ']),
			$this->entity('id-1', ['smimePrivateKey' => '']),
			null
		);
		$service = $this->service();

		$this->assertSame(['key' => 'KEY', 'state' => SenderIdentityService::SIGNING_KEY_AVAILABLE], $service->signingMaterial([], 'id-1'));
		$this->assertSame(['key' => null, 'state' => SenderIdentityService::SIGNING_KEY_ABSENT], $service->signingMaterial([], 'id-1'));
		$this->assertSame(['key' => null, 'state' => SenderIdentityService::SIGNING_KEY_ABSENT], $service->signingMaterial([], 'id-1'));
	}//end testAnInlineKeyIsReadFromTheStoredIdentity()

	/**
	 * The envelope names the display name when there is one, and replies to the address by default.
	 *
	 * @return void
	 */
	public function testTheEnvelope(): void {
		$service = $this->service();

		$this->assertSame(
			['from' => 'Gemeente <noreply@example.org>', 'replyTo' => 'noreply@example.org', 'signature' => '', 'account' => ''],
			$service->envelopeFor(['address' => 'noreply@example.org', 'displayName' => 'Gemeente'])
		);
		$this->assertSame(
			['from' => 'noreply@example.org', 'replyTo' => 'post@example.org', 'signature' => 'Groet', 'account' => '3'],
			$service->envelopeFor(['address' => 'noreply@example.org', 'replyTo' => 'post@example.org', 'signature' => 'Groet', 'mailAccount' => 3])
		);
	}//end testTheEnvelope()
}//end class

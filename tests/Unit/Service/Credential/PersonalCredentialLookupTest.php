<?php

/**
 * A person's own delegated grant at a provider.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-microsoft-365-source-answers-a-document-search-across-files-mail-and-chat-req-dcc-008
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Credential;

use OCA\Integriq\Service\Credential\PersonalCredentialLookup;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Integriq\Service\Credential\PersonalCredentialLookup
 */
class PersonalCredentialLookupTest extends TestCase {

	/**
	 * OpenRegister's object service double.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objects;

	/**
	 * Set up the double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(originalClassName: ObjectService::class);
	}//end setUp()

	/**
	 * One credential row.
	 *
	 * @param string               $uuid  Its id.
	 * @param string               $owner Its owner.
	 * @param array<string, mixed> $data  Its data.
	 *
	 * @return ObjectEntity
	 */
	private static function row(string $uuid, string $owner, array $data=[]): ObjectEntity {
		$row = new ObjectEntity();
		$row->setUuid($uuid);
		$row->setOwner($owner);
		$row->setObject($data);

		return $row;
	}//end row()

	/**
	 * The lookup over the given rows.
	 *
	 * @param array<int, mixed> $rows The rows the broker holds at the provider.
	 *
	 * @return PersonalCredentialLookup
	 */
	private function lookup(array $rows): PersonalCredentialLookup {
		$this->objects->method('findAll')->willReturn(['results' => $rows]);

		return new PersonalCredentialLookup(objectService: $this->objects, logger: $this->createMock(originalClassName: LoggerInterface::class));
	}//end lookup()

	/**
	 * The person's one personal, enabled credential is their grant; it is asked for at the provider.
	 *
	 * @return void
	 */
	public function testThePersonsOwnPersonalCredentialIsTheGrant(): void {
		$sent = null;
		$this->objects->expects(self::once())->method('findAll')->willReturnCallback(
			static function (array $config) use (&$sent): array {
				$sent = $config;
				return ['results' => [
					self::row(uuid: 'c-other', owner: 'someone'),
					self::row(uuid: 'c-shared', owner: 'pjansen', data: ['scope' => 'shared']),
					self::row(uuid: 'c-off', owner: 'pjansen', data: ['scope' => 'personal', 'status' => 'disabled']),
					self::row(uuid: 'c-mine', owner: 'pjansen', data: ['scope' => 'personal', 'status' => 'active']),
				]];
			}
		);
		$lookup = new PersonalCredentialLookup(objectService: $this->objects, logger: $this->createMock(originalClassName: LoggerInterface::class));

		self::assertSame(expected: 'c-mine', actual: $lookup->credentialIdFor(provider: 'microsoft-entra', userId: 'pjansen'));
		self::assertSame(
			expected: ['register' => 'credential-broker', 'schema' => 'brokeredcredential', 'provider' => 'microsoft-entra'],
			actual: $sent['filters']
		);
	}//end testThePersonsOwnPersonalCredentialIsTheGrant()

	/**
	 * No credential, two of them, or no person: no grant, so the caller refuses.
	 *
	 * @return void
	 */
	public function testNoneOrTwoCredentialsAreNoGrant(): void {
		$none = $this->lookup(rows: [self::row(uuid: 'c-other', owner: 'someone')]);
		self::assertNull(actual: $none->credentialIdFor(provider: 'microsoft-entra', userId: 'pjansen'));

		$this->setUp();
		$two = $this->lookup(rows: [self::row(uuid: 'c-1', owner: 'pjansen'), self::row(uuid: 'c-2', owner: 'pjansen')]);
		self::assertNull(actual: $two->credentialIdFor(provider: 'microsoft-entra', userId: 'pjansen'));

		$this->setUp();
		$this->objects->expects(self::never())->method('findAll');
		$lookup = new PersonalCredentialLookup(objectService: $this->objects, logger: $this->createMock(originalClassName: LoggerInterface::class));
		self::assertNull(actual: $lookup->credentialIdFor(provider: 'microsoft-entra', userId: ''));
		self::assertNull(actual: $lookup->credentialIdFor(provider: ' ', userId: 'pjansen'));
	}//end testNoneOrTwoCredentialsAreNoGrant()

	/**
	 * A lookup that fails is logged and answers no grant, never a guess.
	 *
	 * @return void
	 */
	public function testAFailingLookupIsNoGrant(): void {
		$this->objects->method('findAll')->willThrowException(exception: new RuntimeException(message: 'register missing'));
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects(self::once())->method('warning');
		$lookup = new PersonalCredentialLookup(objectService: $this->objects, logger: $logger);

		self::assertNull(actual: $lookup->credentialIdFor(provider: 'microsoft-entra', userId: 'pjansen'));
	}//end testAFailingLookupIsNoGrant()

	/**
	 * A plain list answer (no `results` envelope) is read the same way.
	 *
	 * @return void
	 */
	public function testAPlainListAnswerIsRead(): void {
		$this->objects->method('findAll')->willReturn([self::row(uuid: 'c-mine', owner: 'pjansen')]);
		$lookup = new PersonalCredentialLookup(objectService: $this->objects, logger: $this->createMock(originalClassName: LoggerInterface::class));

		self::assertSame(expected: 'c-mine', actual: $lookup->credentialIdFor(provider: 'microsoft-entra', userId: 'pjansen'));
	}//end testAPlainListAnswerIsRead()
}//end class

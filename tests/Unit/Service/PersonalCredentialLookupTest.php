<?php

/**
 * PersonalCredentialLookup: only the user's own enabled personal credentials.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-microsoft-365-source-answers-a-document-search-across-files-mail-and-chat-req-dcc-008
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\BrokeredCallService;
use OCA\Integriq\Service\PersonalCredentialLookup;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Integriq\Service\PersonalCredentialLookup
 */
class PersonalCredentialLookupTest extends TestCase {

	/**
	 * A credential row.
	 *
	 * @param string $uuid  The UUID.
	 * @param string $owner The owner.
	 * @param array  $data  Extra data.
	 *
	 * @return ObjectEntity The row.
	 */
	private static function row(string $uuid, string $owner, array $data = []): ObjectEntity {
		$row = new ObjectEntity();
		$row->setUuid($uuid);
		$row->setOwner($owner);
		$row->setObject($data + ['provider' => 'microsoft-entra']);

		return $row;
	}//end row()

	public function testOnlyTheUsersOwnEnabledPersonalCredentialsCount(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->expects(self::once())->method('findAll')->with(
			[
				'filters' => [
					'register' => BrokeredCallService::CREDENTIAL_REGISTER,
					'schema' => BrokeredCallService::CREDENTIAL_SCHEMA,
					'provider' => 'microsoft-entra',
				],
			]
		)->willReturn(
			['results' => [
				self::row(uuid: 'own', owner: 'pjansen'),
				self::row(uuid: 'own-explicit', owner: 'pjansen', data: ['scope' => 'personal', 'status' => 'active']),
				self::row(uuid: 'other-user', owner: 'avries'),
				self::row(uuid: 'organisation', owner: 'pjansen', data: ['scope' => 'organisation']),
				self::row(uuid: 'disabled', owner: 'pjansen', data: ['status' => 'disabled']),
				['uuid' => 'not-an-entity'],
			]]
		);

		$ids = (new PersonalCredentialLookup(objectService: $objects))->credentialIds(provider: 'microsoft-entra', userId: 'pjansen');

		self::assertSame(['own', 'own-explicit'], $ids);
	}//end testOnlyTheUsersOwnEnabledPersonalCredentialsCount()

	public function testWithoutAUserOrAProviderNothingIsLookedUp(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->expects(self::never())->method('findAll');
		$lookup = new PersonalCredentialLookup(objectService: $objects);

		self::assertSame([], $lookup->credentialIds(provider: 'microsoft-entra', userId: ''));
		self::assertSame([], $lookup->credentialIds(provider: ' ', userId: 'pjansen'));
	}//end testWithoutAUserOrAProviderNothingIsLookedUp()

	public function testAPlainListAnswerIsReadAndAFailureIsNotSwallowed(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnOnConsecutiveCalls(
			[self::row(uuid: 'own', owner: 'pjansen')],
			self::throwException(new RuntimeException('down'))
		);
		$lookup = new PersonalCredentialLookup(objectService: $objects);

		self::assertSame(['own'], $lookup->credentialIds(provider: 'microsoft-entra', userId: 'pjansen'));
		$this->expectException(RuntimeException::class);
		$lookup->credentialIds(provider: 'microsoft-entra', userId: 'pjansen');
	}//end testAPlainListAnswerIsReadAndAFailureIsNotSwallowed()
}//end class

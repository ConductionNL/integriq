<?php

/**
 * The declared mapping, the token matrix and the leak guard.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Objecten
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.conduction.nl
 *
 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Objecten;

use OCA\Integriq\Service\Objecten\ObjectenTokenService;
use OCA\Integriq\Service\Objecten\ObjectRecordTranslator;
use OCA\Integriq\Service\Objecten\ObjecttypeRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Verifies REQ-OAF-001, the read-shape half of REQ-OAF-003 and REQ-OAF-005.
 */
class ObjectenFacadeTest extends TestCase {

	/**
	 * Two objecttypes, one of them version-pinned.
	 *
	 * @return ObjecttypeRegistry The registry.
	 */
	private function registry(): ObjecttypeRegistry {
		$registry = new ObjecttypeRegistry();
		$registry->load(
			[
				['uuid' => 'aaa', 'name' => 'melding', 'register' => 'meldingen', 'schema' => 'melding', 'versions' => ['1']],
				['uuid' => 'bbb', 'name' => 'melding', 'register' => 'klachten', 'schema' => 'melding'],
			]
		);

		return $registry;
	}//end registry()

	/**
	 * A token service over declared tokens and a fake credential broker.
	 *
	 * @param array<int, array<string, mixed>> $tokens The declarations.
	 * @param array<string, string>            $keys   Reference to key.
	 *
	 * @return ObjectenTokenService The service.
	 */
	private function tokens(array $tokens, array $keys): ObjectenTokenService {
		$service = new ObjectenTokenService(
			objecttypes: $this->registry(),
			credentialRead: static function (string $reference) use ($keys): ?string {
				return ($keys[$reference] ?? null);
			}
		);
		$service->load($tokens);

		return $service;
	}//end tokens()

	/**
	 * A token that may read `aaa` only.
	 *
	 * @return ObjectenTokenService The service.
	 */
	private function readOnlyOnA(): ObjectenTokenService {
		return $this->tokens(
			tokens: [
				[
					'credential' => 'cred-1',
					'principal' => 'leverancier',
					'permissions' => ['aaa' => ObjectenTokenService::READ],
				],
			],
			keys: ['cred-1' => 'the-key']
		);
	}//end readOnlyOnA()

	/**
	 * 🔴 Two registers holding a schema with the same name resolve separately.
	 *
	 * A name-based lookup picks one of them by iteration order, so a
	 * counterparty writing to a national uuid lands in whichever register was
	 * first, and nothing says which.
	 *
	 * @return void
	 */
	public function testTwoRegistersHoldingTheSameSchemaNameResolveSeparately(): void {
		$registry = $this->registry();

		$this->assertSame('meldingen', $registry->find(uuid: 'aaa')['register']);
		$this->assertSame('klachten', $registry->find(uuid: 'bbb')['register']);
		$this->assertSame(
			$registry->find(uuid: 'aaa')['schema'],
			$registry->find(uuid: 'bbb')['schema'],
			'the two share a schema NAME, which is exactly why the mapping may not be inferred from one'
		);
	}//end testTwoRegistersHoldingTheSameSchemaNameResolveSeparately()

	/**
	 * A declaration missing any required field is refused, naming the field.
	 *
	 * @return void
	 */
	public function testADeclarationMissingARequiredFieldIsRefused(): void {
		$registry = new ObjecttypeRegistry();

		foreach (ObjecttypeRegistry::REQUIRED as $missing) {
			$declaration = ['uuid' => 'x', 'name' => 'n', 'register' => 'r', 'schema' => 's'];
			unset($declaration[$missing]);

			$this->assertNotNull(
				$registry->refusalFor(declaration: $declaration),
				sprintf('a declaration with no "%s" must be refused', $missing)
			);
		}

		$this->assertNull(
			$registry->refusalFor(declaration: ['uuid' => 'x', 'name' => 'n', 'register' => 'r', 'schema' => 's']),
			'the control: a complete declaration is accepted'
		);
	}//end testADeclarationMissingARequiredFieldIsRefused()

	/**
	 * A second declaration for one uuid is refused, not allowed to overwrite.
	 *
	 * @return void
	 */
	public function testASecondDeclarationForOneUuidIsRefused(): void {
		$registry = new ObjecttypeRegistry();
		$registry->load(
			[
				['uuid' => 'aaa', 'name' => 'a', 'register' => 'first', 'schema' => 's'],
				['uuid' => 'aaa', 'name' => 'a', 'register' => 'second', 'schema' => 's'],
			]
		);

		$this->assertSame('first', $registry->find(uuid: 'aaa')['register'], 'first wins');
		$this->assertCount(1, $registry->refused(), 'and the loser is visible, not swallowed');
	}//end testASecondDeclarationForOneUuidIsRefused()

	/**
	 * 🔴 An unlisted version is refused, never answered with a newer one.
	 *
	 * @return void
	 */
	public function testAnUnlistedVersionIsRefusedRatherThanUpgraded(): void {
		$registry = $this->registry();

		$this->assertTrue($registry->allowsVersion(uuid: 'aaa', version: '1'));
		$this->assertFalse(
			$registry->allowsVersion(uuid: 'aaa', version: '2'),
			'answering with a newer version answers a different question than the consumer asked'
		);
		$this->assertTrue(
			$registry->allowsVersion(uuid: 'bbb', version: '7'),
			'an objecttype pinning no versions serves any, which is the declared default'
		);
	}//end testAnUnlistedVersionIsRefusedRatherThanUpgraded()

	/**
	 * 🔴 No token is 401, and it is decided before anything else.
	 *
	 * @return void
	 */
	public function testNoTokenIsRefusedBeforeAnythingElse(): void {
		$service = $this->readOnlyOnA();

		foreach ([null, '', '   ', 'Bearer the-key', 'Token', 'Token '] as $header) {
			$verdict = $service->verdictFor(authorization: $header, objecttype: 'aaa');

			$this->assertSame(401, $verdict['status'], sprintf('"%s" must not authenticate', (string)$header));
		}
	}//end testNoTokenIsRefusedBeforeAnythingElse()

	/**
	 * The control: the right token on the right objecttype reads.
	 *
	 * @return void
	 */
	public function testTheRightTokenReadsItsOwnObjecttype(): void {
		$verdict = $this->readOnlyOnA()->verdictFor(authorization: 'Token the-key', objecttype: 'aaa');

		$this->assertSame(ObjectenTokenService::ALLOWED, $verdict['verdict'], 'the control: a scoped token works');
		$this->assertSame('leverancier', $verdict['principal'], 'and the write is attributable');
	}//end testTheRightTokenReadsItsOwnObjecttype()

	/**
	 * The scheme is case insensitive, as RFC 7235 says; the key is not.
	 *
	 * @return void
	 */
	public function testTheSchemeIsCaseInsensitiveAndTheKeyIsNot(): void {
		$service = $this->readOnlyOnA();

		$this->assertSame(
			ObjectenTokenService::ALLOWED,
			$service->verdictFor(authorization: 'token the-key', objecttype: 'aaa')['verdict']
		);
		$this->assertSame(
			401,
			$service->verdictFor(authorization: 'Token THE-KEY', objecttype: 'aaa')['status'],
			'the key after the scheme is opaque and compared exactly'
		);
	}//end testTheSchemeIsCaseInsensitiveAndTheKeyIsNot()

	/**
	 * 🔴 A token scoped to one objecttype is refused on a second, with 403.
	 *
	 * @return void
	 */
	public function testATokenScopedToOneObjecttypeIsRefusedOnASecond(): void {
		$verdict = $this->readOnlyOnA()->verdictFor(authorization: 'Token the-key', objecttype: 'bbb');

		$this->assertSame(ObjectenTokenService::REFUSED_OBJECTTYPE, $verdict['verdict']);
		$this->assertSame(403, $verdict['status']);
	}//end testATokenScopedToOneObjecttypeIsRefusedOnASecond()

	/**
	 * 🔴 A read token cannot write, and gets 403 rather than a silent read.
	 *
	 * @return void
	 */
	public function testAReadTokenCannotWrite(): void {
		$verdict = $this->readOnlyOnA()->verdictFor(authorization: 'Token the-key', objecttype: 'aaa', writing: true);

		$this->assertSame(ObjectenTokenService::READ_ONLY, $verdict['verdict']);
		$this->assertSame(403, $verdict['status']);
	}//end testAReadTokenCannotWrite()

	/**
	 * An objecttype this instance does not publish is 404, not 403.
	 *
	 * That split is the standard's, and it means a valid token can enumerate
	 * which objecttypes exist. Asserted so the consequence is visible rather
	 * than incidental.
	 *
	 * @return void
	 */
	public function testAnUnknownObjecttypeIs404AndThatLeaksExistence(): void {
		$service = $this->readOnlyOnA();

		$this->assertSame(
			404,
			$service->verdictFor(authorization: 'Token the-key', objecttype: 'zzz')['status']
		);
		$this->assertSame(
			403,
			$service->verdictFor(authorization: 'Token the-key', objecttype: 'bbb')['status'],
			'404 and 403 differ, so a valid token learns which objecttypes exist — the standard asks for this'
		);
	}//end testAnUnknownObjecttypeIs404AndThatLeaksExistence()

	/**
	 * 🔴 A declaration carrying a literal key is refused.
	 *
	 * The way a key ends up in a configuration export is somebody pasting it
	 * into the configuration, so the configuration must not accept one.
	 *
	 * @return void
	 */
	public function testADeclarationCarryingALiteralKeyIsRefused(): void {
		foreach (['key', 'secret', 'token'] as $forbidden) {
			$service = new ObjectenTokenService(objecttypes: $this->registry());
			$refusals = $service->load(
				[['credential' => 'c', 'principal' => 'p', $forbidden => 'sup3rsecret', 'permissions' => []]]
			);

			$this->assertCount(1, $refusals, sprintf('a declaration carrying "%s" must be refused', $forbidden));
			$this->assertStringNotContainsString('sup3rsecret', $refusals[0], 'and the refusal must not echo it');
		}
	}//end testADeclarationCarryingALiteralKeyIsRefused()

	/**
	 * A token with no principal is refused: a write needs somebody to attribute.
	 *
	 * @return void
	 */
	public function testATokenWithNoPrincipalIsRefused(): void {
		$service = new ObjectenTokenService(objecttypes: $this->registry());

		$this->assertCount(1, $service->load([['credential' => 'c', 'permissions' => []]]));
	}//end testATokenWithNoPrincipalIsRefused()

	/**
	 * An unknown permission word is dropped, not downgraded and not upgraded.
	 *
	 * @return void
	 */
	public function testAnUnknownPermissionWordIsDropped(): void {
		$service = $this->tokens(
			tokens: [['credential' => 'c', 'principal' => 'p', 'permissions' => ['aaa' => 'readwrite']]],
			keys: ['c' => 'k']
		);

		$this->assertSame(
			ObjectenTokenService::REFUSED_OBJECTTYPE,
			$service->verdictFor(authorization: 'Token k', objecttype: 'aaa')['verdict'],
			'a misspelled permission grants nothing rather than guessing which one was meant'
		);
	}//end testAnUnknownPermissionWordIsDropped()

	/**
	 * A credential the broker cannot resolve authenticates nobody.
	 *
	 * @return void
	 */
	public function testACredentialTheBrokerCannotResolveAuthenticatesNobody(): void {
		$service = $this->tokens(
			tokens: [['credential' => 'missing', 'principal' => 'p', 'permissions' => ['aaa' => 'read']]],
			keys: []
		);

		$this->assertSame(401, $service->verdictFor(authorization: 'Token anything', objecttype: 'aaa')['status']);
	}//end testACredentialTheBrokerCannotResolveAuthenticatesNobody()

	/**
	 * 🔴 No OpenRegister field reaches the consumer.
	 *
	 * @return void
	 */
	public function testNoOpenRegisterFieldReachesTheConsumer(): void {
		$translator = new ObjectRecordTranslator();

		$rendered = $translator->toRecord(
			object: [
				'@self' => ['id' => 'u-1', 'created' => '2026-09-18T11:00:00+02:00', 'version' => 3, 'owner' => 'anja'],
				'id' => 'u-1',
				'register' => 'meldingen',
				'schema' => 'melding',
				'organisation' => 'gemeente',
				'straatnaam' => 'Kerkstraat',
			],
			objecttype: 'aaa',
			baseUrl: 'https://example.nl/api/v2'
		);

		$this->assertSame(
			[],
			$translator->foreignFieldsIn(rendered: $rendered),
			'a field added to the translator without being added to the vocabulary must fail here, not in a landscape'
		);
		$this->assertSame(['straatnaam' => 'Kerkstraat'], $rendered['record']['data']);
		$this->assertSame('u-1', $rendered['uuid']);
		$this->assertSame('aaa', $rendered['type']);
		$this->assertSame('2026-09-18', $rendered['record']['startAt'], 'the standard writes a date, not an instant');
	}//end testNoOpenRegisterFieldReachesTheConsumer()

	/**
	 * The guard is discriminating: a foreign field IS reported.
	 *
	 * Without this the leak test could be passing on a guard that reports
	 * nothing whatever it is given.
	 *
	 * @return void
	 */
	public function testTheLeakGuardReportsAForeignField(): void {
		$translator = new ObjectRecordTranslator();

		$rendered = $translator->toRecord(object: ['straatnaam' => 'x'], objecttype: 'aaa');
		$rendered['_openregister_internal'] = 'leaked';
		$rendered['record']['schema'] = 'melding';
		$rendered['record']['data']['register'] = 'meldingen';

		$foreign = $translator->foreignFieldsIn(rendered: $rendered);

		$this->assertContains('_openregister_internal', $foreign);
		$this->assertContains('record.schema', $foreign);
		$this->assertContains('record.data.register', $foreign);
	}//end testTheLeakGuardReportsAForeignField()

	/**
	 * An unreadable date becomes null rather than a wrong date.
	 *
	 * @return void
	 */
	public function testAnUnreadableDateBecomesNull(): void {
		$rendered = (new ObjectRecordTranslator())->toRecord(
			object: ['@self' => ['id' => 'u', 'created' => 'ooit']],
			objecttype: 'aaa'
		);

		$this->assertNull($rendered['record']['startAt']);
	}//end testAnUnreadableDateBecomesNull()
}//end class

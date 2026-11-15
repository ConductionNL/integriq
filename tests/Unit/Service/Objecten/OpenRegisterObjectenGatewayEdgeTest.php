<?php

/**
 * Unit tests for OpenRegisterObjectenGateway's token loading, delete wiring and declaration reading.
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

use OCA\Integriq\Service\Objecten\ObjectenOpenRegisterAccess;
use OCA\Integriq\Service\Objecten\ObjectenTokenService;
use OCA\Integriq\Service\Objecten\ObjecttypeDeclarationReader;
use OCA\Integriq\Service\Objecten\ObjecttypeRegistry;
use OCA\Integriq\Service\Objecten\ObjectRecordTranslator;
use OCA\Integriq\Service\Objecten\ObjectWriteHandler;
use OCA\Integriq\Service\Objecten\OpenRegisterObjectenGateway;
use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins that a refused token declaration is logged, that a delete reaches OpenRegister,
 * and that an app whose path cannot be resolved contributes no declarations.
 */
#[CoversClass(OpenRegisterObjectenGateway::class)]
#[CoversClass(ObjecttypeDeclarationReader::class)]
#[UsesClass(ObjecttypeRegistry::class)]
#[UsesClass(ObjectenTokenService::class)]
#[UsesClass(ObjectWriteHandler::class)]
#[UsesClass(ObjectRecordTranslator::class)]
class OpenRegisterObjectenGatewayEdgeTest extends TestCase {

	/**
	 * An access stub declaring one objecttype and the given tokens.
	 *
	 * @param array<int, mixed> $tokens The token declarations.
	 *
	 * @return ObjectenOpenRegisterAccess&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function access(array $tokens = []): ObjectenOpenRegisterAccess {
		$access = $this->createMock(ObjectenOpenRegisterAccess::class);
		$access->method('declarations')->willReturnCallback(
			static fn (string $schema): array => ($schema === ObjectenOpenRegisterAccess::CREDENTIAL_SCHEMA
				? $tokens
				: [['publishedUuid' => 'aaa', 'name' => 'melding', 'register' => 'meldingen', 'schema' => 'melding']])
		);

		return $access;
	}//end access()

	/**
	 * A refused token declaration is logged with its reason; a good one still loads.
	 *
	 * @return void
	 */
	public function testARefusedTokenDeclarationIsLogged(): void {
		$access = $this->access(['not an object', ['credential' => 'ref-a', 'principal' => 'p-a', 'permissions' => ['aaa' => 'read']]]);
		$access->method('readCredential')->willReturnCallback(static fn (string $reference): ?string => ($reference === 'ref-a' ? 'key-a' : null));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with('Integriq objecten: a token declaration was refused: a token declaration must be an object');

		$tokens = (new OpenRegisterObjectenGateway($access, $logger))->tokens();

		$this->assertSame('p-a', $tokens->verdictFor('Token key-a', 'aaa')['principal']);
		$this->assertSame(ObjectenTokenService::ALLOWED, $tokens->verdictFor('Token key-a', 'aaa')['verdict']);
	}//end testARefusedTokenDeclarationIsLogged()

	/**
	 * A delete through the write handler reaches OpenRegister with the declared register and schema, and is announced.
	 *
	 * @return void
	 */
	public function testADeleteReachesOpenRegister(): void {
		$access = $this->access();
		$access->expects($this->once())->method('deleteObject')->with('meldingen', 'melding', 'u1', 'lev');
		$access->expects($this->once())->method('announce')
			->with($this->callback(static fn (array $n): bool => $n['actie'] === 'destroy' && $n['hoofdObject'] === 'u1'));

		$answer = (new OpenRegisterObjectenGateway($access, $this->createMock(LoggerInterface::class)))
			->writes()->delete(type: 'aaa', uuid: 'u1', principal: 'lev');

		$this->assertSame(['status' => 204, 'body' => []], $answer);
	}//end testADeleteReachesOpenRegister()

	/**
	 * An app whose path cannot be resolved is skipped without a warning; the configured objecttype still loads.
	 *
	 * @return void
	 */
	public function testAnAppWithoutAPathContributesNothing(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getEnabledApps')->willReturn(['ghost']);
		$appManager->expects($this->exactly(2))->method('getAppPath')->with('ghost')->willThrowException(new RuntimeException('no such app'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');
		$reader = new ObjecttypeDeclarationReader($appManager, $logger);

		$this->assertSame([], $reader->read());

		$registry = (new OpenRegisterObjectenGateway($this->access(), $logger, $reader))->registry();
		$this->assertSame(['aaa'], array_column($registry->all(), 'uuid'));
	}//end testAnAppWithoutAPathContributesNothing()
}//end class

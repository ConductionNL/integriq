<?php

/**
 * OpenRegister as the objecten facade reaches it: objects read, written and
 * deleted as the token's principal, the schema served as JSON Schema, the
 * credential behind a token, and integriq's own declarations.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Objecten
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Objecten;

use OCA\Integriq\Service\BrokeredCallService;
use OCA\Integriq\Service\Objecten\ObjectenOpenRegisterAccess;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Integriq\Service\Objecten\ObjectenOpenRegisterAccess
 */
class ObjectenOpenRegisterAccessTest extends TestCase {

	/**
	 * The OpenRegister services by container id.
	 *
	 * @var array<string,mixed>
	 */
	private array $services = [];

	/**
	 * The user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $session;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The principal "alice".
	 *
	 * @var IUser&MockObject
	 */
	private IUser $alice;

	protected function setUp(): void {
		parent::setUp();
		$this->session = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->alice = $this->createMock(IUser::class);
	}//end setUp()

	/**
	 * The access under test, over the registered services.
	 *
	 * @return ObjectenOpenRegisterAccess
	 */
	private function access(): ObjectenOpenRegisterAccess {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id) => $this->services[$id] ?? null);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(fn (string $uid) => ($uid === 'alice') ? $this->alice : null);

		return new ObjectenOpenRegisterAccess($container, $users, $this->session, $this->logger);
	}//end access()

	/**
	 * A stored schema as OpenRegister's mapper answers it.
	 *
	 * @param array<int,string> $required The required properties.
	 *
	 * @return object
	 */
	private function storedSchema(array $required): object {
		return new class($required) {
			/**
			 * @param array<int,string> $required The required properties.
			 */
			public function __construct(private array $required) {
			}

			/**
			 * @return array<string,mixed>
			 */
			public function getProperties(): array {
				return ['naam' => ['type' => 'string']];
			}

			public function getTitle(): string {
				return 'Boom';
			}

			/**
			 * @return array<int|string,string>
			 */
			public function getRequired(): array {
				return $this->required;
			}
		};
	}//end storedSchema()

	/**
	 * A schema mapper answering the given stored value, or throwing it.
	 *
	 * @param mixed $stored What find() answers, or the exception it throws.
	 *
	 * @return object
	 */
	private function mapper(mixed $stored): object {
		return new class($stored) {
			/**
			 * @var array<int,array<int,mixed>>
			 */
			public array $calls = [];

			public function __construct(private mixed $stored) {
			}

			public function find(mixed ...$args): mixed {
				$this->calls[] = $args;
				if ($this->stored instanceof \Throwable) {
					throw $this->stored;
				}
				return $this->stored;
			}
		};
	}//end mapper()

	public function testTheSchemaIsServedAsAJsonSchemaDocument(): void {
		$mapper = $this->mapper($this->storedSchema(['k' => 'naam']));
		$this->services[ObjectenOpenRegisterAccess::SCHEMA_MAPPER] = $mapper;

		$this->assertSame(
			[
				'$schema' => 'https://json-schema.org/draft/2020-12/schema',
				'type' => 'object',
				'properties' => ['naam' => ['type' => 'string']],
				'title' => 'Boom',
				'required' => ['naam'],
			],
			$this->access()->readSchema('boom')
		);
		$this->assertSame([['boom', [], false, false]], $mapper->calls);
	}//end testTheSchemaIsServedAsAJsonSchemaDocument()

	public function testASchemaWithoutRequiredPropertiesNamesNone(): void {
		$this->services[ObjectenOpenRegisterAccess::SCHEMA_MAPPER] = $this->mapper($this->storedSchema([]));

		$this->assertArrayNotHasKey('required', $this->access()->readSchema('boom'));
	}//end testASchemaWithoutRequiredPropertiesNamesNone()

	public function testAStoredValueWithoutPropertiesIsNoSchema(): void {
		$this->services[ObjectenOpenRegisterAccess::SCHEMA_MAPPER] = $this->mapper(new \stdClass());

		$this->assertSame([], $this->access()->readSchema('boom'));
	}//end testAStoredValueWithoutPropertiesIsNoSchema()

	public function testAnUnreadableSchemaIsLoggedByClassAndServesNothing(): void {
		$this->services[ObjectenOpenRegisterAccess::SCHEMA_MAPPER] = $this->mapper(new RuntimeException('secret detail'));
		$this->logger->expects($this->once())
			->method('warning')
			->with('Integriq objecten: schema boom could not be read (RuntimeException).');

		$this->assertSame([], $this->access()->readSchema('boom'));
	}//end testAnUnreadableSchemaIsLoggedByClassAndServesNothing()

	public function testAMissingSchemaMapperServesNothing(): void {
		$this->assertSame([], $this->access()->readSchema('boom'));
	}//end testAMissingSchemaMapperServesNothing()

	/**
	 * An object service whose deleteObject answers the given value.
	 *
	 * @param mixed $answer What deleteObject answers.
	 *
	 * @return object
	 */
	private function objectService(mixed $answer): object {
		return new class($answer) {
			/**
			 * @var array<int,array<string,mixed>>
			 */
			public array $deleted = [];

			public function __construct(private mixed $answer) {
			}

			public function deleteObject(string $uuid, string $register, string $schema): mixed {
				$this->deleted[] = ['uuid' => $uuid, 'register' => $register, 'schema' => $schema];
				return $this->answer;
			}

			/**
			 * @param array<string,mixed> $config The query.
			 */
			public function findAll(array $config, bool $rbac = true, bool $multi = true): mixed {
				throw new RuntimeException('register down');
			}
		};
	}//end objectService()

	public function testADeleteRunsAsThePrincipalAndRestoresThePriorUser(): void {
		$service = $this->objectService(true);
		$this->services[ObjectenOpenRegisterAccess::OBJECT_SERVICE] = $service;
		$prior = $this->createMock(IUser::class);
		$this->session->method('getUser')->willReturn($prior);
		$set = [];
		$this->session->method('setVolatileActiveUser')->willReturnCallback(function (?IUser $user) use (&$set): void {
			$set[] = $user;
		});

		$this->access()->deleteObject('reg', 'boom', 'u-1', 'alice');

		$this->assertSame([['uuid' => 'u-1', 'register' => 'reg', 'schema' => 'boom']], $service->deleted);
		$this->assertSame([$this->alice, $prior], $set);
	}//end testADeleteRunsAsThePrincipalAndRestoresThePriorUser()

	public function testADeleteThatDidNotHappenIsAnError(): void {
		$this->services[ObjectenOpenRegisterAccess::OBJECT_SERVICE] = $this->objectService(false);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Object "u-1" was not deleted.');

		$this->access()->deleteObject('reg', 'boom', 'u-1', 'alice');
	}//end testADeleteThatDidNotHappenIsAnError()

	public function testAPrincipalThatIsNoUserDeletesNothing(): void {
		$service = $this->objectService(true);
		$this->services[ObjectenOpenRegisterAccess::OBJECT_SERVICE] = $service;
		$this->session->expects($this->never())->method('setVolatileActiveUser');

		try {
			$this->access()->deleteObject('reg', 'boom', 'u-1', 'mallory');
			$this->fail('a principal that is not a user must be refused');
		} catch (RuntimeException $exception) {
			$this->assertStringContainsString('not a user of this instance', $exception->getMessage());
		}

		$this->assertSame([], $service->deleted);
	}//end testAPrincipalThatIsNoUserDeletesNothing()

	public function testUnreadableDeclarationsAreLoggedAndAnswerNone(): void {
		$this->services[ObjectenOpenRegisterAccess::OBJECT_SERVICE] = $this->objectService(true);
		$this->logger->expects($this->once())
			->method('warning')
			->with('Integriq objecten: the objecttype declarations could not be read (RuntimeException).');

		$this->assertSame([], $this->access()->declarations(ObjectenOpenRegisterAccess::OBJECTTYPE_SCHEMA));
	}//end testUnreadableDeclarationsAreLoggedAndAnswerNone()

	public function testWithoutAnObjectServiceTheDeclarationsAnswerNone(): void {
		$this->logger->expects($this->once())->method('warning');

		$this->assertSame([], $this->access()->declarations(ObjectenOpenRegisterAccess::TOKEN_SCHEMA));
	}//end testWithoutAnObjectServiceTheDeclarationsAnswerNone()

	/**
	 * A credential broker answering the given key.
	 *
	 * @param mixed $key The resolved key.
	 *
	 * @return object
	 */
	private function broker(mixed $key): object {
		return new class($key) {
			/**
			 * @var array<int,array<int,mixed>>
			 */
			public array $calls = [];

			public function __construct(private mixed $key) {
			}

			public function resolveInjectable(mixed ...$args): mixed {
				$this->calls[] = $args;
				return $this->key;
			}
		};
	}//end broker()

	public function testACredentialResolvesToItsTrimmedKey(): void {
		$broker = $this->broker("  k-123 \n");
		$this->services[ObjectenOpenRegisterAccess::BROKER] = $broker;

		$this->assertSame('k-123', $this->access()->readCredential('cred-ref'));
		$this->assertSame([['cred-ref', BrokeredCallService::APP_ID, null, null]], $broker->calls);
	}//end testACredentialResolvesToItsTrimmedKey()

	public function testABlankCredentialIsNoKey(): void {
		$this->services[ObjectenOpenRegisterAccess::BROKER] = $this->broker('   ');

		$this->assertNull($this->access()->readCredential('cred-ref'));
	}//end testABlankCredentialIsNoKey()
}//end class

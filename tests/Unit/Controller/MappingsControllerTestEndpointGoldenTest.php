<?php

/**
 * Golden characterization of MappingsController::test().
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/mapping-and-search/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use Exception;
use OCA\Integriq\Controller\MappingsController;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\SourceMappingService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs test() over every early return (no session, refused action, missing
 * input, no OpenRegister, unknown schema, mapping error) and the mapping and
 * validation paths. Records the acting user the action check ran for, every
 * collaborator call and the response against a golden file.
 *
 * Regenerate the golden file on the code it must describe with
 * MAPPINGS_TEST_GOLDEN=1.
 *
 * @covers \OCA\Integriq\Controller\MappingsController
 */
class MappingsControllerTestEndpointGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/mappings/test-endpoint.json';

	/**
	 * Every recorded call, in order.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * The cases: request parameters and collaborator behaviour.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$body = ['inputObject' => ['name' => 'Ada'], 'mapping' => ['mapping' => ['fullName' => '{{name}}']]];
		return [
			'no-session' => ['session' => false, 'params' => $body],
			'action-refused' => ['refuse' => true, 'params' => $body],
			'forged-user-param' => ['params' => ($body + ['userId' => 'mallory', 'user' => 'mallory'])],
			'missing-input' => ['params' => ['mapping' => ['a' => 'b']]],
			'missing-mapping' => ['params' => ['inputObject' => ['a' => 1]]],
			'plain-mapping' => ['params' => $body],
			'string-mapping-wrapped' => ['params' => ['inputObject' => ['name' => 'Ada'], 'mapping' => '{"fullName":"{{name}}"}']],
			'mapping-throws' => ['mappingThrows' => true, 'params' => $body],
			'schema-without-openregister' => ['noOpenRegister' => true, 'params' => ($body + ['schema' => '7'])],
			'schema-not-found' => ['schemaMissing' => true, 'params' => ($body + ['schema' => '7'])],
			'schema-null-entity' => ['schemaNull' => true, 'params' => ($body + ['schema' => '7'])],
			'schema-no-validation' => ['params' => ($body + ['schema' => '7'])],
			'schema-validation-valid' => ['params' => ($body + ['schema' => 'slug-a', 'validation' => true])],
			'schema-validation-invalid' => ['invalid' => true, 'params' => ($body + ['schema' => 'slug-a', 'validation' => '1'])],
			'validation-without-schema' => ['params' => ($body + ['validation' => true])],
			'empty-schema-ignored' => ['noOpenRegister' => true, 'params' => ($body + ['schema' => '', 'validation' => true])],
			'no-openregister-plain' => ['noOpenRegister' => true, 'params' => $body],
		];
	}//end cases()

	/**
	 * Build the controller for one case.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array{0: MappingsController, 1: SourceMappingService}
	 */
	private function controller(array $case): array {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($case['params']);

		$actingUser = $this->createMock(IUser::class);
		$actingUser->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(($case['session'] ?? true) === true ? $actingUser : null);

		$actionAuth = $this->createMock(ActionAuthService::class);
		$actionAuth->method('requireAction')->willReturnCallback(
			function (IUser $user, string $action) use ($actingUser, $case) {
				$this->calls[] = ['requireAction', ($user === $actingUser ? 'session-user' : $user->getUID()), $action];
				if (($case['refuse'] ?? false) === true) {
					throw new OCSForbiddenException('refused');
				}
			}
		);

		$mapping = $this->createMock(MappingService::class);
		$mapping->method('executeMapping')->willReturnCallback(
			function (array $mapping, array $input) use ($case) {
				$this->calls[] = ['executeMapping', $mapping, $input];
				if (($case['mappingThrows'] ?? false) === true) {
					throw new Exception('bad template');
				}

				return ['fullName' => ($input['name'] ?? null)];
			}
		);

		$validation = new class(($case['invalid'] ?? false) === false) {
			/**
			 * @param bool $valid Whether the result is valid.
			 */
			public function __construct(private bool $valid) {
			}

			/**
			 * @return bool
			 */
			public function isValid(): bool {
				return $this->valid;
			}

			/**
			 * @return bool
			 */
			public function hasError(): bool {
				return false;
			}
		};
		$validator = new class($this, $validation) {
			/**
			 * @param MappingsControllerTestEndpointGoldenTest $test   The test.
			 * @param object                                   $result The result to return.
			 */
			public function __construct(private MappingsControllerTestEndpointGoldenTest $test, private object $result) {
			}

			/**
			 * @param array  $object The object.
			 * @param object $schema The schema.
			 *
			 * @return object
			 */
			public function validateObject(array $object, object $schema): object {
				$this->test->record(['validateObject', $object, get_class($schema)]);
				return $this->result;
			}
		};

		$openRegisters = $this->getMockBuilder(ObjectService::class)
			->disableOriginalConstructor()
			->onlyMethods(['setSchema'])
			->addMethods(['getCurrentSchemaEntity', 'getValidateHandler'])
			->getMock();
		$openRegisters->method('setSchema')->willReturnCallback(
			function (string $schema) use ($openRegisters, $case) {
				$this->calls[] = ['setSchema', $schema];
				if (($case['schemaMissing'] ?? false) === true) {
					throw new DoesNotExistException('none');
				}

				return $openRegisters;
			}
		);
		$openRegisters->method('getCurrentSchemaEntity')->willReturn(($case['schemaNull'] ?? false) === true ? null : new \stdClass());
		$openRegisters->method('getValidateHandler')->willReturn($validator);

		$sourceMapping = $this->createMock(SourceMappingService::class);
		$sourceMapping->method('getOpenRegisters')->willReturn(($case['noOpenRegister'] ?? false) === true ? null : $openRegisters);

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);

		$controller = new MappingsController(
			'integriq',
			$request,
			$mapping,
			$sourceMapping,
			$l,
			$session,
			$actionAuth,
			$this->createMock(LoggerInterface::class),
			$this->createMock(ContainerInterface::class)
		);

		return [$controller, $sourceMapping];
	}//end controller()

	/**
	 * Record a call made by an anonymous collaborator.
	 *
	 * @param array<int, mixed> $call The call.
	 *
	 * @return void
	 */
	public function record(array $call): void {
		$this->calls[] = $call;
	}//end record()

	/**
	 * Every case behaves as the golden file records.
	 *
	 * @return void
	 */
	public function testEveryCaseMatchesTheGoldenFile(): void {
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$this->calls = [];
			[$controller, $sourceMapping] = $this->controller(case: $case);
			try {
				$response = $controller->test(objectService: $sourceMapping);
				$outcome = ['status' => $response->getStatus(), 'data' => $response->getData()];
			} catch (\Throwable $exception) {
				$outcome = ['throws' => get_class($exception), 'message' => $exception->getMessage()];
			}

			$actual[$name] = ['outcome' => $outcome, 'calls' => $this->calls];
		}

		$encoded = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n";
		if (getenv('MAPPINGS_TEST_GOLDEN') === '1') {
			if (is_dir(dirname(self::GOLDEN)) === false) {
				mkdir(dirname(self::GOLDEN), 0777, true);
			}

			file_put_contents(self::GOLDEN, $encoded);
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), json_decode($encoded, true));
	}//end testEveryCaseMatchesTheGoldenFile()
}//end class

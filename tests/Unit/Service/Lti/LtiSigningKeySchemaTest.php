<?php

/**
 * The signing-key entries LtiKeyService writes must pass the register schema.
 *
 * integriq#2261: generateKey() wrote `rotatedAt: null`, which the lti_tool and
 * lti_platform schemas do not allow (`{"type": "string", "format":
 * "date-time"}`), so OpenRegister refused every key save on a live instance
 * while LtiKeyServiceTest stayed green: it never looked at the schema. This
 * test validates what the service hands to saveObject() against the REAL
 * schema, built the way InitializeRegister builds it at install time (the
 * base register merged with every register.d fragment through
 * InitializeRegister::deepMergeConfig()), using opis/json-schema, the
 * validator OpenRegister itself uses.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Lti
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/lti-13-platform/specs/lti-platform/spec.md#requirement-own-signing-key-lifecycle-with-rotation-and-a-per-registration-jwks-publish-endpoint-req-lti-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Lti;

use OCA\Integriq\Repair\InitializeRegister;
use OCA\Integriq\Service\Lti\LtiKeyService;
use OCA\Integriq\Tests\Unit\Service\Lti\Support\AesTestCrypto;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * Validates generated and rotated key entries against the merged register schema.
 */
class LtiSigningKeySchemaTest extends TestCase {

	/**
	 * What LtiKeyService last handed to saveObject(), keyed by uuid.
	 *
	 * @var array<string, array>
	 */
	private array $saved = [];

	/**
	 * Registrations the ObjectService double serves, keyed by uuid.
	 *
	 * @var array<string, array>
	 */
	private array $registrations = [];

	/**
	 * A minimal valid registration per schema, so the whole object validates.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const SEEDS = [
		'lti_tool'     => [
			'clientId'  => 'schema-test-tool',
			'name'      => 'Schema test tool',
			'launchUrl' => 'https://tool.example.org/launch',
		],
		'lti_platform' => [
			'issuer'   => 'https://platform.example.org',
			'clientId' => 'schema-test-platform',
		],
	];

	/**
	 * The register as InitializeRegister imports it: base plus every fragment,
	 * merged in sorted order by the repair step's own deepMergeConfig().
	 *
	 * @return array<string, mixed>
	 */
	private static function mergedRegister(): array {
		$root = dirname(__DIR__, 4);
		$descriptor = json_decode((string)file_get_contents($root . '/lib/Settings/integriq_register.json'), true, flags: JSON_THROW_ON_ERROR);

		$merge = new ReflectionMethod(InitializeRegister::class, 'deepMergeConfig');
		$fragments = glob($root . '/lib/Settings/register.d/*.json');
		sort($fragments);
		foreach ($fragments as $fragmentPath) {
			$fragment = json_decode((string)file_get_contents($fragmentPath), true);
			if (is_array($fragment) === false) {
				continue;
			}

			$descriptor = $merge->invoke(null, $descriptor, $fragment);
		}

		return $descriptor;
	}//end mergedRegister()

	/**
	 * Build an LtiKeyService whose ObjectService records every save.
	 *
	 * @return LtiKeyService
	 */
	private function makeService(): LtiKeyService {
		$objectService = $this->createMock(ObjectService::class);

		$objectService->method('find')->willReturnCallback(
			function ($id) {
				if (isset($this->registrations[$id]) === false) {
					throw new \OCP\AppFramework\Db\DoesNotExistException('not found');
				}

				$entity = new ObjectEntity();
				$entity->setUuid($id);
				$entity->setObject($this->registrations[$id]);
				return $entity;
			}
		);

		$objectService->method('saveObject')->willReturnCallback(
			function ($object = [], $register = null, $schema = null, $uuid = null) {
				$this->saved[$uuid] = $object;
				$this->registrations[$uuid] = $object;

				$entity = new ObjectEntity();
				$entity->setUuid($uuid);
				$entity->setObject($object);
				return $entity;
			}
		);

		return new LtiKeyService($objectService, new NullLogger(), new AesTestCrypto());
	}//end makeService()

	/**
	 * Validate an object against one schema of the merged register.
	 *
	 * @param string $schemaSlug The schema slug.
	 * @param array $object The object as handed to saveObject().
	 *
	 * @return array<string, mixed> Formatted errors, empty when valid.
	 */
	private static function schemaErrors(string $schemaSlug, array $object): array {
		$schema = self::mergedRegister()['components']['schemas'][$schemaSlug];
		unset($object['@schema']);

		$result = (new Validator())->validate(
			json_decode(json_encode($object, JSON_THROW_ON_ERROR)),
			json_encode($schema, JSON_THROW_ON_ERROR)
		);
		if ($result->isValid() === true) {
			return [];
		}

		return (new ErrorFormatter())->format($result->error());
	}//end schemaErrors()

	/**
	 * Registration types covered.
	 *
	 * @return array<string, array{string}>
	 */
	public static function registrationTypes(): array {
		return [
			'lti_tool'     => ['lti_tool'],
			'lti_platform' => ['lti_platform'],
		];
	}//end registrationTypes()

	/**
	 * A freshly generated key saves as a valid registration.
	 *
	 * @param string $type The registration schema.
	 *
	 * @return void
	 *
	 * @dataProvider registrationTypes
	 */
	public function testGeneratedKeyValidatesAgainstRegisterSchema(string $type): void {
		$this->registrations['reg-1'] = array_merge(['@schema' => $type, 'signingKeys' => []], self::SEEDS[$type]);

		$this->makeService()->generateKey($type, 'reg-1', 'RS256');

		$this->assertArrayHasKey('reg-1', $this->saved, 'generateKey() saved nothing');
		$this->assertSame([], self::schemaErrors(schemaSlug: $type, object: $this->saved['reg-1']));
	}//end testGeneratedKeyValidatesAgainstRegisterSchema()

	/**
	 * After a rotation both the previous and the new active entry validate.
	 *
	 * @param string $type The registration schema.
	 *
	 * @return void
	 *
	 * @dataProvider registrationTypes
	 */
	public function testRotatedKeysValidateAgainstRegisterSchema(string $type): void {
		$this->registrations['reg-2'] = array_merge(['@schema' => $type, 'signingKeys' => []], self::SEEDS[$type]);
		$service = $this->makeService();

		$service->generateKey($type, 'reg-2', 'RS256');
		$service->rotateKey($type, 'reg-2');

		$keys = $this->saved['reg-2']['signingKeys'];
		$this->assertCount(2, $keys);
		$this->assertSame([], self::schemaErrors(schemaSlug: $type, object: $this->saved['reg-2']));
	}//end testRotatedKeysValidateAgainstRegisterSchema()

	/**
	 * Control: the validator does see a null rotatedAt, so the green above means something.
	 *
	 * @param string $type The registration schema.
	 *
	 * @return void
	 *
	 * @dataProvider registrationTypes
	 */
	public function testSchemaRefusesANullRotatedAt(string $type): void {
		$object = self::SEEDS[$type];
		$object['signingKeys'] = [['kid' => 'k', 'algorithm' => 'RS256', 'publicJwk' => ['kty' => 'RSA'], 'status' => 'active', 'rotatedAt' => null]];

		$this->assertNotSame([], self::schemaErrors(schemaSlug: $type, object: $object));
	}//end testSchemaRefusesANullRotatedAt()
}//end class

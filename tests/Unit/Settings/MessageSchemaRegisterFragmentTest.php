<?php

/**
 * Contract tests for the message schema register fragment and its page.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-a-message-schema-is-stored-once-and-referenced-req-msv-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use OCA\Integriq\Repair\InitializeRegister;
use OCA\Integriq\Service\MessageValidation\JsonSchemaChecker;
use OCA\Integriq\Service\MessageValidation\OpenApiChecker;
use OCA\Integriq\Service\MessageValidation\XsdChecker;
use OCA\Integriq\Service\MessageValidationService;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The `message_schema` schema is declared by its own fragment, closed to
 * administrators, seeded with two example schemas that pass the real schema
 * and the real checkers, and listed on its own page.
 */
class MessageSchemaRegisterFragmentTest extends TestCase {

	/**
	 * The fragment file this change adds.
	 */
	private const FRAGMENT = '/lib/Settings/register.d/mapping-message-schema-validation.json';

	/**
	 * The manifest fragment this change adds.
	 */
	private const MANIFEST = '/src/manifest.d/mapping-message-schema-validation.json';

	/**
	 * The repository root.
	 *
	 * @return string
	 */
	private static function root(): string {
		return dirname(__DIR__, 3);
	}//end root()

	/**
	 * Decode a JSON file under the repository root.
	 *
	 * @param string $relative The path from the root.
	 *
	 * @return array<string, mixed>
	 */
	private static function json(string $relative): array {
		$path = self::root() . $relative;
		self::assertFileExists($path);
		return json_decode((string)file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
	}//end json()

	/**
	 * The descriptor with every fragment merged in, the way InitializeRegister does.
	 *
	 * @return array<string, mixed>
	 */
	private static function mergedRegister(): array {
		$descriptor = self::json('/lib/Settings/integriq_register.json');
		$merge = new ReflectionMethod(InitializeRegister::class, 'deepMergeConfig');
		$fragments = glob(self::root() . '/lib/Settings/register.d/*.json');
		sort($fragments);
		foreach ($fragments as $fragmentPath) {
			$fragment = json_decode((string)file_get_contents($fragmentPath), true);
			if (is_array($fragment) === true) {
				$descriptor = $merge->invoke(null, $descriptor, $fragment);
			}
		}

		return $descriptor;
	}//end mergedRegister()

	/**
	 * The seeded message schemas, keyed by slug, without their `@self`.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function seeds(): array {
		$seeds = [];
		foreach (self::json(self::FRAGMENT)['components']['objects'] ?? [] as $object) {
			self::assertSame('integriq', $object['@self']['register']);
			self::assertSame('message_schema', $object['@self']['schema']);
			$slug = $object['@self']['slug'];
			unset($object['@self']);
			$seeds[$slug] = $object;
		}

		return $seeds;
	}//end seeds()

	/**
	 * The service with its three real checkers.
	 *
	 * @return MessageValidationService
	 */
	private static function service(): MessageValidationService {
		$json = new JsonSchemaChecker();
		return new MessageValidationService($json, new XsdChecker(), new OpenApiChecker($json));
	}//end service()

	/**
	 * The schema carries a slug, its four kinds, and joins the integriq register.
	 *
	 * @return void
	 */
	public function testTheFragmentDeclaresMessageSchemaOnTheRegister(): void {
		$fragment = self::json(self::FRAGMENT);
		$schema = $fragment['components']['schemas']['message_schema'];

		$this->assertSame('message_schema', $schema['slug']);
		$this->assertSame(['json-schema', 'xsd', 'openapi', 'register-schema'], $schema['properties']['kind']['enum']);
		$this->assertSame(['name', 'kind', 'version'], $schema['required']);
		foreach (['name', 'kind', 'document', 'registerSchema', 'version', 'description'] as $property) {
			$this->assertArrayHasKey($property, $schema['properties'], $property);
		}

		$merged = self::mergedRegister();
		$this->assertContains('message_schema', $merged['components']['registers']['integriq']['schemas']);
		$this->assertArrayHasKey('message_schema', $merged['components']['schemas']);
	}//end testTheFragmentDeclaresMessageSchemaOnTheRegister()

	/**
	 * A message schema is integration trust config: every verb is admin-only.
	 *
	 * @return void
	 */
	public function testOnlyAnAdministratorReadsOrWritesAMessageSchema(): void {
		$schema = self::mergedRegister()['components']['schemas']['message_schema'];

		$this->assertSame(
			['create' => ['admin'], 'read' => ['admin'], 'update' => ['admin'], 'delete' => ['admin']],
			$schema['authorization']
		);
	}//end testOnlyAnAdministratorReadsOrWritesAMessageSchema()

	/**
	 * Both seeds pass the real merged schema with the validator OpenRegister uses.
	 *
	 * @return void
	 */
	public function testTheTwoSeedsPassTheRealSchema(): void {
		$seeds = self::seeds();
		$this->assertSame(['example-person-json', 'example-person-xsd'], array_keys($seeds));

		$schema = self::mergedRegister()['components']['schemas']['message_schema'];
		foreach ($seeds as $slug => $object) {
			$result = (new Validator())->validate(
				json_decode(json_encode($object, JSON_THROW_ON_ERROR)),
				json_encode($schema, JSON_THROW_ON_ERROR)
			);
			$errors = [];
			if ($result->isValid() === false) {
				$errors = (new ErrorFormatter())->format($result->error());
			}

			$this->assertSame([], $errors, $slug);
		}
	}//end testTheTwoSeedsPassTheRealSchema()

	/**
	 * The seeds describe the same person: a good one passes, one without a bsn fails at /bsn.
	 *
	 * @return void
	 */
	public function testTheSeedsCheckTheSamePersonWithTheRealCheckers(): void {
		$seeds = self::seeds();
		$service = self::service();

		$this->assertSame('json-schema', $seeds['example-person-json']['kind']);
		$this->assertSame('xsd', $seeds['example-person-xsd']['kind']);
		$this->assertNull($service->documentProblem($seeds['example-person-json']));
		$this->assertNull($service->documentProblem($seeds['example-person-xsd']));

		$good = json_decode('{"bsn":"999993653","geslachtsnaam":"Jansen","geboortedatum":"1980-05-17"}');
		$this->assertTrue($service->validate($seeds['example-person-json'], $good)->isValid());
		$missing = json_decode('{"geslachtsnaam":"Jansen"}');
		$this->assertContains('/bsn', $service->validate($seeds['example-person-json'], $missing)->paths());

		$xml = '<persoon xmlns="https://integriq.conduction.nl/example/person"><bsn>999993653</bsn>'
			. '<geslachtsnaam>Jansen</geslachtsnaam><geboortedatum>1980-05-17</geboortedatum></persoon>';
		$this->assertTrue($service->validate($seeds['example-person-xsd'], $xml)->isValid());
		$noBsn = '<persoon xmlns="https://integriq.conduction.nl/example/person"><geslachtsnaam>Jansen</geslachtsnaam></persoon>';
		$this->assertFalse($service->validate($seeds['example-person-xsd'], $noBsn)->isValid());
	}//end testTheSeedsCheckTheSamePersonWithTheRealCheckers()

	/**
	 * The demo register carries three message schemas (ADR-111 rule 1), each valid and parseable.
	 *
	 * @return void
	 */
	public function testTheDemoRegisterCarriesThreeParseableMessageSchemas(): void {
		$demo = array_values(
			array_filter(
				self::json('/lib/Settings/integriq_mock_register.json')['components']['objects'],
				static fn (array $object): bool => ($object['@self']['schema'] ?? '') === 'message_schema'
			)
		);
		$this->assertCount(3, $demo);

		$schema = self::mergedRegister()['components']['schemas']['message_schema'];
		$kinds = [];
		foreach ($demo as $object) {
			unset($object['@self']);
			$result = (new Validator())->validate(
				json_decode(json_encode($object, JSON_THROW_ON_ERROR)),
				json_encode($schema, JSON_THROW_ON_ERROR)
			);
			$this->assertTrue($result->isValid(), (string)$object['name']);
			$this->assertNull(self::service()->documentProblem($object), (string)$object['name']);
			$kinds[] = $object['kind'];
		}

		$this->assertSame(['json-schema', 'xsd', 'openapi'], $kinds);
	}//end testTheDemoRegisterCarriesThreeParseableMessageSchemas()

	/**
	 * The page lists message schemas for administrators, under Automation next to Mappings.
	 *
	 * @return void
	 */
	public function testTheMessageSchemasPageListsTheSchema(): void {
		$manifest = self::json(self::MANIFEST);
		$this->assertCount(1, $manifest['pages']);
		$page = $manifest['pages'][0];

		$this->assertSame('MessageSchemas', $page['id']);
		$this->assertSame('index', $page['type']);
		$this->assertSame('admin', $page['permission']);
		$this->assertSame('integriq', $page['config']['register']);
		$this->assertSame('message_schema', $page['config']['schema']);
		$this->assertSame(['name', 'kind', 'version', 'dateModified'], $page['config']['columns']);

		$this->assertSame('AutomationGroup', $manifest['menu'][0]['id']);
		$this->assertSame('MessageSchemas', $manifest['menu'][0]['children'][0]['route']);
	}//end testTheMessageSchemasPageListsTheSchema()
}//end class

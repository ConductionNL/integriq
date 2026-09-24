<?php

/**
 * Unit tests for ConnectionDeclarationValidator (connection-registry, umbrella D2).
 *
 * Every fixture runs through the hand-written validator AND through
 * lib/Settings/connections.schema.json with opis/json-schema, and the two must
 * agree. That is the guard against the runtime validator and the published
 * schema drifting apart.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-the-sync-turns-declaration-files-into-connection-rows-req-conn-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\ConnectionDeclarationValidator;
use Opis\JsonSchema\Validator as OpisValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Validator and JSON Schema agree on valid and invalid files.
 */
class ConnectionDeclarationValidatorTest extends TestCase {

	/**
	 * A valid file shaped like dossiq's declaration.
	 *
	 * @return array<string,mixed>
	 */
	private static function validFile(): array {
		return [
			'app' => 'dossiq',
			'connections' => [
				[
					'key' => 'zgw',
					'title' => 'ZGW APIs',
					'description' => 'Zaken and Documenten.',
					'order' => 10,
					'settingsUrl' => '/settings/admin/dossiq#section-zgw',
					'requiredConfig' => ['register', 'case_schema'],
				],
				[
					'key' => 'berichtenbox',
					'title' => 'Berichtenbox',
					'adapter' => ['configKey' => 'berichtenbox_adapter', 'simulatedMessage' => 'A mock answers.'],
					'sourceTemplate' => 'berichtenbox',
					'unconfiguredMessage' => 'Set berichtenbox_adapter first.',
				],
				[
					'key' => 'kvk',
					'title' => 'KvK',
					'available' => false,
					'unavailableMessage' => 'Not called yet.',
				],
			],
		];
	}//end validFile()

	/**
	 * The valid file with the berichtenbox adapter replaced.
	 *
	 * @param array<string,mixed> $adapter The adapter object.
	 *
	 * @return array<string,mixed>
	 */
	private static function withAdapter(array $adapter): array {
		$file = self::validFile();
		$file['connections'][1]['adapter'] = $adapter;

		return $file;
	}//end withAdapter()

	/**
	 * The valid file with the zgw requiredConfig replaced.
	 *
	 * @param mixed $required The requiredConfig value.
	 *
	 * @return array<string,mixed>
	 */
	private static function withRequired(mixed $required): array {
		$file = self::validFile();
		$file['connections'][0]['requiredConfig'] = $required;

		return $file;
	}//end withRequired()

	/**
	 * The valid file with a switch and, optionally, other fields on the zgw entry.
	 *
	 * @param mixed $switch The switch value.
	 * @param array<string,mixed> $extra Other entry fields to set.
	 *
	 * @return array<string,mixed>
	 */
	private static function withSwitch(mixed $switch, array $extra = []): array {
		$file = self::validFile();
		$file['connections'][0] = array_merge($file['connections'][0], ['switch' => $switch], $extra);

		return $file;
	}//end withSwitch()

	/**
	 * Fixtures: name => [data, expected valid].
	 *
	 * @return array<string,array{0:mixed,1:bool}>
	 */
	public static function fixtures(): array {
		$valid = self::validFile();

		$noTitle = $valid;
		unset($noTitle['connections'][0]['title']);

		$badKey = $valid;
		$badKey['connections'][0]['key'] = 'Bad_Key';

		$extraField = $valid;
		$extraField['connections'][1]['settingUrl'] = '/typo';

		$relativeUrl = $valid;
		$relativeUrl['connections'][0]['settingsUrl'] = 'settings/admin';

		$stringOrder = $valid;
		$stringOrder['connections'][0]['order'] = '10';

		$badRequired = $valid;
		$badRequired['connections'][0]['requiredConfig'] = ['register', ''];

		$badAdapter = $valid;
		$badAdapter['connections'][1]['adapter'] = ['configKey' => 'x', 'className' => 'y'];

		$noApp = $valid;
		unset($noApp['app']);

		$badUnconfigured = $valid;
		$badUnconfigured['connections'][1]['unconfiguredMessage'] = ['not', 'a', 'string'];

		$badAvailable = $valid;
		$badAvailable['connections'][2]['available'] = 'no';

		$amended = $valid;
		$amended['connections'][1]['adapter'] = [
			'configKey' => 'llm',
			'jsonPath' => 'chat.provider',
			'simulatedValues' => ['', 'none', 'null'],
		];
		$amended['connections'][0]['reportedOnly'] = true;

		$badJsonPath = $valid;
		$badJsonPath['connections'][1]['adapter']['jsonPath'] = 'chat..provider';

		$leadingDotPath = $valid;
		$leadingDotPath['connections'][1]['adapter']['jsonPath'] = '.provider';

		$emptyJsonPath = $valid;
		$emptyJsonPath['connections'][1]['adapter']['jsonPath'] = '';

		$simulatedNotList = $valid;
		$simulatedNotList['connections'][1]['adapter']['simulatedValues'] = 'none';

		$simulatedNotStrings = $valid;
		$simulatedNotStrings['connections'][1]['adapter']['simulatedValues'] = ['none', 0];

		$badReportedOnly = $valid;
		$badReportedOnly['connections'][0]['reportedOnly'] = 'yes';

		$jsonEntry = ['configKey' => 'eolSync', 'jsonPath' => 'sync.enabled'];

		return [
			'valid file' => [$valid, true],
			'empty connections' => [['app' => 'dossiq', 'connections' => []], true],
			'with $schema pointer' => [['$schema' => './connections.schema.json', 'app' => 'dossiq', 'connections' => []], true],
			'entry without title' => [$noTitle, false],
			'key not kebab case' => [$badKey, false],
			'unknown entry field' => [$extraField, false],
			'relative settingsUrl' => [$relativeUrl, false],
			'order as string' => [$stringOrder, false],
			'empty required key' => [$badRequired, false],
			'unknown adapter field' => [$badAdapter, false],
			'no app' => [$noApp, false],
			'available not boolean' => [$badAvailable, false],
			'unconfiguredMessage not a string' => [$badUnconfigured, false],
			'list instead of object' => [[1, 2], false],
			'jsonPath, simulatedValues and reportedOnly' => [$amended, true],
			'empty simulatedValues list' => [self::withAdapter(adapter: ['configKey' => 'x', 'simulatedValues' => []]), true],
			'jsonPath with an empty segment' => [$badJsonPath, false],
			'jsonPath with a leading dot' => [$leadingDotPath, false],
			'empty jsonPath' => [$emptyJsonPath, false],
			'simulatedValues not a list' => [$simulatedNotList, false],
			'simulatedValues holding a number' => [$simulatedNotStrings, false],
			'reportedOnly not boolean' => [$badReportedOnly, false],
			'requiredConfig with a {configKey, jsonPath} entry' => [self::withRequired(required: ['register', $jsonEntry]), true],
			'requiredConfig with a dotted key' => [self::withRequired(required: ['integration.brp.mode']), true],
			'required object without jsonPath' => [self::withRequired(required: [['configKey' => 'eolSync']]), false],
			'required object without configKey' => [self::withRequired(required: [['jsonPath' => 'enabled']]), false],
			'required object with an extra field' => [self::withRequired(required: [$jsonEntry + ['simulatedValues' => []]]), false],
			'required object with an empty configKey' => [self::withRequired(required: [['configKey' => '', 'jsonPath' => 'enabled']]), false],
			'required object with an empty path segment' => [self::withRequired(required: [['configKey' => 'eolSync', 'jsonPath' => 'sync..enabled']]), false],
			'required object with a numeric jsonPath' => [self::withRequired(required: [['configKey' => 'eolSync', 'jsonPath' => 1]]), false],
			'required empty object' => [self::withRequired(required: [[]]), false],
			'required number' => [self::withRequired(required: ['register', 5]), false],
			'requiredConfig as an object, not a list' => [self::withRequired(required: $jsonEntry), false],
			'switch with only configKey' => [self::withSwitch(switch: ['configKey' => 'breach_check_enabled']), true],
			'switch with jsonPath, offValues and a disabledMessage' => [
				self::withSwitch(
					switch: ['configKey' => 'traffic', 'jsonPath' => 'geo.provider', 'offValues' => ['none', '']],
					extra: ['disabledMessage' => 'Geography is off.']
				),
				true,
			],
			'switch with an empty offValues list' => [self::withSwitch(switch: ['configKey' => 'x', 'offValues' => []]), true],
			'switch without configKey' => [self::withSwitch(switch: ['offValues' => ['none']]), false],
			'switch as an empty object' => [self::withSwitch(switch: []), false],
			'switch with an unknown key' => [self::withSwitch(switch: ['configKey' => 'x', 'simulatedValues' => ['none']]), false],
			'switch with an empty configKey' => [self::withSwitch(switch: ['configKey' => '']), false],
			'switch with an empty path segment' => [self::withSwitch(switch: ['configKey' => 'x', 'jsonPath' => 'geo..provider']), false],
			'switch offValues not a list' => [self::withSwitch(switch: ['configKey' => 'x', 'offValues' => 'none']), false],
			'switch offValues holding a boolean' => [self::withSwitch(switch: ['configKey' => 'x', 'offValues' => [false]]), false],
			'switch as a string' => [self::withSwitch(switch: 'breach_check_enabled'), false],
			'disabledMessage not a string' => [self::withSwitch(switch: ['configKey' => 'x'], extra: ['disabledMessage' => false]), false],
		];
	}//end fixtures()

	/**
	 * The validator gives the expected verdict, and the schema agrees.
	 *
	 * @param mixed $data The decoded file.
	 * @param bool $expected Whether the file is valid.
	 *
	 * @return void
	 */
	#[DataProvider('fixtures')]
	public function testValidatorAndSchemaAgree(mixed $data, bool $expected): void {
		$errors = (new ConnectionDeclarationValidator())->validate($data);
		$this->assertSame(expected: $expected, actual: $errors === [], message: 'Validator: ' . implode('; ', $errors));

		$schema = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/connections.schema.json'));
		$result = (new OpisValidator())->validate(json_decode((string)json_encode($data)), $schema);
		$this->assertSame(expected: $expected, actual: $result->isValid(), message: 'JSON Schema disagrees with the validator');
	}//end testValidatorAndSchemaAgree()

	/**
	 * The error names the failing path.
	 *
	 * @return void
	 */
	public function testErrorNamesTheFailingPath(): void {
		$data = self::validFile();
		unset($data['connections'][1]['title']);

		$errors = (new ConnectionDeclarationValidator())->validate($data);

		$this->assertSame(expected: ['/connections/1/title: is required'], actual: $errors);
	}//end testErrorNamesTheFailingPath()

	/**
	 * A bad requiredConfig entry names the requiredConfig path.
	 *
	 * @return void
	 */
	public function testBadRequiredEntryNamesThePath(): void {
		$errors = (new ConnectionDeclarationValidator())->validate(self::withRequired(required: [['configKey' => 'eolSync']]));

		$this->assertSame(
			expected: ['/connections/0/requiredConfig: must be an array of non-empty strings or {configKey, jsonPath} objects'],
			actual: $errors
		);
	}//end testBadRequiredEntryNamesThePath()

	/**
	 * A switch missing configKey or carrying an unknown key names the switch path.
	 *
	 * @return void
	 */
	public function testBadSwitchNamesThePath(): void {
		$validator = new ConnectionDeclarationValidator();

		$this->assertSame(
			expected: ['/connections/0/switch/configKey: is required'],
			actual: $validator->validate(self::withSwitch(switch: ['offValues' => ['none']]))
		);
		$this->assertSame(
			expected: ['/connections/0/switch/className: is not allowed'],
			actual: $validator->validate(self::withSwitch(switch: ['configKey' => 'x', 'className' => 'y']))
		);
	}//end testBadSwitchNamesThePath()

	/**
	 * A duplicate key is refused, which JSON Schema cannot express.
	 *
	 * @return void
	 */
	public function testDuplicateKeyIsRefused(): void {
		$data = self::validFile();
		$data['connections'][2]['key'] = 'zgw';

		$errors = (new ConnectionDeclarationValidator())->validate($data);

		$this->assertSame(expected: ['/connections/2/key: duplicates key "zgw"'], actual: $errors);
	}//end testDuplicateKeyIsRefused()
}//end class

<?php

/**
 * Unit tests for the RemoveMigratedSourceSecretFields repair step (Phase D / ocon#151).
 *
 * These assert the SAFETY CONTRACT of the irreversible schema mutation, not a
 * double's return value:
 *   - removal fires ONLY when all four auto-migratable fields are clean fleet-wide;
 *   - a single source with an inline apikey BLOCKS removal (the safety test);
 *   - `authenticationConfig` never blocks the four AND is never removed;
 *   - idempotency: a second run is a no-op; already-absent fields are skipped;
 *   - a MUTATION that bypasses the clean gate removes fields on dirty data — this
 *     test exists to FAIL if the gate is ever weakened;
 *   - no secret ever reaches the logger;
 *   - the register JSON still declares all five fields (the catastrophic-trap guard).
 *
 * Sources are read through the SAME render-boundary double the planner tests use,
 * so the gate is exercised through the real raw-read path.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-phase-d-remove-migrated-fields
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Repair;

use OCA\OpenConnector\Repair\RemoveMigratedSourceSecretFields;
use OCA\OpenConnector\Tests\Helpers\RenderBoundarySimulatingObjectService;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

/**
 * A minimal in-memory IAppConfig capturing setValueString writes.
 */
class RemoveStepAppConfig implements IAppConfig {

	/**
	 * Captured key => value writes.
	 *
	 * @var array<string, string>
	 */
	public array $written = [];

	// phpcs:disable
	public function setValueString(string $app, string $key, string $value, bool $lazy = false, bool $sensitive = false): bool {
		$this->written[$key] = $value;
		return true;
	}
	public function getApps(): array {
		return [];
	}
	public function getKeys(string $app): array {
		return [];
	}
	public function hasKey(string $app, string $key, ?bool $lazy = false): bool {
		return isset($this->written[$key]);
	}
	public function isSensitive(string $app, string $key, ?bool $lazy = false): bool {
		return false;
	}
	public function isLazy(string $app, string $key): bool {
		return false;
	}
	public function getAllValues(string $app, string $prefix = '', bool $filtered = false): array {
		return [];
	}
	public function getValues($app, $key) {
		return [];
	}
	public function getFilteredValues($app) {
		return [];
	}
	public function searchValues(string $key, bool $lazy = false, ?int $typedAs = null): array {
		return [];
	}
	public function getValueString(string $app, string $key, string $default = '', bool $lazy = false): string {
		return ($this->written[$key] ?? $default);
	}
	public function getValueInt(string $app, string $key, int $default = 0, bool $lazy = false): int {
		return $default;
	}
	public function getValueFloat(string $app, string $key, float $default = 0, bool $lazy = false): float {
		return $default;
	}
	public function getValueBool(string $app, string $key, bool $default = false, bool $lazy = false): bool {
		return $default;
	}
	public function getValueArray(string $app, string $key, array $default = [], bool $lazy = false): array {
		return $default;
	}
	public function getValueType(string $app, string $key, ?bool $lazy = null): int {
		return 0;
	}
	public function setValueInt(string $app, string $key, int $value, bool $lazy = false, bool $sensitive = false): bool {
		return true;
	}
	public function setValueFloat(string $app, string $key, float $value, bool $lazy = false, bool $sensitive = false): bool {
		return true;
	}
	public function setValueBool(string $app, string $key, bool $value, bool $lazy = false): bool {
		return true;
	}
	public function setValueArray(string $app, string $key, array $value, bool $lazy = false, bool $sensitive = false): bool {
		return true;
	}
	public function updateSensitive(string $app, string $key, bool $sensitive): bool {
		return true;
	}
	public function updateLazy(string $app, string $key, bool $lazy): bool {
		return true;
	}
	public function getDetails(string $app, string $key): array {
		return [];
	}
	public function convertTypeToString(int $type): string {
		return '';
	}
	public function convertTypeToInt(string $type): int {
		return 0;
	}
	public function deleteKey(string $app, string $key): void {
	}
	public function deleteApp(string $app): void {
	}
	public function clearCache(bool $reload = false): void {
	}
	// Added when NC34's IAppConfig grew these three; without them this double is
	// abstract and the whole Unit suite dies at load with a fatal error.
	public function searchKeys(string $app, string $prefix = '', bool $lazy = false): array {
		return [];
	}
	public function getKeyDetails(string $app, string $key): array {
		return [];
	}
	public function getAppInstalledVersions(bool $onlyEnabled = false): array {
		return [];
	}
	// phpcs:enable
}//end class

/**
 * A stand-in for OpenRegister's Schema — just the property/version bag this step touches.
 */
class FakeSourceSchema {

	/**
	 * The schema's properties bag.
	 *
	 * @var array<string, mixed>
	 */
	public array $properties;

	/**
	 * The schema's version string.
	 *
	 * @var string
	 */
	public string $version;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $properties Initial properties.
	 * @param string $version Initial version.
	 */
	public function __construct(array $properties, string $version = '1.2.0') {
		$this->properties = $properties;
		$this->version = $version;
	}//end __construct()

	/**
	 * @return array<string, mixed>
	 */
	public function getProperties(): array {
		return $this->properties;
	}//end getProperties()

	/**
	 * @param array<string, mixed> $properties The new properties.
	 *
	 * @return void
	 */
	public function setProperties(array $properties): void {
		$this->properties = $properties;
	}//end setProperties()

	/**
	 * @return string
	 */
	public function getVersion(): string {
		return $this->version;
	}//end getVersion()

	/**
	 * @param string $version The new version.
	 *
	 * @return void
	 */
	public function setVersion(string $version): void {
		$this->version = $version;
	}//end setVersion()
}//end class

/**
 * A SchemaMapper double: find() returns the FakeSourceSchema and update() records.
 */
class RecordingSchemaMapper {

	/**
	 * The single source schema this mapper serves.
	 *
	 * @var FakeSourceSchema
	 */
	public FakeSourceSchema $schema;

	/**
	 * Whether update() was called.
	 *
	 * @var boolean
	 */
	public bool $updated = false;

	/**
	 * When true, find() throws (simulating a broken read).
	 *
	 * @var boolean
	 */
	public bool $findThrows = false;

	/**
	 * Constructor.
	 *
	 * @param FakeSourceSchema $schema The schema to serve.
	 */
	public function __construct(FakeSourceSchema $schema) {
		$this->schema = $schema;
	}//end __construct()

	/**
	 * Return the source schema by slug.
	 *
	 * @param string|int $id The slug or id.
	 *
	 * @return FakeSourceSchema
	 */
	public function find($id): FakeSourceSchema {
		if ($this->findThrows === true) {
			throw new \RuntimeException('schema read boom');
		}

		return $this->schema;
	}//end find()

	/**
	 * Record an update.
	 *
	 * @param object $schema The schema to persist.
	 *
	 * @return object
	 */
	public function update(object $schema): object {
		$this->updated = true;
		return $schema;
	}//end update()
}//end class

/**
 * A logger that flattens every message + context so a test can scan for secrets.
 */
class RemoveStepSpyLogger extends AbstractLogger {

	/**
	 * @var array<int, string>
	 */
	public array $lines = [];

	/**
	 * @param mixed $level Level.
	 * @param mixed $message Message.
	 * @param array $context Context.
	 *
	 * @return void
	 */
	public function log($level, $message, array $context = []): void {
		$this->lines[] = (string)$message . ' ' . json_encode($context);
	}//end log()
}//end class

/**
 * A step subclass that FORCES the clean gate — used only by the mutation test to
 * prove the gate is what protects a dirty fleet.
 */
class GateBypassingRemoveStep extends RemoveMigratedSourceSecretFields {

	/**
	 * @return void
	 */
	public function run(IOutput $output): void {
		// Bypass isAutoMigratableClean() entirely and remove regardless. This is the
		// exact defect the safety gate prevents; the mutation test asserts it fires.
		$reflection = new \ReflectionMethod(RemoveMigratedSourceSecretFields::class, 'removeFieldsWhenClean');
		$reflection->setAccessible(true);
		$reflection->invoke($this, $output);
	}//end run()
}//end class

/**
 * @covers \OCA\OpenConnector\Repair\RemoveMigratedSourceSecretFields
 */
class RemoveMigratedSourceSecretFieldsTest extends TestCase {

	private const FIVE_FIELD_PROPS = [
		'name' => ['type' => 'string'],
		'apikey' => ['type' => 'string', 'writeOnly' => true],
		'secret' => ['type' => 'string', 'writeOnly' => true],
		'password' => ['type' => 'string', 'writeOnly' => true],
		'jwt' => ['type' => 'string', 'writeOnly' => true],
		'authenticationConfig' => ['type' => 'object', 'writeOnly' => true],
	];

	/**
	 * Build the step, wiring the render-boundary object service and a schema mapper.
	 *
	 * @param array<string, array<string, mixed>> $stored uuid => raw source data.
	 * @param FakeSourceSchema $schema The live source schema double.
	 * @param RemoveStepAppConfig $appConfig The recording appconfig.
	 * @param RecordingSchemaMapper|null $schemaMapper Optional pre-built mapper.
	 * @param \Psr\Log\LoggerInterface|null $logger Optional logger.
	 *
	 * @return array{step: RemoveMigratedSourceSecretFields, mapper: RecordingSchemaMapper}
	 */
	private function makeStep(
		array $stored,
		FakeSourceSchema $schema,
		RemoveStepAppConfig $appConfig,
		?RecordingSchemaMapper $schemaMapper = null,
		?\Psr\Log\LoggerInterface $logger = null,
	): array {
		$objectService = new RenderBoundarySimulatingObjectService();
		$objectService->stored = $stored;

		$mapper = ($schemaMapper ?? new RecordingSchemaMapper($schema));

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objectService, $mapper) {
				if ($id === OrObjectService::class || $id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $objectService;
				}

				if ($id === 'OCA\\OpenRegister\\Db\\SchemaMapper') {
					return $mapper;
				}

				throw new \RuntimeException('unexpected service: ' . $id);
			}
		);

		$step = new RemoveMigratedSourceSecretFields($container, $appConfig, ($logger ?? new NullLogger()));
		return ['step' => $step, 'mapper' => $mapper];
	}//end makeStep()

	/**
	 * A migrated (or empty) fleet: the four fields are removed, authenticationConfig
	 * is retained, and the schema version is bumped.
	 *
	 * @return void
	 */
	public function testRemovesFourFieldsWhenFleetClean(): void {
		$appConfig = new RemoveStepAppConfig();
		$schema = new FakeSourceSchema(self::FIVE_FIELD_PROPS, '1.2.0');
		$stored = ['src-1' => ['name' => 'Migrated', 'apikey' => ['credentialRef' => ['credentialId' => 'abc']]]];

		$built = $this->makeStep($stored, $schema, $appConfig);
		$built['step']->run($this->createMock(IOutput::class));

		$props = $schema->getProperties();
		$this->assertArrayNotHasKey('apikey', $props);
		$this->assertArrayNotHasKey('secret', $props);
		$this->assertArrayNotHasKey('password', $props);
		$this->assertArrayNotHasKey('jwt', $props);
		$this->assertArrayHasKey('authenticationConfig', $props, 'authenticationConfig must be retained (manual review).');
		$this->assertArrayHasKey('name', $props);
		$this->assertSame('1.2.1', $schema->getVersion(), 'The schema version must be bumped on removal.');
		$this->assertTrue($built['mapper']->updated);
		$this->assertSame('1', $appConfig->written[RemoveMigratedSourceSecretFields::KEY_FIELDS_REMOVED]);
	}//end testRemovesFourFieldsWhenFleetClean()

	/**
	 * SAFETY TEST: a single source with an inline apikey BLOCKS all removal — the
	 * schema keeps all five fields and nothing is written.
	 *
	 * @return void
	 */
	public function testDirtyApikeyBlocksRemoval(): void {
		$appConfig = new RemoveStepAppConfig();
		$schema = new FakeSourceSchema(self::FIVE_FIELD_PROPS, '1.2.0');
		$stored = ['src-1' => ['name' => 'Live', 'apikey' => 'STILL-INLINE-SECRET']];

		$built = $this->makeStep($stored, $schema, $appConfig);
		$built['step']->run($this->createMock(IOutput::class));

		$props = $schema->getProperties();
		foreach (['apikey', 'secret', 'password', 'jwt', 'authenticationConfig'] as $field) {
			$this->assertArrayHasKey($field, $props, "$field must NOT be removed while a source holds an inline apikey.");
		}

		$this->assertSame('1.2.0', $schema->getVersion(), 'The version must not change when removal is blocked.');
		$this->assertFalse($built['mapper']->updated, 'update() must NOT be called on a dirty fleet.');
		$this->assertSame('0', $appConfig->written[RemoveMigratedSourceSecretFields::KEY_FIELDS_REMOVED]);
	}//end testDirtyApikeyBlocksRemoval()

	/**
	 * A source holding ONLY an inline authenticationConfig must NOT block the four —
	 * they are removed, and authenticationConfig is retained.
	 *
	 * @return void
	 */
	public function testAuthConfigDoesNotBlockAndIsNeverRemoved(): void {
		$appConfig = new RemoveStepAppConfig();
		$schema = new FakeSourceSchema(self::FIVE_FIELD_PROPS, '1.2.0');
		// Clean of the four (apikey is a migrated ref), but holds an inline auth bag.
		$stored = [
			'src-1' => [
				'name' => 'OAuth',
				'apikey' => ['credentialRef' => ['credentialId' => 'abc']],
				'authenticationConfig' => ['client_secret' => 'shh-manual'],
			],
		];

		$built = $this->makeStep($stored, $schema, $appConfig);
		$built['step']->run($this->createMock(IOutput::class));

		$props = $schema->getProperties();
		$this->assertArrayNotHasKey('apikey', $props);
		$this->assertArrayNotHasKey('secret', $props);
		$this->assertArrayNotHasKey('password', $props);
		$this->assertArrayNotHasKey('jwt', $props);
		$this->assertArrayHasKey('authenticationConfig', $props, 'authenticationConfig is manual-review and must survive.');
		$this->assertTrue($built['mapper']->updated);
		$this->assertSame('1', $appConfig->written[RemoveMigratedSourceSecretFields::KEY_FIELDS_REMOVED]);
	}//end testAuthConfigDoesNotBlockAndIsNeverRemoved()

	/**
	 * Idempotency: a second run over an already-stripped schema is a no-op — update()
	 * is not called and the marker stays '1'.
	 *
	 * @return void
	 */
	public function testIdempotentSecondRunNoOp(): void {
		$appConfig = new RemoveStepAppConfig();
		// Schema already stripped of the four (only name + authenticationConfig left).
		$schema = new FakeSourceSchema(
			['name' => ['type' => 'string'], 'authenticationConfig' => ['type' => 'object', 'writeOnly' => true]],
			'1.2.1'
		);
		$stored = ['src-1' => ['name' => 'Migrated']];

		$built = $this->makeStep($stored, $schema, $appConfig);
		$built['step']->run($this->createMock(IOutput::class));

		$this->assertSame('1.2.1', $schema->getVersion(), 'No re-bump on a no-op run.');
		$this->assertFalse($built['mapper']->updated, 'update() must NOT be called when there is nothing to remove.');
		$this->assertSame('1', $appConfig->written[RemoveMigratedSourceSecretFields::KEY_FIELDS_REMOVED]);
	}//end testIdempotentSecondRunNoOp()

	/**
	 * Partial state: a schema missing apikey but still holding secret/password/jwt is
	 * cleaned of the three present fields (already-absent apikey is skipped).
	 *
	 * @return void
	 */
	public function testAlreadyAbsentFieldsAreSkipped(): void {
		$appConfig = new RemoveStepAppConfig();
		$props = self::FIVE_FIELD_PROPS;
		unset($props['apikey']);
		$schema = new FakeSourceSchema($props, '1.2.0');
		$stored = ['src-1' => ['name' => 'Migrated']];

		$built = $this->makeStep($stored, $schema, $appConfig);
		$built['step']->run($this->createMock(IOutput::class));

		$after = $schema->getProperties();
		$this->assertArrayNotHasKey('secret', $after);
		$this->assertArrayNotHasKey('password', $after);
		$this->assertArrayNotHasKey('jwt', $after);
		$this->assertArrayHasKey('authenticationConfig', $after);
		$this->assertSame('1.2.1', $schema->getVersion());
		$this->assertTrue($built['mapper']->updated);
	}//end testAlreadyAbsentFieldsAreSkipped()

	/**
	 * MUTATION TEST: a step that BYPASSES the clean gate removes the four fields even
	 * though a source still holds an inline apikey. This test proves the gate is the
	 * only thing standing between a dirty fleet and a destructive schema mutation —
	 * if the production `run()` ever drops the gate, the real safety test
	 * ({@see testDirtyApikeyBlocksRemoval}) fails; this asserts the removal path
	 * itself is live so that safety test cannot pass vacuously.
	 *
	 * @return void
	 */
	public function testMutationBypassingGateRemovesOnDirtyFleet(): void {
		$appConfig = new RemoveStepAppConfig();
		$schema = new FakeSourceSchema(self::FIVE_FIELD_PROPS, '1.2.0');
		$stored = ['src-1' => ['name' => 'Live', 'apikey' => 'STILL-INLINE-SECRET']];

		$objectService = new RenderBoundarySimulatingObjectService();
		$objectService->stored = $stored;
		$mapper = new RecordingSchemaMapper($schema);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objectService, $mapper) {
				if ($id === OrObjectService::class || $id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $objectService;
				}

				if ($id === 'OCA\\OpenRegister\\Db\\SchemaMapper') {
					return $mapper;
				}

				throw new \RuntimeException('unexpected service: ' . $id);
			}
		);

		$mutant = new GateBypassingRemoveStep($container, $appConfig, new NullLogger());
		$mutant->run($this->createMock(IOutput::class));

		// The mutant removed fields DESPITE the dirty source — exactly the disaster
		// the gate prevents. If this assertion ever fails, the removal path is dead
		// and the safety test would be passing vacuously.
		$this->assertArrayNotHasKey('apikey', $schema->getProperties());
		$this->assertTrue($mapper->updated);
	}//end testMutationBypassingGateRemovesOnDirtyFleet()

	/**
	 * A schema read failure is never fatal: the step catches, records the gate
	 * closed, and does not throw.
	 *
	 * @return void
	 */
	public function testSchemaReadFailureIsNonFatal(): void {
		$appConfig = new RemoveStepAppConfig();
		$schema = new FakeSourceSchema(self::FIVE_FIELD_PROPS, '1.2.0');
		$mapper = new RecordingSchemaMapper($schema);
		$mapper->findThrows = true;
		$stored = ['src-1' => ['name' => 'Migrated']];

		$built = $this->makeStep($stored, $schema, $appConfig, $mapper);
		$built['step']->run($this->createMock(IOutput::class));

		$this->assertSame('0', $appConfig->written[RemoveMigratedSourceSecretFields::KEY_FIELDS_REMOVED]);
		$this->assertFalse($mapper->updated);
	}//end testSchemaReadFailureIsNonFatal()

	/**
	 * No secret value ever reaches the logger, even on the blocked (dirty) path.
	 *
	 * @return void
	 */
	public function testNoSecretInLogs(): void {
		$logger = new RemoveStepSpyLogger();
		$schema = new FakeSourceSchema(self::FIVE_FIELD_PROPS, '1.2.0');

		// A clean-of-the-four fleet (so the gate passes) whose source still carries a
		// secret in the manual-review auth bag, with a schema read that then FAILS —
		// driving the error-logging path while a secret is in scope.
		$mapper = new RecordingSchemaMapper($schema);
		$mapper->findThrows = true;
		$stored = [
			'src-1' => [
				'name' => 'Live',
				'apikey' => ['credentialRef' => ['credentialId' => 'abc']],
				'authenticationConfig' => ['client_secret' => 'super-secret-DO-NOT-LEAK'],
			],
		];

		$built = $this->makeStep($stored, $schema, new RemoveStepAppConfig(), $mapper, $logger);
		$built['step']->run($this->createMock(IOutput::class));

		$joined = implode("\n", $logger->lines);
		$this->assertStringNotContainsString('super-secret-DO-NOT-LEAK', $joined, 'A secret leaked into the logs.');
	}//end testNoSecretInLogs()

	/**
	 * CATASTROPHIC-TRAP GUARD: the shipped register JSON must still declare ALL FIVE
	 * inline-secret fields on the source schema. Removing them from the JSON would
	 * prune them fleet-wide on a version-bumping import (Schema::hydrate wholesale
	 * replace), UNGATED by any instance's migration state.
	 *
	 * @return void
	 */
	public function testRegisterJsonStillDeclaresAllFiveFields(): void {
		$path = __DIR__ . '/../../../lib/Settings/openconnector_register.json';
		$this->assertFileExists($path);

		$data = json_decode((string)file_get_contents($path), true);
		$this->assertIsArray($data, 'The register JSON must parse.');

		$props = ($data['components']['schemas']['source']['properties'] ?? []);
		foreach (['apikey', 'secret', 'password', 'jwt', 'authenticationConfig'] as $field) {
			$this->assertArrayHasKey(
				$field,
				$props,
				"The register JSON MUST keep declaring source.$field — removing it prunes it fleet-wide on the next version-bumping import."
			);
		}
	}//end testRegisterJsonStillDeclaresAllFiveFields()
}//end class

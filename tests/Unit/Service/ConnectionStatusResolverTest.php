<?php

/**
 * Unit tests for ConnectionStatusResolver (connection-registry, umbrella D4).
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-the-resolver-applies-the-d4-rules-in-order-req-conn-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Integriq\Service\ConnectionStatusResolver;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Exceptions\AppConfigTypeConflictException;
use OCP\Exceptions\AppConfigUnknownKeyException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Every D4 row, in order, plus the two orderings the umbrella explains.
 */
class ConnectionStatusResolverTest extends TestCase {

	/**
	 * The fixed clock value.
	 *
	 * @var string
	 */
	private const NOW = '2026-09-14T12:00:00+00:00';

	/**
	 * Build a resolver over a fixed config map and clock.
	 *
	 * A string value is stored under the string type. A bool, int, float or
	 * array value is stored under its own type, so getValueString() refuses it
	 * the way Nextcloud does.
	 *
	 * @param array<string,mixed> $config Key => value in the declaring app's config.
	 *
	 * @return ConnectionStatusResolver
	 */
	private function makeResolver(array $config = []): ConnectionStatusResolver {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '', bool $lazy = false) use ($config): string {
				$value = $config[$app . '.' . $key] ?? $default;
				if (is_string($value) === false) {
					throw new AppConfigTypeConflictException('conflict with value type from database');
				}

				return $value;
			}
		);
		$appConfig->method('getValueType')->willReturnCallback(
			static fn (string $app, string $key, ?bool $lazy = null): int => match (get_debug_type($config[$app . '.' . $key] ?? '')) {
				'bool' => IAppConfig::VALUE_BOOL,
				'int' => IAppConfig::VALUE_INT,
				'float' => IAppConfig::VALUE_FLOAT,
				'array' => IAppConfig::VALUE_ARRAY,
				default => IAppConfig::VALUE_STRING,
			}
		);
		$typed = static fn (string $app, string $key): mixed => $config[$app . '.' . $key];
		$appConfig->method('getValueBool')->willReturnCallback($typed);
		$appConfig->method('getValueInt')->willReturnCallback($typed);
		$appConfig->method('getValueFloat')->willReturnCallback($typed);
		$appConfig->method('getValueArray')->willReturnCallback($typed);

		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable(self::NOW));

		return new ConnectionStatusResolver(appConfig: $appConfig, timeFactory: $time);
	}//end makeResolver()

	/**
	 * Rule 1: a disabled app shows unavailable, stamped now, above every other rule.
	 *
	 * @return void
	 */
	public function testRuleOneDisabledAppIsUnavailable(): void {
		$row = [
			'app' => 'dossiq',
			'declaration' => ['available' => false, 'adapter' => ['configKey' => 'x']],
			'lastProbe' => ['status' => 'ok', 'message' => 'fine', 'at' => '2026-09-14T10:00:00+00:00'],
		];

		$outcome = $this->makeResolver()->resolve($row, false);

		$this->assertSame(expected: 'unavailable', actual: $outcome['status']);
		$this->assertSame(expected: 'The dossiq app is disabled.', actual: $outcome['statusMessage']);
		$this->assertSame(expected: self::NOW, actual: $outcome['checkedAt']);
		$this->assertSame(expected: 1, actual: $outcome['rule']);
	}//end testRuleOneDisabledAppIsUnavailable()

	/**
	 * Rule 2: available false uses the declared message, else the default.
	 *
	 * @return void
	 */
	public function testRuleTwoDeclaredUnavailable(): void {
		$resolver = $this->makeResolver();

		$declared = $resolver->resolve(
			['app' => 'dossiq', 'declaration' => ['available' => false, 'unavailableMessage' => 'Not wired yet.']],
			true
		);
		$this->assertSame(expected: 'unavailable', actual: $declared['status']);
		$this->assertSame(expected: 'Not wired yet.', actual: $declared['statusMessage']);
		$this->assertSame(expected: self::NOW, actual: $declared['checkedAt']);
		$this->assertSame(expected: 2, actual: $declared['rule']);

		$default = $resolver->resolve(['app' => 'dossiq', 'declaration' => ['available' => false]], true);
		$this->assertSame(expected: 'Declared, not built yet.', actual: $default['statusMessage']);
	}//end testRuleTwoDeclaredUnavailable()

	/**
	 * Rules 2, 3 and 5 keep the stored time while status and message stay the same.
	 *
	 * @return void
	 */
	public function testSyncTimeIsKeptWhileNothingChanged(): void {
		$row = [
			'app' => 'dossiq',
			'declaration' => ['available' => false],
			'status' => 'unavailable',
			'statusMessage' => 'Declared, not built yet.',
			'checkedAt' => '2026-09-01T08:00:00+00:00',
		];

		$outcome = $this->makeResolver()->resolve($row, true);

		$this->assertSame(expected: '2026-09-01T08:00:00+00:00', actual: $outcome['checkedAt']);
	}//end testSyncTimeIsKeptWhileNothingChanged()

	/**
	 * Rule 3: an empty adapter key shows simulated with the declared message.
	 *
	 * @return void
	 */
	public function testRuleThreeEmptyAdapterKeyIsSimulated(): void {
		$row = [
			'app' => 'dossiq',
			'declaration' => [
				'adapter' => ['configKey' => 'berichtenbox_adapter', 'simulatedMessage' => 'A mock answers.'],
			],
		];

		$outcome = $this->makeResolver()->resolve($row, true);

		$this->assertSame(expected: 'simulated', actual: $outcome['status']);
		$this->assertSame(expected: 'A mock answers.', actual: $outcome['statusMessage']);
		$this->assertSame(expected: 3, actual: $outcome['rule']);
	}//end testRuleThreeEmptyAdapterKeyIsSimulated()

	/**
	 * Rule 3 default message names the config key.
	 *
	 * @return void
	 */
	public function testRuleThreeDefaultMessageNamesTheKey(): void {
		$row = ['app' => 'dossiq', 'declaration' => ['adapter' => ['configKey' => 'kvk_adapter']]];

		$outcome = $this->makeResolver()->resolve($row, true);

		$this->assertSame(expected: 'A mock adapter answers here. Set kvk_adapter to a real adapter.', actual: $outcome['statusMessage']);
	}//end testRuleThreeDefaultMessageNamesTheKey()

	/**
	 * Why simulated beats a passing probe: rule 3 sits above rule 4.
	 *
	 * @return void
	 */
	public function testSimulatedOutranksAPassingProbe(): void {
		$row = [
			'app' => 'dossiq',
			'declaration' => ['adapter' => ['configKey' => 'berichtenbox_adapter']],
			'source' => 'b0a7c1d2-0000-4000-8000-000000000001',
			'lastProbe' => ['status' => 'ok', 'message' => 'HTTP 200', 'at' => '2026-09-14T11:00:00+00:00'],
		];

		$outcome = $this->makeResolver()->resolve($row, true);

		$this->assertSame(expected: 'simulated', actual: $outcome['status']);
	}//end testSimulatedOutranksAPassingProbe()

	/**
	 * A filled adapter key falls through to the next rules.
	 *
	 * @return void
	 */
	public function testFilledAdapterKeyIsNotSimulated(): void {
		$row = ['app' => 'dossiq', 'declaration' => ['adapter' => ['configKey' => 'berichtenbox_adapter']]];

		$outcome = $this->makeResolver(config: ['dossiq.berichtenbox_adapter' => 'OCA\\Dossiq\\Real'])->resolve($row, true);

		$this->assertSame(expected: 'unconfigured', actual: $outcome['status']);
	}//end testFilledAdapterKeyIsNotSimulated()

	/**
	 * Rule 4: a probe's ok reads as configured, with the probe's own time.
	 *
	 * @return void
	 */
	public function testRuleFourProbeOkReadsAsConfigured(): void {
		$row = [
			'app' => 'dossiq',
			'declaration' => [],
			'lastProbe' => ['status' => 'ok', 'message' => 'The source answered with HTTP 200.', 'at' => '2026-09-14T11:00:00+00:00'],
		];

		$outcome = $this->makeResolver()->resolve($row, true);

		$this->assertSame(expected: 'configured', actual: $outcome['status']);
		$this->assertSame(expected: 'The source answered with HTTP 200.', actual: $outcome['statusMessage']);
		$this->assertSame(expected: '2026-09-14T11:00:00+00:00', actual: $outcome['checkedAt']);
		$this->assertSame(expected: 4, actual: $outcome['rule']);
	}//end testRuleFourProbeOkReadsAsConfigured()

	/**
	 * Why a report and a probe compare by time: the newer observation wins.
	 *
	 * @return void
	 */
	public function testNewerObservationWins(): void {
		$resolver = $this->makeResolver();
		$report = ['status' => 'configured', 'message' => 'Logged in', 'at' => '2026-09-14T10:00:00+00:00'];
		$probe = ['status' => 'error', 'message' => 'HTTP 503', 'at' => '2026-09-14T11:00:00+00:00'];

		$probeNewer = $resolver->resolve(['app' => 'dossiq', 'lastReport' => $report, 'lastProbe' => $probe], true);
		$this->assertSame(expected: 'error', actual: $probeNewer['status']);
		$this->assertSame(expected: '2026-09-14T11:00:00+00:00', actual: $probeNewer['checkedAt']);

		$report['at'] = '2026-09-14T11:30:00+00:00';
		$reportNewer = $resolver->resolve(['app' => 'dossiq', 'lastReport' => $report, 'lastProbe' => $probe], true);
		$this->assertSame(expected: 'configured', actual: $reportNewer['status']);
		$this->assertSame(expected: 'Logged in', actual: $reportNewer['statusMessage']);
	}//end testNewerObservationWins()

	/**
	 * An observation with an invalid status is ignored.
	 *
	 * @return void
	 */
	public function testInvalidObservationIsIgnored(): void {
		$row = [
			'app' => 'dossiq',
			'lastReport' => ['status' => 'green', 'message' => 'nope', 'at' => '2026-09-14T10:00:00+00:00'],
			'lastProbe' => ['status' => 'configured', 'message' => 'probes only say ok or error'],
		];

		$outcome = $this->makeResolver()->resolve($row, true);

		$this->assertSame(expected: 6, actual: $outcome['rule']);
	}//end testInvalidObservationIsIgnored()

	/**
	 * Rule 5: every required key filled shows configured.
	 *
	 * @return void
	 */
	public function testRuleFiveSavedSettingsShowConfigured(): void {
		$row = ['app' => 'dossiq', 'declaration' => ['requiredConfig' => ['register', 'case_schema']]];

		$outcome = $this->makeResolver(config: ['dossiq.register' => 'dossiq', 'dossiq.case_schema' => 'case'])->resolve($row, true);

		$this->assertSame(expected: 'configured', actual: $outcome['status']);
		$this->assertSame(expected: 'Required settings are filled.', actual: $outcome['statusMessage']);
		$this->assertSame(expected: self::NOW, actual: $outcome['checkedAt']);
		$this->assertSame(expected: 5, actual: $outcome['rule']);
	}//end testRuleFiveSavedSettingsShowConfigured()

	/**
	 * Rule 5 does not apply when one required key is empty.
	 *
	 * @return void
	 */
	public function testRuleFiveNeedsEveryKey(): void {
		$row = ['app' => 'dossiq', 'declaration' => ['requiredConfig' => ['register', 'case_schema']]];

		$outcome = $this->makeResolver(config: ['dossiq.register' => 'dossiq', 'dossiq.case_schema' => '  '])->resolve($row, true);

		$this->assertSame(expected: 'unconfigured', actual: $outcome['status']);
	}//end testRuleFiveNeedsEveryKey()

	/**
	 * Rule 6: nothing to go on shows not checked, with no time.
	 *
	 * @return void
	 */
	public function testRuleSixNotCheckedYet(): void {
		$outcome = $this->makeResolver()->resolve(['app' => 'dossiq', 'declaration' => ['key' => 'pdok']], true);

		$this->assertSame(expected: 'unconfigured', actual: $outcome['status']);
		$this->assertSame(expected: 'Not checked yet.', actual: $outcome['statusMessage']);
		$this->assertNull(actual: $outcome['checkedAt']);
		$this->assertSame(expected: 6, actual: $outcome['rule']);
	}//end testRuleSixNotCheckedYet()

	/**
	 * Rule 6 uses the declared unconfiguredMessage when there is one.
	 *
	 * @return void
	 */
	public function testRuleSixUsesDeclaredUnconfiguredMessage(): void {
		$row = [
			'app' => 'dossiq',
			'declaration' => ['key' => 'brp', 'unconfiguredMessage' => 'Set integration.brp.mode to use the BRP.'],
		];

		$outcome = $this->makeResolver()->resolve($row, true);

		$this->assertSame(expected: 'unconfigured', actual: $outcome['status']);
		$this->assertSame(expected: 'Set integration.brp.mode to use the BRP.', actual: $outcome['statusMessage']);
		$this->assertNull(actual: $outcome['checkedAt']);
	}//end testRuleSixUsesDeclaredUnconfiguredMessage()

	/**
	 * A value stored under another type counts as filled.
	 *
	 * @return void
	 */
	public function testTypedConfigValueCountsAsFilled(): void {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willThrowException(
			new \OCP\Exceptions\AppConfigTypeConflictException('conflict with value type from database')
		);
		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable(self::NOW));

		$resolver = new ConnectionStatusResolver(appConfig: $appConfig, timeFactory: $time);
		$outcome = $resolver->resolve(['app' => 'dossiq', 'declaration' => ['requiredConfig' => ['retries']]], true);

		$this->assertSame(expected: 'configured', actual: $outcome['status']);
	}//end testTypedConfigValueCountsAsFilled()

	/**
	 * A provider name in `simulatedValues` selects simulated, case-insensitively after trimming.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#scenario-a-provider-name-selects-simulated
	 */
	public function testProviderNameSelectsSimulated(): void {
		$row = [
			'app' => 'pipelinq',
			'declaration' => ['adapter' => ['configKey' => 'email_transport_type', 'simulatedValues' => ['', 'null']]],
		];

		foreach (['null', '  NULL ', ''] as $value) {
			$outcome = $this->makeResolver(config: ['pipelinq.email_transport_type' => $value])->resolve($row, true);
			$this->assertSame(expected: 'simulated', actual: $outcome['status'], message: 'value "' . $value . '"');
			$this->assertSame(expected: 3, actual: $outcome['rule']);
		}

		$real = $this->makeResolver(config: ['pipelinq.email_transport_type' => 'smtp'])->resolve($row, true);
		$this->assertSame(expected: 'unconfigured', actual: $real['status']);
	}//end testProviderNameSelectsSimulated()

	/**
	 * A JSON path reads inside a settings blob, so a real provider there keeps rule 3 off.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#scenario-a-json-path-reads-inside-a-settings-blob
	 */
	public function testJsonPathReadsInsideASettingsBlob(): void {
		$row = [
			'app' => 'pipelinq',
			'declaration' => ['adapter' => ['configKey' => 'llm', 'jsonPath' => 'provider', 'simulatedValues' => ['', 'none']]],
		];

		$real = $this->makeResolver(config: ['pipelinq.llm' => '{"provider": "openai"}'])->resolve($row, true);
		$this->assertNotSame(expected: 3, actual: $real['rule']);
		$this->assertSame(expected: 'unconfigured', actual: $real['status']);

		$mock = $this->makeResolver(config: ['pipelinq.llm' => '{"provider": "None"}'])->resolve($row, true);
		$this->assertSame(expected: 'simulated', actual: $mock['status']);
	}//end testJsonPathReadsInsideASettingsBlob()

	/**
	 * JSON path edge cases: [stored value, path, expected adapter value is simulated under the default list].
	 *
	 * @return array<string,array{0:string,1:string,2:bool}>
	 */
	public static function jsonPathCases(): array {
		return [
			'nested path with a value' => ['{"chat": {"provider": "openai"}}', 'chat.provider', false],
			'missing leaf' => ['{"chat": {}}', 'chat.provider', true],
			'missing branch' => ['{"other": 1}', 'chat.provider', true],
			'path through a scalar' => ['{"chat": "openai"}', 'chat.provider', true],
			'invalid JSON' => ['{provider: openai', 'provider', true],
			'empty value' => ['', 'provider', true],
			'a plain string, not JSON' => ['openai', 'provider', true],
			'object at the path' => ['{"provider": {"name": "openai"}}', 'provider', true],
			'list at the path' => ['{"provider": ["openai"]}', 'provider', true],
			'null at the path' => ['{"provider": null}', 'provider', true],
			'whitespace string at the path' => ['{"provider": "   "}', 'provider', true],
			'number at the path' => ['{"provider": 0}', 'provider', false],
			'false at the path' => ['{"provider": false}', 'provider', false],
		];
	}//end jsonPathCases()

	/**
	 * A missing path, invalid JSON or a non-scalar value reads as the empty string.
	 *
	 * @param string $stored The stored config value.
	 * @param string $path The declared JSON path.
	 * @param bool $simulated Whether rule 3 applies under the default `[""]`.
	 *
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('jsonPathCases')]
	public function testJsonPathEdgeCases(string $stored, string $path, bool $simulated): void {
		$row = ['app' => 'pipelinq', 'declaration' => ['adapter' => ['configKey' => 'llm', 'jsonPath' => $path]]];

		$outcome = $this->makeResolver(config: ['pipelinq.llm' => $stored])->resolve($row, true);

		$this->assertSame(expected: $simulated, actual: $outcome['rule'] === 3);
	}//end testJsonPathEdgeCases()

	/**
	 * Booleans and numbers at the path read as their JSON text, so a list can name them.
	 *
	 * @return void
	 */
	public function testJsonPathScalarsReadAsText(): void {
		$declaration = ['adapter' => ['configKey' => 'geo', 'jsonPath' => 'enabled', 'simulatedValues' => ['false', '0']]];

		$false = $this->makeResolver(config: ['traffic.geo' => '{"enabled": false}'])->resolve(['app' => 'traffic', 'declaration' => $declaration], true);
		$this->assertSame(expected: 'simulated', actual: $false['status']);

		$zero = $this->makeResolver(config: ['traffic.geo' => '{"enabled": 0}'])->resolve(['app' => 'traffic', 'declaration' => $declaration], true);
		$this->assertSame(expected: 'simulated', actual: $zero['status']);

		$true = $this->makeResolver(config: ['traffic.geo' => '{"enabled": true}'])->resolve(['app' => 'traffic', 'declaration' => $declaration], true);
		$this->assertSame(expected: 'unconfigured', actual: $true['status']);
	}//end testJsonPathScalarsReadAsText()

	/**
	 * A JSON path also reads a value Nextcloud stores under the array type.
	 *
	 * @return void
	 */
	public function testJsonPathReadsAnArrayTypedValue(): void {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willThrowException(
			new \OCP\Exceptions\AppConfigTypeConflictException('conflict with value type from database')
		);
		$appConfig->method('getValueArray')->willReturn(['provider' => 'none']);
		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable(self::NOW));

		$resolver = new ConnectionStatusResolver(appConfig: $appConfig, timeFactory: $time);
		$row = [
			'app' => 'pipelinq',
			'declaration' => ['adapter' => ['configKey' => 'llm', 'jsonPath' => 'provider', 'simulatedValues' => ['none']]],
		];

		$this->assertSame(expected: 'simulated', actual: $resolver->resolve($row, true)['status']);
	}//end testJsonPathReadsAnArrayTypedValue()

	/**
	 * Without the new fields a declaration resolves exactly as before, and the explicit defaults change nothing.
	 *
	 * Before the amendment rule 3 applied only when the trimmed value was empty,
	 * so only the empty and blank values expect simulated here.
	 *
	 * @return void
	 */
	public function testDefaultsKeepTheOldMeaning(): void {
		$legacy = ['configKey' => 'berichtenbox_adapter', 'simulatedMessage' => 'A mock answers.'];
		$explicit = $legacy + ['simulatedValues' => ['']];
		$oldStatus = [
			'' => 'simulated',
			'   ' => 'simulated',
			'OCA\\Dossiq\\Real' => 'unconfigured',
			'null' => 'unconfigured',
			'none' => 'unconfigured',
			'NULL' => 'unconfigured',
		];

		foreach ($oldStatus as $value => $expected) {
			$value = (string)$value;
			$resolver = $this->makeResolver(config: ['dossiq.berichtenbox_adapter' => $value]);
			$withoutFields = $resolver->resolve(['app' => 'dossiq', 'declaration' => ['adapter' => $legacy]], true);
			$withDefaults = $resolver->resolve(
				['app' => 'dossiq', 'declaration' => ['adapter' => $explicit, 'reportedOnly' => false]],
				true
			);

			$this->assertSame(expected: $expected, actual: $withoutFields['status'], message: 'value "' . $value . '"');
			$this->assertSame(expected: $withoutFields, actual: $withDefaults, message: 'value "' . $value . '"');
		}
	}//end testDefaultsKeepTheOldMeaning()

	/**
	 * A reported-only row ignores filled settings and an empty adapter key.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#scenario-a-reported-only-row-ignores-filled-settings
	 */
	public function testReportedOnlyRowIgnoresFilledSettings(): void {
		$row = [
			'app' => 'pipelinq',
			'declaration' => [
				'reportedOnly' => true,
				'requiredConfig' => ['cti_register'],
				'adapter' => ['configKey' => 'cti_adapter'],
			],
		];

		$outcome = $this->makeResolver(config: ['pipelinq.cti_register' => 'pipelinq'])->resolve($row, true);

		$this->assertSame(expected: 'unconfigured', actual: $outcome['status']);
		$this->assertSame(expected: 6, actual: $outcome['rule']);
	}//end testReportedOnlyRowIgnoresFilledSettings()

	/**
	 * A reported-only row still takes the app's report.
	 *
	 * @return void
	 */
	public function testReportedOnlyRowShowsTheReport(): void {
		$row = [
			'app' => 'pipelinq',
			'declaration' => ['reportedOnly' => true, 'requiredConfig' => ['cti_register']],
			'lastReport' => ['status' => 'configured', 'message' => 'Platform chosen: Voys.', 'at' => '2026-09-14T10:00:00+00:00'],
		];

		$outcome = $this->makeResolver(config: ['pipelinq.cti_register' => 'pipelinq'])->resolve($row, true);

		$this->assertSame(expected: 'configured', actual: $outcome['status']);
		$this->assertSame(expected: 'Platform chosen: Voys.', actual: $outcome['statusMessage']);
	}//end testReportedOnlyRowShowsTheReport()

	/**
	 * Rule 4a: a simulated report stands against a newer passing probe, with the report's time.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#scenario-a-simulated-report-stands-against-a-newer-probe
	 */
	public function testSimulatedReportStandsAgainstANewerProbe(): void {
		$row = [
			'app' => 'shillinq',
			'declaration' => [],
			'lastReport' => ['status' => 'simulated', 'message' => 'A Log adapter answers.', 'at' => '2026-09-14T10:00:00+00:00'],
			'lastProbe' => ['status' => 'ok', 'message' => 'HTTP 200', 'at' => '2026-09-14T11:00:00+00:00'],
		];

		$outcome = $this->makeResolver()->resolve($row, true);

		$this->assertSame(expected: 'simulated', actual: $outcome['status']);
		$this->assertSame(expected: '2026-09-14T10:00:00+00:00', actual: $outcome['checkedAt']);
		$this->assertSame(expected: 'A Log adapter answers.', actual: $outcome['statusMessage']);
	}//end testSimulatedReportStandsAgainstANewerProbe()

	/**
	 * A report may carry limited, and rule 4b shows it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#scenario-an-app-reports-a-connection-that-works-in-part
	 */
	public function testLimitedReportIsShown(): void {
		$row = [
			'app' => 'pipelinq',
			'declaration' => [],
			'lastReport' => [
				'status' => 'limited',
				'message' => 'Preview API: posting works, reading replies does not.',
				'at' => '2026-09-14T10:00:00+00:00',
			],
		];

		$outcome = $this->makeResolver()->resolve($row, true);

		$this->assertSame(expected: 'limited', actual: $outcome['status']);
		$this->assertSame(expected: 'Preview API: posting works, reading replies does not.', actual: $outcome['statusMessage']);
		$this->assertContains(needle: 'limited', haystack: ConnectionStatusResolver::STATUSES);
	}//end testLimitedReportIsShown()

	/**
	 * No declaration produces limited: every rule that reads the declaration answers another status.
	 *
	 * @return void
	 */
	public function testNoDeclarationProducesLimited(): void {
		$declarations = [
			['available' => false],
			['adapter' => ['configKey' => 'x', 'simulatedValues' => ['limited']]],
			['requiredConfig' => ['limited']],
			['reportedOnly' => true],
			[],
		];

		foreach ($declarations as $declaration) {
			$outcome = $this->makeResolver(config: ['dossiq.x' => 'limited', 'dossiq.limited' => 'limited'])
				->resolve(['app' => 'dossiq', 'declaration' => $declaration], true);
			$this->assertNotSame(expected: 'limited', actual: $outcome['status']);
		}
	}//end testNoDeclarationProducesLimited()

	/**
	 * A zrc row with a filled required setting, a 10:00 error report and a 10:05 refresh.
	 *
	 * @param array<string,mixed> $overrides Row fields to replace.
	 *
	 * @return array<string,mixed>
	 */
	private function refreshedRow(array $overrides = []): array {
		return array_merge(
			[
				'app' => 'zaakafhandelapp',
				'key' => 'zrc',
				'declaration' => ['requiredConfig' => ['zrc_url']],
				'lastReport' => ['status' => 'error', 'message' => 'Connection refused', 'at' => '2026-09-14T10:00:00+00:00'],
				'refreshedAt' => '2026-09-14T10:05:00+00:00',
			],
			$overrides
		);
	}//end refreshedRow()

	/**
	 * A report older than the refresh no longer counts, so the row falls back to rule 5.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#scenario-a-save-retires-an-older-error
	 */
	public function testRefreshRetiresAnOlderReport(): void {
		$outcome = $this->makeResolver(config: ['zaakafhandelapp.zrc_url' => 'https://zrc.example.nl'])
			->resolve($this->refreshedRow(), true);

		$this->assertSame(expected: 'configured', actual: $outcome['status']);
		$this->assertSame(expected: 'Required settings are filled.', actual: $outcome['statusMessage']);
		$this->assertSame(expected: 5, actual: $outcome['rule']);
	}//end testRefreshRetiresAnOlderReport()

	/**
	 * Without filled settings a retired report leaves rule 6, not the old error.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#scenario-a-save-retires-an-older-error
	 */
	public function testRefreshRetiresAnOlderReportDownToRuleSix(): void {
		$outcome = $this->makeResolver()->resolve($this->refreshedRow(), true);

		$this->assertSame(expected: 'unconfigured', actual: $outcome['status']);
		$this->assertNull(actual: $outcome['checkedAt']);
		$this->assertSame(expected: 6, actual: $outcome['rule']);
	}//end testRefreshRetiresAnOlderReportDownToRuleSix()

	/**
	 * A report newer than the refresh counts again, with its own time.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#scenario-a-report-after-the-refresh-counts-again
	 */
	public function testReportAfterTheRefreshCountsAgain(): void {
		$row = $this->refreshedRow(
			overrides: ['lastReport' => ['status' => 'error', 'message' => 'Connection refused', 'at' => '2026-09-14T10:07:00+00:00']]
		);

		$outcome = $this->makeResolver(config: ['zaakafhandelapp.zrc_url' => 'https://zrc.example.nl'])->resolve($row, true);

		$this->assertSame(expected: 'error', actual: $outcome['status']);
		$this->assertSame(expected: '2026-09-14T10:07:00+00:00', actual: $outcome['checkedAt']);
		$this->assertSame(expected: 4, actual: $outcome['rule']);
	}//end testReportAfterTheRefreshCountsAgain()

	/**
	 * A report at the very time of the refresh is not older, so it counts.
	 *
	 * @return void
	 */
	public function testReportAtTheRefreshTimeCounts(): void {
		$row = $this->refreshedRow(
			overrides: ['lastReport' => ['status' => 'error', 'message' => 'Connection refused', 'at' => '2026-09-14T10:05:00+00:00']]
		);

		$outcome = $this->makeResolver(config: ['zaakafhandelapp.zrc_url' => 'https://zrc.example.nl'])->resolve($row, true);

		$this->assertSame(expected: 'error', actual: $outcome['status']);
		$this->assertSame(expected: '2026-09-14T10:05:00+00:00', actual: $outcome['checkedAt']);
	}//end testReportAtTheRefreshTimeCounts()

	/**
	 * Equal times compare as instants, so another offset for the same moment still counts.
	 *
	 * @return void
	 */
	public function testEqualInstantInAnotherOffsetCounts(): void {
		$row = $this->refreshedRow(
			overrides: ['lastProbe' => ['status' => 'error', 'message' => 'HTTP 503', 'at' => '2026-09-14T12:05:00+02:00']]
		);

		$outcome = $this->makeResolver(config: ['zaakafhandelapp.zrc_url' => 'https://zrc.example.nl'])->resolve($row, true);

		$this->assertSame(expected: 'error', actual: $outcome['status']);
		$this->assertSame(expected: 'HTTP 503', actual: $outcome['statusMessage']);
	}//end testEqualInstantInAnotherOffsetCounts()

	/**
	 * A probe newer than the refresh counts while the older report stays retired.
	 *
	 * @return void
	 */
	public function testProbeNewerThanTheRefreshCounts(): void {
		$row = $this->refreshedRow(
			overrides: ['lastProbe' => ['status' => 'ok', 'message' => 'Source answered', 'at' => '2026-09-14T10:10:00+00:00']]
		);

		$outcome = $this->makeResolver()->resolve($row, true);

		$this->assertSame(expected: 'configured', actual: $outcome['status']);
		$this->assertSame(expected: 'Source answered', actual: $outcome['statusMessage']);
		$this->assertSame(expected: '2026-09-14T10:10:00+00:00', actual: $outcome['checkedAt']);
		$this->assertSame(expected: 4, actual: $outcome['rule']);
	}//end testProbeNewerThanTheRefreshCounts()

	/**
	 * A probe older than the refresh is retired too, not only a report.
	 *
	 * @return void
	 */
	public function testProbeOlderThanTheRefreshIsRetired(): void {
		$row = $this->refreshedRow(
			overrides: [
				'lastReport' => null,
				'lastProbe' => ['status' => 'error', 'message' => 'HTTP 503', 'at' => '2026-09-14T09:00:00+00:00'],
			]
		);

		$outcome = $this->makeResolver()->resolve($row, true);

		$this->assertSame(expected: 'unconfigured', actual: $outcome['status']);
		$this->assertSame(expected: 6, actual: $outcome['rule']);
	}//end testProbeOlderThanTheRefreshIsRetired()

	/**
	 * Rule 4a retires with the refresh as well: an older simulated report gives way to a newer probe.
	 *
	 * @return void
	 */
	public function testRefreshRetiresAnOlderSimulatedReport(): void {
		$row = $this->refreshedRow(
			overrides: [
				'lastReport' => ['status' => 'simulated', 'message' => 'A mock answers.', 'at' => '2026-09-14T10:00:00+00:00'],
				'lastProbe' => ['status' => 'ok', 'message' => 'Source answered', 'at' => '2026-09-14T10:10:00+00:00'],
			]
		);

		$outcome = $this->makeResolver()->resolve($row, true);

		$this->assertSame(expected: 'configured', actual: $outcome['status']);
		$this->assertSame(expected: 'Source answered', actual: $outcome['statusMessage']);
	}//end testRefreshRetiresAnOlderSimulatedReport()

	/**
	 * A row without refreshedAt, or with an unreadable one, retires nothing.
	 *
	 * @return void
	 */
	public function testMissingOrUnreadableRefreshRetiresNothing(): void {
		foreach ([null, '', 'not a date'] as $refreshedAt) {
			$outcome = $this->makeResolver()->resolve($this->refreshedRow(overrides: ['refreshedAt' => $refreshedAt]), true);

			$this->assertSame(expected: 'error', actual: $outcome['status']);
			$this->assertSame(expected: '2026-09-14T10:00:00+00:00', actual: $outcome['checkedAt']);
		}
	}//end testMissingOrUnreadableRefreshRetiresNothing()

	/**
	 * A switch stored as a boolean false is not a filled setting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#scenario-a-switch-stored-as-false-is-not-filled
	 */
	public function testSwitchStoredAsFalseIsNotFilled(): void {
		$row = ['app' => 'stackiq', 'declaration' => ['key' => 'federation', 'requiredConfig' => ['federation_enabled']]];

		$outcome = $this->makeResolver(config: ['stackiq.federation_enabled' => false])->resolve($row, true);

		$this->assertSame(expected: 'unconfigured', actual: $outcome['status']);
	}//end testSwitchStoredAsFalseIsNotFilled()

	/**
	 * The control for the scenario above: the same switch stored as true is filled.
	 *
	 * @return void
	 */
	public function testSwitchStoredAsTrueIsFilled(): void {
		$row = ['app' => 'stackiq', 'declaration' => ['key' => 'federation', 'requiredConfig' => ['federation_enabled']]];

		$outcome = $this->makeResolver(config: ['stackiq.federation_enabled' => true])->resolve($row, true);

		$this->assertSame(expected: 'configured', actual: $outcome['status']);
		$this->assertSame(expected: 5, actual: $outcome['rule']);
	}//end testSwitchStoredAsTrueIsFilled()

	/**
	 * A `{configKey, jsonPath}` entry reads one value inside a JSON setting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#scenario-a-required-value-inside-a-json-setting
	 */
	public function testRequiredValueInsideAJsonSetting(): void {
		$row = [
			'app' => 'stackiq',
			'declaration' => ['key' => 'eol-feed', 'requiredConfig' => [['configKey' => 'eolSync', 'jsonPath' => 'enabled']]],
		];

		$outcome = $this->makeResolver(config: ['stackiq.eolSync' => '{"enabled": true, "interval": 24}'])->resolve($row, true);

		$this->assertSame(expected: 'configured', actual: $outcome['status']);
		$this->assertSame(expected: 'Required settings are filled.', actual: $outcome['statusMessage']);
		$this->assertSame(expected: 5, actual: $outcome['rule']);
	}//end testRequiredValueInsideAJsonSetting()

	/**
	 * A JSON setting stored under the array type is read through getValueArray, like the adapter path.
	 *
	 * @return void
	 */
	public function testRequiredValueInsideAnArrayTypedSetting(): void {
		$row = [
			'app' => 'stackiq',
			'declaration' => ['requiredConfig' => [['configKey' => 'eolSync', 'jsonPath' => 'sync.enabled']]],
		];

		$on = $this->makeResolver(config: ['stackiq.eolSync' => ['sync' => ['enabled' => true]]])->resolve($row, true);
		$this->assertSame(expected: 'configured', actual: $on['status']);

		$off = $this->makeResolver(config: ['stackiq.eolSync' => ['sync' => ['enabled' => false]]])->resolve($row, true);
		$this->assertSame(expected: 'unconfigured', actual: $off['status']);
	}//end testRequiredValueInsideAnArrayTypedSetting()

	/**
	 * A string entry with dots is the whole key, never a path into a shorter key.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#scenario-a-dotted-key-is-read-as-one-key
	 */
	public function testDottedKeyIsReadAsOneKey(): void {
		$row = ['app' => 'dossiq', 'declaration' => ['key' => 'brp', 'requiredConfig' => ['integration.brp.mode']]];

		$outcome = $this->makeResolver(config: ['dossiq.integration.brp.mode' => 'live'])->resolve($row, true);

		$this->assertSame(expected: 'configured', actual: $outcome['status']);
		$this->assertSame(expected: 5, actual: $outcome['rule']);
	}//end testDottedKeyIsReadAsOneKey()

	/**
	 * A dotted string entry does not walk into a JSON setting under its first segment.
	 *
	 * @return void
	 */
	public function testDottedKeyDoesNotReadInsideAShorterKey(): void {
		$row = ['app' => 'dossiq', 'declaration' => ['key' => 'brp', 'requiredConfig' => ['integration.brp.mode']]];

		$outcome = $this->makeResolver(config: ['dossiq.integration' => '{"brp": {"mode": "live"}}'])->resolve($row, true);

		$this->assertSame(expected: 'unconfigured', actual: $outcome['status']);
	}//end testDottedKeyDoesNotReadInsideAShorterKey()

	/**
	 * Emptiness cases: [stored value, or null for nothing stored, entry, expected filled].
	 *
	 * @return array<string,array{0:mixed,1:mixed,2:bool}>
	 */
	public static function emptinessCases(): array {
		$onPath = ['configKey' => 'blob', 'jsonPath' => 'on'];

		return [
			'empty string is empty' => ['', 'setting', false],
			'whitespace is empty' => ['   ', 'setting', false],
			'"false" is empty' => ['false', 'setting', false],
			'"FALSE" is empty' => ['FALSE', 'setting', false],
			'"0" is empty' => ['0', 'setting', false],
			'"0" with whitespace is empty' => [' 0 ', 'setting', false],
			'nothing stored is empty' => [null, 'setting', false],
			'boolean false under the bool type is empty' => [false, 'setting', false],
			'0 under the int type is empty' => [0, 'setting', false],
			'0.0 under the float type is empty' => [0.0, 'setting', false],
			'JSON false at the path is empty' => ['{"on": false}', $onPath, false],
			'JSON 0 at the path is empty' => ['{"on": 0}', $onPath, false],
			'JSON null at the path is empty' => ['{"on": null}', $onPath, false],
			'JSON "False " at the path is empty' => ['{"on": "False "}', $onPath, false],
			'JSON whitespace at the path is empty' => ['{"on": "  "}', $onPath, false],
			'a missing path is empty' => ['{"other": true}', $onPath, false],
			'invalid JSON is empty' => ['{on: true', $onPath, false],
			'an entry that is neither string nor object is empty' => ['live', 42, false],
			'an object entry without jsonPath is empty' => ['{"on": true}', ['configKey' => 'blob'], false],
			'a value is filled' => ['live', 'setting', true],
			'"true" is filled' => ['true', 'setting', true],
			'1 under the int type is filled' => [1, 'setting', true],
			'a list under the array type is filled' => [['a'], 'setting', true],
			'JSON true at the path is filled' => ['{"on": true}', $onPath, true],
			'JSON 24 at the path is filled' => ['{"on": 24}', $onPath, true],
			'an object at the path is filled' => ['{"on": {"mode": "live"}}', $onPath, true],
			'"[]" is empty' => ['[]', 'setting', false],
			'"{}" is empty' => ['{}', 'setting', false],
			'" [] " with whitespace around it is empty' => [' [] ', 'setting', false],
			'"[ ]" is empty, because JSON allows whitespace inside' => ['[ ]', 'setting', false],
			'"{ }" is empty, because JSON allows whitespace inside' => ['{ }', 'setting', false],
			'"[0]" is filled, because a list holding a zero is not empty' => ['[0]', 'setting', true],
			'"[\"\"]" is filled, because a list holding an empty string is not empty' => ['[""]', 'setting', true],
			'"[" is filled, because it is text, not JSON' => ['[', 'setting', true],
			'an empty list under the array type is empty' => [[], 'setting', false],
			'JSON [] at the path is empty' => ['{"on": []}', $onPath, false],
			'JSON {} at the path is empty' => ['{"on": {}}', $onPath, false],
			'JSON [0] at the path is filled' => ['{"on": [0]}', $onPath, true],
			'the text "[]" at the path is empty' => ['{"on": "[]"}', $onPath, false],
			'"null" stays filled, because only text starting with [ or { is decoded' => ['null', 'setting', true],
		];
	}//end emptinessCases()

	/**
	 * Rule 5 applies exactly when the entry's value is filled.
	 *
	 * @param mixed $stored The stored value, or null for nothing stored.
	 * @param mixed $entry The requiredConfig entry.
	 * @param bool $filled Whether the value counts as filled.
	 *
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('emptinessCases')]
	public function testWhatCountsAsFilled(mixed $stored, mixed $entry, bool $filled): void {
		$config = [];
		if ($stored !== null) {
			$config = ['dossiq.setting' => $stored, 'dossiq.blob' => $stored];
		}

		$outcome = $this->makeResolver(config: $config)->resolve(['app' => 'dossiq', 'declaration' => ['requiredConfig' => [$entry]]], true);

		$this->assertSame(expected: $filled, actual: $outcome['rule'] === 5);
	}//end testWhatCountsAsFilled()

	/**
	 * "no", "00" and "off" count as filled, because only "", "false" and "0" are listed as empty.
	 *
	 * @return void
	 */
	public function testNoAndDoubleZeroAreFilledBecauseOnlyTheListedValuesAreEmpty(): void {
		$row = ['app' => 'dossiq', 'declaration' => ['requiredConfig' => ['setting']]];

		foreach (['no', '00', 'off'] as $value) {
			$outcome = $this->makeResolver(config: ['dossiq.setting' => $value])->resolve($row, true);
			$this->assertSame(expected: 'configured', actual: $outcome['status'], message: 'value "' . $value . '"');
		}
	}//end testNoAndDoubleZeroAreFilledBecauseOnlyTheListedValuesAreEmpty()

	/**
	 * String and object entries mix, and one empty entry keeps rule 5 off.
	 *
	 * @return void
	 */
	public function testMixedEntriesNeedEveryOneFilled(): void {
		$row = [
			'app' => 'stackiq',
			'declaration' => ['requiredConfig' => ['register', ['configKey' => 'eolSync', 'jsonPath' => 'enabled']]],
		];

		$both = $this->makeResolver(config: ['stackiq.register' => 'catalog', 'stackiq.eolSync' => '{"enabled": true}'])->resolve($row, true);
		$this->assertSame(expected: 'configured', actual: $both['status']);

		$oneOff = $this->makeResolver(config: ['stackiq.register' => 'catalog', 'stackiq.eolSync' => '{"enabled": false}'])->resolve($row, true);
		$this->assertSame(expected: 'unconfigured', actual: $oneOff['status']);
	}//end testMixedEntriesNeedEveryOneFilled()

	/**
	 * A key whose type Nextcloud cannot name still holds a value, so it counts as filled.
	 *
	 * @return void
	 */
	public function testUnknownTypedKeyCountsAsFilled(): void {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willThrowException(new AppConfigTypeConflictException('conflict with value type from database'));
		$appConfig->method('getValueType')->willThrowException(new AppConfigUnknownKeyException('unknown config key'));
		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable(self::NOW));

		$resolver = new ConnectionStatusResolver(appConfig: $appConfig, timeFactory: $time);
		$outcome = $resolver->resolve(['app' => 'dossiq', 'declaration' => ['requiredConfig' => ['retries']]], true);

		$this->assertSame(expected: 'configured', actual: $outcome['status']);
	}//end testUnknownTypedKeyCountsAsFilled()

	/**
	 * An empty JSON list does not count as a filled setting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#scenario-an-empty-json-list-is-not-filled
	 */
	public function testEmptyJsonListIsNotFilled(): void {
		$row = ['app' => 'launchpad', 'declaration' => ['key' => 'live-tiles', 'requiredConfig' => ['live_tile_allowed_hosts']]];

		$outcome = $this->makeResolver(config: ['launchpad.live_tile_allowed_hosts' => '[]'])->resolve($row, true);

		$this->assertSame(expected: 'unconfigured', actual: $outcome['status']);
		$this->assertSame(expected: 6, actual: $outcome['rule']);
	}//end testEmptyJsonListIsNotFilled()

	/**
	 * The control for the scenario above: a list with one host is filled.
	 *
	 * @return void
	 */
	public function testJsonListWithAHostIsFilled(): void {
		$row = ['app' => 'launchpad', 'declaration' => ['key' => 'live-tiles', 'requiredConfig' => ['live_tile_allowed_hosts']]];

		$outcome = $this->makeResolver(config: ['launchpad.live_tile_allowed_hosts' => '["tiles.example.nl"]'])->resolve($row, true);

		$this->assertSame(expected: 'configured', actual: $outcome['status']);
	}//end testJsonListWithAHostIsFilled()

	/**
	 * The keepiq hibp row, switched off and carrying an old error report.
	 *
	 * @param array<string,mixed> $declaration Declaration fields to add or replace.
	 *
	 * @return array<string,mixed>
	 */
	private function hibpRow(array $declaration = []): array {
		return [
			'app' => 'keepiq',
			'declaration' => array_merge(['key' => 'hibp', 'switch' => ['configKey' => 'breach_check_enabled']], $declaration),
			'lastReport' => ['status' => 'error', 'message' => 'HIBP answered 503.', 'at' => '2026-09-14T10:00:00+00:00'],
		];
	}//end hibpRow()

	/**
	 * Rule 2b: a switch that reads as off gives disabled, above an old error report.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#scenario-a-connection-switched-off-reads-disabled
	 */
	public function testConnectionSwitchedOffReadsDisabled(): void {
		$outcome = $this->makeResolver(config: ['keepiq.breach_check_enabled' => false])->resolve($this->hibpRow(), true);

		$this->assertSame(expected: 'disabled', actual: $outcome['status']);
		$this->assertSame(expected: "Switched off in keepiq's settings.", actual: $outcome['statusMessage']);
		$this->assertSame(expected: self::NOW, actual: $outcome['checkedAt']);
		$this->assertSame(expected: 2, actual: $outcome['rule']);
	}//end testConnectionSwitchedOffReadsDisabled()

	/**
	 * The control for the scenario above: the switch on lets the error report show.
	 *
	 * @return void
	 */
	public function testSwitchOnLeavesTheReportStanding(): void {
		$outcome = $this->makeResolver(config: ['keepiq.breach_check_enabled' => true])->resolve($this->hibpRow(), true);

		$this->assertSame(expected: 'error', actual: $outcome['status']);
		$this->assertSame(expected: 4, actual: $outcome['rule']);
	}//end testSwitchOnLeavesTheReportStanding()

	/**
	 * The declared disabledMessage replaces the default, and the time is kept while nothing changed.
	 *
	 * @return void
	 */
	public function testDisabledMessageAndKeptTime(): void {
		$config = ['keepiq.breach_check_enabled' => 'false'];
		$row = $this->hibpRow(declaration: ['disabledMessage' => 'Breach checks are off.']);

		$first = $this->makeResolver(config: $config)->resolve($row, true);
		$this->assertSame(expected: 'Breach checks are off.', actual: $first['statusMessage']);
		$this->assertSame(expected: self::NOW, actual: $first['checkedAt']);

		$row = array_merge($row, ['status' => 'disabled', 'statusMessage' => 'Breach checks are off.', 'checkedAt' => '2026-09-01T08:00:00+00:00']);
		$again = $this->makeResolver(config: $config)->resolve($row, true);
		$this->assertSame(expected: '2026-09-01T08:00:00+00:00', actual: $again['checkedAt']);
	}//end testDisabledMessageAndKeptTime()

	/**
	 * The portaliq geo-db declaration: off only when the provider is none.
	 *
	 * @return array<string,mixed>
	 */
	private function geoRow(): array {
		return [
			'app' => 'portaliq',
			'declaration' => ['key' => 'geo-db', 'switch' => ['configKey' => 'traffic.geo.provider', 'offValues' => ['none']]],
		];
	}//end geoRow()

	/**
	 * With offValues, an unset key is a working default, not a switched-off connection.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#scenario-off-values-leave-a-working-default-alone
	 */
	public function testOffValuesLeaveAWorkingDefaultAlone(): void {
		$outcome = $this->makeResolver()->resolve($this->geoRow(), true);

		$this->assertNotSame(expected: 'disabled', actual: $outcome['status']);
		$this->assertSame(expected: 6, actual: $outcome['rule']);
	}//end testOffValuesLeaveAWorkingDefaultAlone()

	/**
	 * An off value switches the connection off, compared case-insensitively after trimming.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#scenario-an-off-value-switches-the-connection-off
	 */
	public function testAnOffValueSwitchesTheConnectionOff(): void {
		$outcome = $this->makeResolver(config: ['portaliq.traffic.geo.provider' => 'None'])->resolve($this->geoRow(), true);

		$this->assertSame(expected: 'disabled', actual: $outcome['status']);
		$this->assertSame(expected: "Switched off in portaliq's settings.", actual: $outcome['statusMessage']);

		$padded = $this->makeResolver(config: ['portaliq.traffic.geo.provider' => ' NONE '])->resolve($this->geoRow(), true);
		$this->assertSame(expected: 'disabled', actual: $padded['status']);

		$dbip = $this->makeResolver(config: ['portaliq.traffic.geo.provider' => 'dbip'])->resolve($this->geoRow(), true);
		$this->assertSame(expected: 'unconfigured', actual: $dbip['status']);
	}//end testAnOffValueSwitchesTheConnectionOff()

	/**
	 * An unset key without offValues is empty, so the switch reads as off.
	 *
	 * @return void
	 */
	public function testUnsetKeyWithoutOffValuesIsOff(): void {
		$row = ['app' => 'keepiq', 'declaration' => ['switch' => ['configKey' => 'breach_check_enabled']]];

		$outcome = $this->makeResolver()->resolve($row, true);

		$this->assertSame(expected: 'disabled', actual: $outcome['status']);
	}//end testUnsetKeyWithoutOffValuesIsOff()

	/**
	 * An unset key with offValues is off only when the empty string is listed.
	 *
	 * @return void
	 */
	public function testUnsetKeyWithOffValuesIsOffOnlyWhenEmptyIsListed(): void {
		$without = ['app' => 'portaliq', 'declaration' => ['switch' => ['configKey' => 'provider', 'offValues' => ['none']]]];
		$this->assertSame(expected: 'unconfigured', actual: $this->makeResolver()->resolve($without, true)['status']);

		$with = ['app' => 'portaliq', 'declaration' => ['switch' => ['configKey' => 'provider', 'offValues' => ['none', '']]]];
		$this->assertSame(expected: 'disabled', actual: $this->makeResolver()->resolve($with, true)['status']);

		$emptyList = ['app' => 'portaliq', 'declaration' => ['switch' => ['configKey' => 'provider', 'offValues' => []]]];
		$this->assertSame(expected: 'unconfigured', actual: $this->makeResolver()->resolve($emptyList, true)['status']);
	}//end testUnsetKeyWithOffValuesIsOffOnlyWhenEmptyIsListed()

	/**
	 * A stored switch without a usable configKey, written before validation, never reads as off.
	 *
	 * @return void
	 */
	public function testSwitchWithoutAUsableConfigKeyIsIgnored(): void {
		$resolver = $this->makeResolver();

		foreach ([[], ['configKey' => ''], ['configKey' => 5], 'breach_check_enabled'] as $switch) {
			$row = ['app' => 'keepiq', 'declaration' => ['switch' => $switch]];
			$this->assertSame(expected: 'unconfigured', actual: $resolver->resolve($row, true)['status'], message: (string)json_encode($switch));
		}
	}//end testSwitchWithoutAUsableConfigKeyIsIgnored()

	/**
	 * A switch with jsonPath reads inside a JSON setting, stored as text or under the array type.
	 *
	 * @return void
	 */
	public function testSwitchWithJsonPath(): void {
		$row = ['app' => 'keepiq', 'declaration' => ['switch' => ['configKey' => 'breach', 'jsonPath' => 'check.enabled']]];

		$off = $this->makeResolver(config: ['keepiq.breach' => '{"check": {"enabled": false}}'])->resolve($row, true);
		$this->assertSame(expected: 'disabled', actual: $off['status']);

		$on = $this->makeResolver(config: ['keepiq.breach' => '{"check": {"enabled": true}}'])->resolve($row, true);
		$this->assertSame(expected: 'unconfigured', actual: $on['status']);

		$missing = $this->makeResolver(config: ['keepiq.breach' => '{"check": {}}'])->resolve($row, true);
		$this->assertSame(expected: 'disabled', actual: $missing['status']);

		$typed = $this->makeResolver(config: ['keepiq.breach' => ['check' => ['enabled' => false]]])->resolve($row, true);
		$this->assertSame(expected: 'disabled', actual: $typed['status']);

		$offValue = ['app' => 'portaliq', 'declaration' => ['switch' => ['configKey' => 'geo', 'jsonPath' => 'provider', 'offValues' => ['none']]]];
		$named = $this->makeResolver(config: ['portaliq.geo' => '{"provider": "none"}'])->resolve($offValue, true);
		$this->assertSame(expected: 'disabled', actual: $named['status']);

		$list = $this->makeResolver(config: ['portaliq.geo' => '{"provider": ["none"]}'])->resolve($offValue, true);
		$this->assertSame(expected: 'unconfigured', actual: $list['status']);
	}//end testSwitchWithJsonPath()

	/**
	 * An off switch outranks a newer passing probe, a simulated report and a mock adapter.
	 *
	 * @return void
	 */
	public function testOffSwitchOutranksANewerProbeAndASimulatedAdapter(): void {
		$row = [
			'app' => 'keepiq',
			'declaration' => [
				'switch' => ['configKey' => 'breach_check_enabled'],
				'adapter' => ['configKey' => 'breach_adapter'],
				'requiredConfig' => ['breach_api_key'],
			],
			'lastProbe' => ['status' => 'ok', 'message' => 'fine', 'at' => '2026-09-14T11:59:00+00:00'],
			'lastReport' => ['status' => 'simulated', 'message' => 'A mock answers.', 'at' => '2026-09-14T11:00:00+00:00'],
		];
		$config = ['keepiq.breach_check_enabled' => '0', 'keepiq.breach_api_key' => 'secret'];

		$outcome = $this->makeResolver(config: $config)->resolve($row, true);

		$this->assertSame(expected: 'disabled', actual: $outcome['status']);
		$this->assertSame(expected: 2, actual: $outcome['rule']);

		$config['keepiq.breach_check_enabled'] = '1';
		$this->assertSame(expected: 'simulated', actual: $this->makeResolver(config: $config)->resolve($row, true)['status']);
	}//end testOffSwitchOutranksANewerProbeAndASimulatedAdapter()

	/**
	 * Rule 2b applies to a reportedOnly row, and rules 1 and 2 stay above it.
	 *
	 * @return void
	 */
	public function testSwitchAppliesToReportedOnlyRowsBelowRulesOneAndTwo(): void {
		$row = [
			'app' => 'keepiq',
			'declaration' => ['reportedOnly' => true, 'switch' => ['configKey' => 'siem_enabled']],
			'lastReport' => ['status' => 'configured', 'message' => 'Sending.', 'at' => '2026-09-14T11:00:00+00:00'],
		];
		$resolver = $this->makeResolver(config: ['keepiq.siem_enabled' => 'false']);

		$this->assertSame(expected: 'disabled', actual: $resolver->resolve($row, true)['status']);
		$this->assertSame(expected: 1, actual: $resolver->resolve($row, false)['rule']);

		$row['declaration']['available'] = false;
		$declared = $resolver->resolve($row, true);
		$this->assertSame(expected: 'unavailable', actual: $declared['status']);
		$this->assertSame(expected: 2, actual: $declared['rule']);
	}//end testSwitchAppliesToReportedOnlyRowsBelowRulesOneAndTwo()

	/**
	 * A reported disabled is a valid report status and shows through rule 4b.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#scenario-an-app-reports-a-switch-it-keeps-elsewhere
	 */
	public function testReportedDisabledIsShown(): void {
		$row = [
			'app' => 'keepiq',
			'declaration' => ['key' => 'siem', 'reportedOnly' => true],
			'lastReport' => ['status' => 'disabled', 'message' => 'Every SIEM sink is switched off.', 'at' => '2026-09-14T11:00:00+00:00'],
		];

		$outcome = $this->makeResolver()->resolve($row, true);

		$this->assertSame(expected: 'disabled', actual: $outcome['status']);
		$this->assertSame(expected: 'Every SIEM sink is switched off.', actual: $outcome['statusMessage']);
		$this->assertSame(expected: 4, actual: $outcome['rule']);
	}//end testReportedDisabledIsShown()

	/**
	 * Declarations without a switch resolve exactly as before, and a switch that reads on changes nothing.
	 *
	 * @return void
	 */
	public function testDeclarationsWithoutSwitchResolveAsBefore(): void {
		$config = ['dossiq.register' => 'cases', 'dossiq.adapter' => '', 'dossiq.on' => 'true'];
		$rows = [
			'required' => ['app' => 'dossiq', 'declaration' => ['requiredConfig' => ['register']]],
			'simulated' => ['app' => 'dossiq', 'declaration' => ['adapter' => ['configKey' => 'adapter']]],
			'probe' => [
				'app' => 'dossiq',
				'declaration' => [],
				'lastProbe' => ['status' => 'error', 'message' => 'down', 'at' => '2026-09-14T11:00:00+00:00'],
			],
			'nothing' => ['app' => 'dossiq', 'declaration' => ['unconfiguredMessage' => 'Set it.']],
			'unavailable' => ['app' => 'dossiq', 'declaration' => ['available' => false]],
		];
		$expected = [
			'required' => ['configured', 5],
			'simulated' => ['simulated', 3],
			'probe' => ['error', 4],
			'nothing' => ['unconfigured', 6],
			'unavailable' => ['unavailable', 2],
		];
		$resolver = $this->makeResolver(config: $config);

		foreach ($rows as $name => $row) {
			$plain = $resolver->resolve($row, true);
			$this->assertSame(expected: $expected[$name], actual: [$plain['status'], $plain['rule']], message: $name);

			$row['declaration']['disabledMessage'] = 'Off.';
			$this->assertSame(expected: $plain, actual: $resolver->resolve($row, true), message: $name . ' with only a disabledMessage');

			$row['declaration']['switch'] = ['configKey' => 'on'];
			$this->assertSame(expected: $plain, actual: $resolver->resolve($row, true), message: $name . ' with a switch that reads on');
		}
	}//end testDeclarationsWithoutSwitchResolveAsBefore()

	/**
	 * disabled is one of the stored statuses.
	 *
	 * @return void
	 */
	public function testDisabledIsAStoredStatus(): void {
		$this->assertContains(needle: 'disabled', haystack: ConnectionStatusResolver::STATUSES);
		$this->assertCount(expectedCount: 7, haystack: ConnectionStatusResolver::STATUSES);
	}//end testDisabledIsAStoredStatus()
}//end class

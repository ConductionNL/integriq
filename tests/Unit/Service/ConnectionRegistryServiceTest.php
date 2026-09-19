<?php

/**
 * Unit tests for ConnectionRegistryService (connection-registry, umbrella D5 and D6).
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-the-sync-is-idempotent-and-keeps-linked-rows-req-conn-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Integriq\Service\ConnectionDeclarationValidator;
use OCA\Integriq\Service\ConnectionRegistryService;
use OCA\Integriq\Service\ConnectionStatusResolver;
use OCA\Integriq\Service\ConnectionStore;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Sync, removal, report and refresh behaviour against an in-memory store.
 */
class ConnectionRegistryServiceTest extends TestCase {

	/**
	 * Temporary app directories created by a test.
	 *
	 * @var string[]
	 */
	private array $dirs = [];

	/**
	 * The in-memory rows: uuid => data.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $rows = [];

	/**
	 * Saves the store received: [uuid|null, data].
	 *
	 * @var array<int,array{0:?string,1:array<string,mixed>}>
	 */
	private array $saves = [];

	/**
	 * Uuids the store deleted.
	 *
	 * @var string[]
	 */
	private array $deletes = [];

	/**
	 * Remove temporary app directories.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->dirs as $dir) {
			@unlink($dir . '/lib/Settings/connections.json');
			@rmdir($dir . '/lib/Settings');
			@rmdir($dir . '/lib');
			@rmdir($dir);
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * Make an app directory holding a declaration file.
	 *
	 * @param mixed $declaration The decoded file, or a raw string.
	 *
	 * @return string The app path.
	 */
	private function appDir(mixed $declaration): string {
		$dir = sys_get_temp_dir() . '/integriq-conn-' . bin2hex(random_bytes(6));
		mkdir($dir . '/lib/Settings', 0777, true);
		$content = $declaration;
		if (is_string($declaration) === false) {
			$content = (string)json_encode($declaration);
		}

		file_put_contents($dir . '/lib/Settings/connections.json', $content);
		$this->dirs[] = $dir;

		return $dir;
	}//end appDir()

	/**
	 * A two-entry dossiq declaration.
	 *
	 * @return array<string,mixed>
	 */
	private function dossiqDeclaration(): array {
		return [
			'app' => 'dossiq',
			'connections' => [
				['key' => 'zgw', 'title' => 'ZGW APIs', 'order' => 10, 'requiredConfig' => ['register']],
				['key' => 'brp', 'title' => 'BRP', 'order' => 80, 'sourceTemplate' => 'brp-haalcentraal'],
			],
		];
	}//end dossiqDeclaration()

	/**
	 * Build the service over an app manager and an in-memory store.
	 *
	 * @param array<string,string> $appPaths App id => path of every enabled app.
	 * @param LoggerInterface|null $logger A logger double, or null for a silent one.
	 * @param string[] $disabled App ids that are installed but disabled.
	 * @param array<string,string> $config App config values as "app.key" => value.
	 * @param string $now The fixed clock value.
	 *
	 * @return ConnectionRegistryService
	 */
	private function makeService(
		array $appPaths,
		?LoggerInterface $logger = null,
		array $disabled = [],
		array $config = [],
		string $now = '2026-09-14T12:00:00+00:00'
	): ConnectionRegistryService {
		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('getEnabledApps')->willReturn(array_keys($appPaths));
		$appManager->method('getAppPath')->willReturnCallback(
			static function (string $app) use ($appPaths): string {
				if (isset($appPaths[$app]) === false) {
					throw new \OCP\App\AppPathNotFoundException('no ' . $app);
				}

				return $appPaths[$app];
			}
		);
		$appManager->method('getAppVersion')->willReturn('1.2.0');
		$appManager->method('isEnabledForAnyone')->willReturnCallback(
			static fn (string $app): bool => in_array($app, $disabled, true) === false
		);

		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key): string => $config[$app . '.' . $key] ?? ''
		);
		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable($now));

		return new ConnectionRegistryService(
			appManager: $appManager,
			validator: new ConnectionDeclarationValidator(),
			resolver: new ConnectionStatusResolver(appConfig: $appConfig, timeFactory: $time),
			store: $this->makeStore(),
			logger: $logger ?? $this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end makeService()

	/**
	 * An in-memory ConnectionStore double. `payload()` stays real.
	 *
	 * @return ConnectionStore&MockObject
	 */
	private function makeStore(): ConnectionStore {
		$store = $this->getMockBuilder(className: ConnectionStore::class)
			->disableOriginalConstructor()
			->onlyMethods(['findRows', 'save', 'delete'])
			->getMock();

		$store->method('findRows')->willReturnCallback(
			function (?string $app = null): array {
				$rows = [];
				foreach ($this->rows as $uuid => $data) {
					if ($app === null || $data['app'] === $app) {
						$rows[] = ['uuid' => $uuid, 'data' => $data];
					}
				}

				return $rows;
			}
		);
		$store->method('save')->willReturnCallback(
			function (array $data, ?string $uuid = null): string {
				$this->saves[] = [$uuid, $data];
				$uuid = $uuid ?? 'uuid-' . count($this->rows);
				$this->rows[$uuid] = $data;
				return $uuid;
			}
		);
		$store->method('delete')->willReturnCallback(
			function (string $uuid): void {
				$this->deletes[] = $uuid;
				unset($this->rows[$uuid]);
			}
		);

		return $store;
	}//end makeStore()

	/**
	 * A valid file becomes one row per entry, slugged connection-{app}-{key}.
	 *
	 * @return void
	 */
	public function testValidFileBecomesOneRowPerEntry(): void {
		$service = $this->makeService(appPaths: ['dossiq' => $this->appDir(declaration: $this->dossiqDeclaration())]);

		$summary = $service->sync();

		$this->assertSame(expected: 2, actual: $summary['created']);
		$this->assertCount(expectedCount: 2, haystack: $this->rows);
		$slugs = array_column(array_values($this->rows), 'slug');
		$this->assertSame(expected: ['connection-dossiq-zgw', 'connection-dossiq-brp'], actual: $slugs);
		$first = array_values($this->rows)[0];
		$this->assertSame(expected: 'dossiq', actual: $first['app']);
		$this->assertSame(expected: '1.2.0', actual: $first['declaredVersion']);
		$this->assertSame(expected: 'unconfigured', actual: $first['status']);
	}//end testValidFileBecomesOneRowPerEntry()

	/**
	 * Running the sync twice writes nothing the second time.
	 *
	 * @return void
	 */
	public function testSyncIsIdempotent(): void {
		$service = $this->makeService(appPaths: ['dossiq' => $this->appDir(declaration: $this->dossiqDeclaration())]);
		$service->sync();
		$uuids = array_keys($this->rows);
		$this->saves = [];

		$summary = $service->sync();

		$this->assertSame(expected: [], actual: $this->saves);
		$this->assertSame(expected: [], actual: $this->deletes);
		$this->assertSame(expected: 2, actual: $summary['unchanged']);
		$this->assertSame(expected: $uuids, actual: array_keys($this->rows));
	}//end testSyncIsIdempotent()

	/**
	 * An invalid file is skipped whole, and the error names app and path.
	 *
	 * @return void
	 */
	public function testInvalidFileIsSkippedWhole(): void {
		$declaration = $this->dossiqDeclaration();
		unset($declaration['connections'][1]['title']);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			$this->stringContains(string: 'skipped the connections.json'),
			$this->callback(
				callback: static fn (array $context): bool => $context['declaringApp'] === 'dossiq'
					&& str_contains($context['errors'], '/connections/1/title')
			)
		);

		$summary = $this->makeService(appPaths: ['dossiq' => $this->appDir(declaration: $declaration)], logger: $logger)->sync();

		$this->assertSame(expected: [], actual: $this->saves);
		$this->assertSame(expected: ['dossiq'], actual: $summary['skipped']);
	}//end testInvalidFileIsSkippedWhole()

	/**
	 * A file that is not JSON is skipped with an error.
	 *
	 * @return void
	 */
	public function testBrokenJsonIsSkipped(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('error');

		$this->makeService(appPaths: ['dossiq' => $this->appDir(declaration: '{"app": "dossiq",')], logger: $logger)->sync();

		$this->assertSame(expected: [], actual: $this->saves);
	}//end testBrokenJsonIsSkipped()

	/**
	 * A file claiming another app's id is refused and changes no row.
	 *
	 * @return void
	 */
	public function testFileClaimingAnotherAppIsRefused(): void {
		$this->rows['existing'] = ['app' => 'dossiq', 'key' => 'zgw', 'title' => 'ZGW APIs', 'declaration' => []];

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			$this->stringContains(string: 'claims the app id'),
			$this->callback(callback: static fn (array $context): bool => $context['declaringApp'] === 'pipelinq' && $context['claimedApp'] === 'dossiq')
		);

		$this->makeService(appPaths: ['pipelinq' => $this->appDir(declaration: $this->dossiqDeclaration())], logger: $logger)->sync();

		$this->assertSame(expected: [], actual: $this->saves);
		$this->assertSame(expected: [], actual: $this->deletes);
	}//end testFileClaimingAnotherAppIsRefused()

	/**
	 * A removed key without a source is deleted.
	 *
	 * @return void
	 */
	public function testRemovedKeyWithoutSourceIsDeleted(): void {
		$service = $this->makeService(appPaths: ['dossiq' => $this->appDir(declaration: $this->dossiqDeclaration())]);
		$service->sync();
		$brpUuid = array_search('brp', array_column($this->rows, 'key', null), true);
		$brpUuid = array_keys($this->rows)[$brpUuid];

		$declaration = $this->dossiqDeclaration();
		unset($declaration['connections'][1]);
		$this->makeService(appPaths: ['dossiq' => $this->appDir(declaration: $declaration)])->sync();

		$this->assertSame(expected: [$brpUuid], actual: $this->deletes);
		$this->assertCount(expectedCount: 1, haystack: $this->rows);
	}//end testRemovedKeyWithoutSourceIsDeleted()

	/**
	 * A removed key with a linked source is kept and marked unavailable, and
	 * stays so after a later resolve with a passing probe.
	 *
	 * @return void
	 */
	public function testRemovedKeyWithSourceIsKept(): void {
		$this->makeService(appPaths: ['dossiq' => $this->appDir(declaration: $this->dossiqDeclaration())])->sync();
		$brpUuid = '';
		foreach ($this->rows as $uuid => $data) {
			if ($data['key'] === 'brp') {
				$brpUuid = $uuid;
				$this->rows[$uuid]['source'] = 'a1b2c3d4-0000-4000-8000-000000000001';
			}
		}

		$declaration = $this->dossiqDeclaration();
		unset($declaration['connections'][1]);
		$service = $this->makeService(appPaths: ['dossiq' => $this->appDir(declaration: $declaration)]);
		$service->sync();

		$this->assertSame(expected: [], actual: $this->deletes);
		$this->assertSame(expected: 'a1b2c3d4-0000-4000-8000-000000000001', actual: $this->rows[$brpUuid]['source']);
		$this->assertSame(expected: 'unavailable', actual: $this->rows[$brpUuid]['status']);
		$this->assertSame(expected: 'No longer declared by dossiq.', actual: $this->rows[$brpUuid]['statusMessage']);

		$row = $this->rows[$brpUuid];
		$row['lastProbe'] = ['status' => 'ok', 'message' => 'HTTP 200', 'at' => '2026-09-14T13:00:00+00:00'];
		$this->assertSame(expected: 'unavailable', actual: $service->resolveRow($row)['status']);
	}//end testRemovedKeyWithSourceIsKept()

	/**
	 * A report reaches the row as lastReport and the row is resolved.
	 *
	 * @return void
	 */
	public function testReportReachesTheRow(): void {
		$service = $this->makeService(appPaths: ['dossiq' => $this->appDir(declaration: $this->dossiqDeclaration())]);
		$service->sync();
		$this->saves = [];

		$this->assertTrue(condition: $service->report('dossiq', 'brp', 'configured', 'Logged in'));

		$this->assertCount(expectedCount: 1, haystack: $this->saves);
		$saved = $this->saves[0][1];
		$this->assertSame(
			expected: ['status' => 'configured', 'message' => 'Logged in', 'at' => '2026-09-14T12:00:00+00:00'],
			actual: $saved['lastReport']
		);
		$this->assertSame(expected: 'configured', actual: $saved['status']);
		$this->assertSame(expected: 'Logged in', actual: $saved['statusMessage']);
	}//end testReportReachesTheRow()

	/**
	 * A report for an undeclared key is refused with a warning and changes nothing.
	 *
	 * @return void
	 */
	public function testReportForUnknownKeyIsRefused(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			$this->stringContains(string: 'no such connection is declared'),
			$this->callback(callback: static fn (array $context): bool => $context['reportingApp'] === 'dossiq' && $context['key'] === 'mailbox')
		);

		$service = $this->makeService(appPaths: ['dossiq' => $this->appDir(declaration: $this->dossiqDeclaration())], logger: $logger);
		$service->sync();
		$this->saves = [];

		$this->assertFalse(condition: $service->report('dossiq', 'mailbox', 'configured', 'Logged in'));
		$this->assertSame(expected: [], actual: $this->saves);
	}//end testReportForUnknownKeyIsRefused()

	/**
	 * A report with an unknown status is refused with a warning.
	 *
	 * @return void
	 */
	public function testReportWithUnknownStatusIsRefused(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$service = $this->makeService(appPaths: ['dossiq' => $this->appDir(declaration: $this->dossiqDeclaration())], logger: $logger);
		$service->sync();
		$this->saves = [];

		$this->assertFalse(condition: $service->report('dossiq', 'brp', 'green', 'fine'));
		$this->assertSame(expected: [], actual: $this->saves);
	}//end testReportWithUnknownStatusIsRefused()

	/**
	 * Syncing a disabled app resolves its rows to D4 rule 1.
	 *
	 * @return void
	 */
	public function testSyncOfDisabledAppResolvesRuleOne(): void {
		$this->rows['r1'] = ['app' => 'shillinq', 'key' => 'bank', 'title' => 'Bank', 'declaration' => [], 'status' => 'configured'];

		$saved = $this->makeService(appPaths: [], logger: null, disabled: ['shillinq'])->sync('shillinq');

		$this->assertSame(expected: 0, actual: $saved['created']);
		$this->assertSame(expected: 'unavailable', actual: $this->rows['r1']['status']);
		$this->assertSame(expected: 'The shillinq app is disabled.', actual: $this->rows['r1']['statusMessage']);
	}//end testSyncOfDisabledAppResolvesRuleOne()

	/**
	 * Refresh saves only rows whose status changed, and honours the key filter.
	 *
	 * @return void
	 */
	public function testRefreshSavesOnlyChangedRows(): void {
		$service = $this->makeService(appPaths: ['dossiq' => $this->appDir(declaration: $this->dossiqDeclaration())]);
		$service->sync();
		$this->saves = [];

		$this->assertSame(expected: 0, actual: $service->refresh('dossiq'));
		$this->assertSame(expected: 0, actual: $service->refresh('dossiq', 'zgw'));
		$this->assertSame(expected: [], actual: $this->saves);
	}//end testRefreshSavesOnlyChangedRows()

	/**
	 * The hourly job syncs an app whose version moved, and an app with a file but no rows.
	 *
	 * @return void
	 */
	public function testSyncChangedDeclarationsPicksMovedVersions(): void {
		$service = $this->makeService(appPaths: ['dossiq' => $this->appDir(declaration: $this->dossiqDeclaration())]);

		$this->assertSame(expected: ['dossiq'], actual: $service->syncChangedDeclarations());
		$this->assertCount(expectedCount: 2, haystack: $this->rows);

		$this->assertSame(expected: [], actual: $service->syncChangedDeclarations());

		foreach (array_keys($this->rows) as $uuid) {
			$this->rows[$uuid]['declaredVersion'] = '1.1.0';
		}

		$this->assertSame(expected: ['dossiq'], actual: $service->syncChangedDeclarations());
		$this->assertSame(expected: '1.2.0', actual: array_values($this->rows)[0]['declaredVersion']);
	}//end testSyncChangedDeclarationsPicksMovedVersions()

	/**
	 * An app without a declaration file and without rows is left alone.
	 *
	 * @return void
	 */
	public function testAppWithoutFileIsIgnored(): void {
		$dir = sys_get_temp_dir() . '/integriq-conn-none-' . bin2hex(random_bytes(4));

		$summary = $this->makeService(appPaths: ['files' => $dir])->sync();

		$this->assertSame(expected: 0, actual: $summary['created']);
		$this->assertSame(expected: [], actual: $summary['skipped']);
		$this->assertSame(expected: [], actual: $this->saves);
	}//end testAppWithoutFileIsIgnored()

	/**
	 * A report may carry limited, and the row then reads limited.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#scenario-an-app-reports-a-connection-that-works-in-part
	 */
	public function testLimitedReportIsAccepted(): void {
		$service = $this->makeService(appPaths: ['dossiq' => $this->appDir(declaration: $this->dossiqDeclaration())]);
		$service->sync();
		$this->saves = [];

		$message = 'Preview API: posting works, reading replies does not.';
		$this->assertTrue(condition: $service->report('dossiq', 'brp', 'limited', $message));

		$this->assertCount(expectedCount: 1, haystack: $this->saves);
		$this->assertSame(expected: 'limited', actual: $this->saves[0][1]['lastReport']['status']);
		$this->assertSame(expected: 'limited', actual: $this->saves[0][1]['status']);
		$this->assertSame(expected: $message, actual: $this->saves[0][1]['statusMessage']);
	}//end testLimitedReportIsAccepted()

	/**
	 * A report may carry disabled, for a switch the app keeps outside app config.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#scenario-an-app-reports-a-switch-it-keeps-elsewhere
	 */
	public function testDisabledReportIsAccepted(): void {
		$declaration = ['app' => 'keepiq', 'connections' => [['key' => 'siem', 'title' => 'SIEM', 'reportedOnly' => true]]];
		$service = $this->makeService(appPaths: ['keepiq' => $this->appDir(declaration: $declaration)]);
		$service->sync();
		$this->saves = [];

		$message = 'Every SIEM sink is switched off.';
		$this->assertTrue(condition: $service->report('keepiq', 'siem', 'disabled', $message));

		$this->assertCount(expectedCount: 1, haystack: $this->saves);
		$this->assertSame(expected: 'disabled', actual: $this->saves[0][1]['lastReport']['status']);
		$this->assertSame(expected: 'disabled', actual: $this->saves[0][1]['status']);
		$this->assertSame(expected: $message, actual: $this->saves[0][1]['statusMessage']);
	}//end testDisabledReportIsAccepted()

	/**
	 * A declaration with a switch syncs, and the row reads disabled while the switch is off.
	 *
	 * @return void
	 */
	public function testSwitchedOffDeclarationSyncsToDisabled(): void {
		$declaration = [
			'app' => 'keepiq',
			'connections' => [['key' => 'hibp', 'title' => 'Have I Been Pwned', 'switch' => ['configKey' => 'breach_check_enabled']]],
		];
		$service = $this->makeService(appPaths: ['keepiq' => $this->appDir(declaration: $declaration)], config: ['keepiq.breach_check_enabled' => 'false']);

		$service->sync();

		$this->assertCount(expectedCount: 1, haystack: $this->rows);
		$row = array_values($this->rows)[0];
		$this->assertSame(expected: 'disabled', actual: $row['status']);
		$this->assertSame(expected: "Switched off in keepiq's settings.", actual: $row['statusMessage']);
	}//end testSwitchedOffDeclarationSyncsToDisabled()

	/**
	 * A key set with occ, which sends no refresh event, shows on the next refresh of an unlinked row.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#scenario-a-key-set-with-occ-shows-within-the-hour
	 */
	public function testRefreshPicksUpAKeySetWithOcc(): void {
		$this->rows['r1'] = [
			'app' => 'dossiq',
			'key' => 'zgw',
			'title' => 'ZGW APIs',
			'declaration' => ['key' => 'zgw', 'title' => 'ZGW APIs', 'requiredConfig' => ['register']],
			'status' => 'unconfigured',
			'statusMessage' => 'Not checked yet.',
			'checkedAt' => null,
		];

		$service = $this->makeService(appPaths: [], logger: null, disabled: [], config: ['dossiq.register' => 'dossiq']);

		$this->assertSame(expected: 1, actual: $service->refresh());
		$this->assertSame(expected: 'configured', actual: $this->rows['r1']['status']);
		$this->assertSame(expected: 'Required settings are filled.', actual: $this->rows['r1']['statusMessage']);
	}//end testRefreshPicksUpAKeySetWithOcc()

	/**
	 * A stored zaakafhandelapp row that reports an error from 10:00.
	 *
	 * @param string $key The connection key.
	 *
	 * @return array<string,mixed>
	 */
	private function zrcRow(string $key = 'zrc'): array {
		return [
			'app' => 'zaakafhandelapp',
			'key' => $key,
			'title' => strtoupper($key),
			'declaration' => ['key' => $key, 'title' => strtoupper($key), 'requiredConfig' => [$key . '_url']],
			'lastReport' => ['status' => 'error', 'message' => 'Connection refused', 'at' => '2026-09-14T10:00:00+00:00'],
			'status' => 'error',
			'statusMessage' => 'Connection refused',
			'checkedAt' => '2026-09-14T10:00:00+00:00',
		];
	}//end zrcRow()

	/**
	 * A save that sends a refresh stamps refreshedAt and retires the older error, which stays on the row.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#scenario-a-save-retires-an-older-error
	 */
	public function testSaveRetiresAnOlderError(): void {
		$this->rows['r1'] = $this->zrcRow();

		$service = $this->makeService(
			appPaths: [],
			logger: null,
			disabled: [],
			config: ['zaakafhandelapp.zrc_url' => 'https://zrc.example.nl'],
			now: '2026-09-14T10:05:00+00:00'
		);

		$this->assertSame(expected: 1, actual: $service->refreshRequested('zaakafhandelapp', 'zrc'));
		$this->assertSame(expected: '2026-09-14T10:05:00+00:00', actual: $this->rows['r1']['refreshedAt']);
		$this->assertSame(expected: 'configured', actual: $this->rows['r1']['status']);
		$this->assertSame(expected: 'Required settings are filled.', actual: $this->rows['r1']['statusMessage']);
		$this->assertSame(
			expected: ['status' => 'error', 'message' => 'Connection refused', 'at' => '2026-09-14T10:00:00+00:00'],
			actual: $this->rows['r1']['lastReport']
		);
	}//end testSaveRetiresAnOlderError()

	/**
	 * After the refresh, a new report counts again with its own time.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#scenario-a-report-after-the-refresh-counts-again
	 */
	public function testReportAfterTheRefreshCountsAgain(): void {
		$this->rows['r1'] = array_merge(
			$this->zrcRow(),
			[
				'refreshedAt' => '2026-09-14T10:05:00+00:00',
				'status' => 'configured',
				'statusMessage' => 'Required settings are filled.',
				'checkedAt' => '2026-09-14T10:05:00+00:00',
			]
		);

		$service = $this->makeService(
			appPaths: [],
			logger: null,
			disabled: [],
			config: ['zaakafhandelapp.zrc_url' => 'https://zrc.example.nl'],
			now: '2026-09-14T10:07:00+00:00'
		);

		$this->assertTrue(condition: $service->report('zaakafhandelapp', 'zrc', 'error', 'Connection refused'));
		$this->assertSame(expected: 'error', actual: $this->rows['r1']['status']);
		$this->assertSame(expected: '2026-09-14T10:07:00+00:00', actual: $this->rows['r1']['checkedAt']);
		$this->assertSame(expected: '2026-09-14T10:05:00+00:00', actual: $this->rows['r1']['refreshedAt']);
	}//end testReportAfterTheRefreshCountsAgain()

	/**
	 * A refresh without a key stamps every row of that app and no row of another app.
	 *
	 * @return void
	 */
	public function testRefreshWithoutKeyStampsEveryRowOfTheApp(): void {
		$this->rows['r1'] = $this->zrcRow(key: 'zrc');
		$this->rows['r2'] = $this->zrcRow(key: 'drc');
		$this->rows['r3'] = array_merge($this->zrcRow(key: 'zgw'), ['app' => 'dossiq']);

		$service = $this->makeService(appPaths: [], logger: null, disabled: [], config: [], now: '2026-09-14T10:05:00+00:00');

		$this->assertSame(expected: 2, actual: $service->refreshRequested('zaakafhandelapp'));
		$this->assertSame(expected: '2026-09-14T10:05:00+00:00', actual: $this->rows['r1']['refreshedAt']);
		$this->assertSame(expected: '2026-09-14T10:05:00+00:00', actual: $this->rows['r2']['refreshedAt']);
		$this->assertSame(expected: 'unconfigured', actual: $this->rows['r2']['status']);
		$this->assertArrayNotHasKey(key: 'refreshedAt', array: $this->rows['r3']);
		$this->assertSame(expected: 'error', actual: $this->rows['r3']['status']);
	}//end testRefreshWithoutKeyStampsEveryRowOfTheApp()

	/**
	 * A refresh with a key leaves the app's other rows alone.
	 *
	 * @return void
	 */
	public function testRefreshWithKeyStampsOnlyThatRow(): void {
		$this->rows['r1'] = $this->zrcRow(key: 'zrc');
		$this->rows['r2'] = $this->zrcRow(key: 'drc');

		$service = $this->makeService(appPaths: [], logger: null, disabled: [], config: [], now: '2026-09-14T10:05:00+00:00');

		$this->assertSame(expected: 1, actual: $service->refreshRequested('zaakafhandelapp', 'zrc'));
		$this->assertSame(expected: '2026-09-14T10:05:00+00:00', actual: $this->rows['r1']['refreshedAt']);
		$this->assertArrayNotHasKey(key: 'refreshedAt', array: $this->rows['r2']);
		$this->assertSame(expected: 'error', actual: $this->rows['r2']['status']);
	}//end testRefreshWithKeyStampsOnlyThatRow()

	/**
	 * A sync, a report and the plain resolve keep the stored refreshedAt; only a refresh request writes it.
	 *
	 * @return void
	 */
	public function testSyncReportAndPlainRefreshLeaveRefreshedAtAlone(): void {
		$service = $this->makeService(appPaths: ['dossiq' => $this->appDir(declaration: $this->dossiqDeclaration())]);
		$service->sync();
		foreach (array_keys($this->rows) as $uuid) {
			$this->rows[$uuid]['refreshedAt'] = '2026-09-14T10:05:00+00:00';
			$this->rows[$uuid]['declaredVersion'] = '1.1.0';
		}

		$this->saves = [];
		$service->sync();
		$this->assertCount(expectedCount: 2, haystack: $this->saves);

		$this->assertTrue(condition: $service->report('dossiq', 'brp', 'error', 'HTTP 503'));
		foreach (array_keys($this->rows) as $uuid) {
			$this->rows[$uuid]['status'] = 'stale';
		}

		$this->assertSame(expected: 2, actual: $service->refresh());

		$this->assertCount(expectedCount: 5, haystack: $this->saves);
		foreach ($this->saves as $save) {
			$this->assertSame(expected: '2026-09-14T10:05:00+00:00', actual: $save[1]['refreshedAt']);
		}
	}//end testSyncReportAndPlainRefreshLeaveRefreshedAtAlone()

	/**
	 * refreshedAt is a stored row property, so a change to it alone is written.
	 *
	 * @return void
	 */
	public function testRefreshedAtIsAStoredProperty(): void {
		$this->assertContains(needle: 'refreshedAt', haystack: ConnectionStore::PROPERTIES);
	}//end testRefreshedAtIsAStoredProperty()
}//end class

<?php

namespace Unit\Service;

use OCA\Integriq\Service\DefaultConnectorService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The shipped default source connectors (`$gate: "default-connectors"` fragments
 * under `register.d/`).
 *
 * `InitializeRegister` skips every `$gate`-tagged fragment outright (see its
 * merge loop), so this service is the ONLY path these connectors can reach an
 * instance through — every failure here must THROW rather than return empty,
 * same reasoning as DemoDataServiceTest.
 */
class DefaultConnectorServiceTest extends TestCase {
	private string $appPath;
	private IAppManager $appManager;
	private ContainerInterface $container;

	protected function setUp(): void {
		$this->appPath = sys_get_temp_dir() . '/or-connectors-' . uniqid();
		mkdir($this->appPath . '/lib/Settings/register.d', 0777, true);

		$this->appManager = $this->createMock(IAppManager::class);
		$this->appManager->method('getAppPath')->willReturn($this->appPath);
		$this->appManager->method('getAppVersion')->willReturn('1.2.3');
		$this->appManager->method('getInstalledApps')->willReturn(['openregister']);

		$this->container = $this->createMock(ContainerInterface::class);
	}

	protected function tearDown(): void {
		$dir = $this->fragmentDir();
		foreach ((glob($dir . '/*.json') ?: []) as $file) {
			unlink($file);
		}

		@rmdir($dir);
		@rmdir($this->appPath . '/lib/Settings');
		@rmdir($this->appPath . '/lib');
		@rmdir($this->appPath);
	}

	private function fragmentDir(): string {
		return $this->appPath . '/lib/Settings/register.d';
	}

	private function writeFragment(string $name, array $data): void {
		file_put_contents($this->fragmentDir() . '/' . $name, json_encode($data));
	}

	private function service(): DefaultConnectorService {
		return new DefaultConnectorService(
			$this->appManager,
			$this->container,
			$this->createMock(LoggerInterface::class)
		);
	}

	public function testIsAvailableIsFalseWithoutAnyGatedFragment(): void {
		$this->assertFalse($this->service()->isAvailable());
	}

	public function testIsAvailableIsFalseWhenFragmentsExistButNoneAreGated(): void {
		// A core schema fragment sitting next to this service's files must not
		// make it think it has something to offer.
		$this->writeFragment('core-schema.json', ['components' => ['schemas' => []]]);

		$this->assertFalse($this->service()->isAvailable());
	}

	public function testIsAvailableIsTrueWithAGatedFragment(): void {
		$this->writeFragment('demo-connector.json', ['$gate' => 'default-connectors', 'components' => ['objects' => []]]);

		$this->assertTrue($this->service()->isAvailable());
	}

	public function testDecliningIsOfferedEvenWhenNoConnectorShips(): void {
		$choices = $this->service()->listChoices();

		$this->assertSame(['none'], array_column($choices, 'id'));
		$this->assertNotSame('', $choices[0]['description']);
		$this->assertNotSame('', $choices[0]['icon']);
	}

	public function testTheShippedConnectorsAreOfferedWithTheCountTheyActuallyCarry(): void {
		// Mirrors register.d's real shape: several gated connector fragments,
		// plus an ungated core-schema fragment that must not be counted.
		$this->writeFragment('austender.json', [
			'$gate' => 'default-connectors',
			'components' => ['objects' => [['a' => 1], ['b' => 2]]],
		]);
		$this->writeFragment('kvk.json', [
			'$gate' => 'default-connectors',
			'components' => ['objects' => [['c' => 3]]],
		]);
		$this->writeFragment('core-schema.json', [
			'components' => ['objects' => [['d' => 4]]],
		]);

		$choices = $this->service()->listChoices();

		$this->assertSame(['none', 'default-connectors'], array_column($choices, 'id'));
		$this->assertSame(3, $choices[1]['objectCount']);
	}

	public function testAMalformedFragmentIsSkippedButGatedOnesStillCount(): void {
		$this->writeFragment('austender.json', [
			'$gate' => 'default-connectors',
			'components' => ['objects' => [['a' => 1]]],
		]);
		file_put_contents($this->fragmentDir() . '/broken.json', 'not json at all');

		$choices = $this->service()->listChoices();

		$this->assertSame(['none', 'default-connectors'], array_column($choices, 'id'));
		$this->assertSame(1, $choices[1]['objectCount']);
	}

	public function testInstallThrowsWhenNoConnectorShips(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/No default source connectors/');

		$this->service()->install();
	}

	public function testInstallNamesTheMissingAppWhenOpenRegisterIsAbsent(): void {
		$this->writeFragment('austender.json', [
			'$gate' => 'default-connectors',
			'components' => ['objects' => [['a' => 1]]],
		]);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->appManager->method('getAppPath')->willReturn($this->appPath);
		$this->appManager->method('getInstalledApps')->willReturn([]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/OpenRegister/');

		$this->service()->install();
	}

	public function testInstallMergesOnlyGatedFragmentsUnderItsOwnNamespace(): void {
		$this->writeFragment('austender.json', [
			'$gate' => 'default-connectors',
			'components' => ['objects' => [['a' => 1], ['b' => 2]]],
		]);
		$this->writeFragment('kvk.json', [
			'$gate' => 'default-connectors',
			'components' => ['objects' => [['c' => 3]]],
		]);
		// Never merged in: no `$gate`, so a core-schema fragment living in the
		// same directory must not leak into the connector-only import.
		$this->writeFragment('core-schema.json', [
			'components' => ['objects' => [['d' => 4]]],
		]);

		$importer = new class {
			public array $seen = [];

			public function importFromApp(string $appId, array $data, string $version, bool $force): array {
				$this->seen = ['appId' => $appId, 'data' => $data, 'force' => $force];

				return ['registers' => [1], 'schemas' => [1, 1]];
			}
		};
		$this->container->method('get')->willReturn($importer);

		$result = $this->service()->install();

		$this->assertSame(3, $result['objects']);
		$this->assertSame(1, $result['registers']);
		$this->assertSame(2, $result['schemas']);

		// Its own namespace, so this import cannot mask — or be masked by — the
		// real configuration import or the demo-data import.
		$this->assertSame('integriq.connectors', $importer->seen['appId']);
		$this->assertTrue($importer->seen['force']);

		$mergedObjects = $importer->seen['data']['components']['objects'] ?? [];
		$this->assertCount(3, $mergedObjects, 'the ungated core-schema fragment must not be merged in');
	}
}

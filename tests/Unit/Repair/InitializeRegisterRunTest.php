<?php

/**
 * Characterisation tests for InitializeRegister::run().
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/repair-and-app-boot/spec.md#requirement-register-descriptor-import-via-or-configurationservice-on-install-upgrade-req-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Repair;

use OCA\Integriq\Repair\InitializeRegister;
use OCA\OpenRegister\Service\ConfigurationService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the observable behaviour of the register import repair step: the
 * messages it writes, the log calls it makes and the arguments it hands to
 * OpenRegister's ConfigurationService.
 */
final class InitializeRegisterRunTest extends TestCase {

	/**
	 * Load the ConfigurationService stand-in when OpenRegister is absent.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		require_once __DIR__ . '/../../stubs/openregister-service-stubs.php';
	}//end setUp()

	/**
	 * The fragment-signature suffix the real register.d directory produces.
	 *
	 * @return string
	 */
	private function expectedFragmentSuffix(): string {
		$dir = __DIR__ . '/../../../lib/Settings/register.d';
		$files = glob($dir . '/*.json');
		sort($files);
		$sig = '';
		foreach ($files as $file) {
			$content = (string)file_get_contents($file);
			json_decode($content, true);
			if (json_last_error() !== JSON_ERROR_NONE) {
				continue;
			}

			$sig .= basename($file) . ':' . md5($content) . ';';
		}

		if ($sig === '') {
			return '';
		}

		return '+frag.' . substr(md5($sig), 0, 8);
	}//end expectedFragmentSuffix()

	/**
	 * Build the step around a container that answers with the given service.
	 *
	 * @param object|\Throwable $service The resolved service, or what the container throws.
	 * @param LoggerInterface   $logger  The logger mock.
	 *
	 * @return InitializeRegister
	 */
	private function step(object $service, LoggerInterface $logger): InitializeRegister {
		$container = $this->createMock(ContainerInterface::class);
		if ($service instanceof \Throwable) {
			$container->method('get')->willThrowException($service);
		} else {
			$container->method('get')
				->with('OCA\\OpenRegister\\Service\\ConfigurationService')
				->willReturn($service);
		}

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')
			->with('integriq', 'installed_version', '1.0.0')
			->willReturn('2.3.4');

		return new InitializeRegister($container, $appConfig, $logger);
	}//end step()

	/**
	 * A successful import hands the merged descriptor and the fragment-tagged version over.
	 *
	 * @return void
	 */
	public function testImportsMergedDescriptorWithFragmentTaggedVersion(): void {
		$captured = [];
		$service  = $this->createMock(ConfigurationService::class);
		$service->expects($this->once())
			->method('importFromApp')
			->willReturnCallback(
				static function (string $appId, array $data, string $version) use (&$captured): void {
					$captured = ['appId' => $appId, 'data' => $data, 'version' => $version];
				}
			);

		$output = $this->createMock(IOutput::class);
		$infos  = [];
		$output->method('info')->willReturnCallback(
			static function (string $message) use (&$infos): void {
				$infos[] = $message;
			}
		);
		$output->expects($this->never())->method('warning');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');

		$this->step($service, $logger)->run($output);

		$this->assertSame('integriq', $captured['appId']);
		$this->assertSame('2.3.4' . $this->expectedFragmentSuffix(), $captured['version']);
		$this->assertIsArray($captured['data']);
		$this->assertArrayHasKey('components', $captured['data']);
		$this->assertSame(
			[
				'Integriq: initializing register + schemas via OR ConfigurationService…',
				'Integriq: register descriptor imported (existing schemas reused).',
			],
			$infos
		);
	}//end testImportsMergedDescriptorWithFragmentTaggedVersion()

	/**
	 * A fragment's content lands in the descriptor handed to the import.
	 *
	 * @return void
	 */
	public function testFragmentSchemasAreMergedIntoTheDescriptor(): void {
		$base     = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/integriq_register.json'), true);
		$captured = [];
		$service  = $this->createMock(ConfigurationService::class);
		$service->method('importFromApp')->willReturnCallback(
			static function (string $appId, array $data, string $version) use (&$captured): void {
				$captured = $data;
			}
		);

		$this->step($service, $this->createMock(LoggerInterface::class))->run($this->createMock(IOutput::class));

		$this->assertGreaterThanOrEqual(
			count((array)($base['components']['schemas'] ?? [])),
			count((array)($captured['components']['schemas'] ?? []))
		);
		$this->assertNotSame($base, $captured);
	}//end testFragmentSchemasAreMergedIntoTheDescriptor()

	/**
	 * An unresolvable ConfigurationService warns, logs an error and imports nothing.
	 *
	 * @return void
	 */
	public function testUnresolvableServiceWarnsAndStops(): void {
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())
			->method('warning')
			->with('Integriq: could not resolve OR ConfigurationService: boom');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('error')
			->with('Integriq: ConfigurationService resolution failed', ['exception' => 'boom']);

		$this->step(new RuntimeException('boom'), $logger)->run($output);
	}//end testUnresolvableServiceWarnsAndStops()

	/**
	 * A failing import warns and logs an error without rethrowing.
	 *
	 * @return void
	 */
	public function testFailingImportWarnsAndLogs(): void {
		$service = $this->createMock(ConfigurationService::class);
		$service->method('importFromApp')->willThrowException(new RuntimeException('nope'));

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())
			->method('warning')
			->with('Integriq: register import failed: nope');
		$output->expects($this->once())
			->method('info')
			->with('Integriq: initializing register + schemas via OR ConfigurationService…');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('error')
			->with('Integriq: importFromApp failed', ['exception' => 'nope']);

		$this->step($service, $logger)->run($output);
	}//end testFailingImportWarnsAndLogs()
}//end class

<?php

/**
 * Tests for the per-component contract registrars.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\AppInfo;

use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every `lib/<Component>/<Component>Registrar` binds contracts of its own
 * component, each to a class that implements it, and Application runs it.
 */
class ComponentRegistrarsTest extends TestCase {
	/**
	 * The registrar classes found under lib/, keyed by component.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function registrars(): array {
		$found = [];
		foreach (glob(dirname(__DIR__, 3) . '/lib/*/*Registrar.php') as $file) {
			$component = basename(dirname($file));
			if (basename($file) === $component . 'Registrar.php') {
				$found[$component] = ['OCA\\Integriq\\' . $component . '\\' . $component . 'Registrar'];
			}
		}

		return $found;
	}

	/**
	 * Each alias names an interface of the registrar's own component, bound to a class that implements it.
	 *
	 * @param string $registrar The registrar class.
	 *
	 * @return void
	 *
	 * @spec exclude container wiring invariant, no requirement states which contracts exist
	 */
	#[DataProvider('registrars')]
	public function testEveryAliasIsAnOwnContractBoundToItsImplementation(string $registrar): void {
		$aliases = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerServiceAlias')->willReturnCallback(
			function (string $alias, string $target) use (&$aliases): void {
				$aliases[$alias] = $target;
			}
		);

		(new $registrar())->register(context: $context);

		$this->assertNotEmpty($aliases, $registrar . ' binds nothing.');
		$namespace = substr($registrar, 0, (int)strrpos($registrar, '\\') + 1);
		foreach ($aliases as $contract => $implementation) {
			$this->assertStringStartsWith($namespace, $contract);
			$this->assertTrue(interface_exists($contract), $contract);
			$this->assertContains($contract, class_implements($implementation), $implementation);
		}
	}

	/**
	 * Application::register() runs every registrar.
	 *
	 * @return void
	 *
	 * @spec exclude container wiring invariant, no requirement states which contracts exist
	 */
	public function testApplicationRunsEveryRegistrar(): void {
		$application = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/AppInfo/Application.php');
		foreach (self::registrars() as $component => [$registrar]) {
			$short = $component . 'Registrar';
			$this->assertStringContainsString('(new ' . $short . '())->register(context: $context);', $application, $registrar);
		}
	}
}

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
 * Every `lib/<Component>/<Component>Registrar` wires only its own component,
 * binds each contract to a class that implements it, and Application runs it.
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
	 * Records every call a registrar makes on the registration context.
	 *
	 * @param string $registrar The registrar class.
	 *
	 * @return list<array{0: string, 1: list<mixed>}> One entry per call: method name and arguments.
	 */
	private function recordCalls(string $registrar): array {
		$calls   = [];
		$context = $this->createMock(IRegistrationContext::class);
		foreach ((new \ReflectionClass(IRegistrationContext::class))->getMethods() as $method) {
			$name = $method->getName();
			$context->method($name)->willReturnCallback(
				function (...$args) use (&$calls, $name): void {
					$calls[] = [$name, $args];
				}
			);
		}

		(new $registrar())->register(context: $context);

		return $calls;
	}

	/**
	 * Each registrar wires only its own component: the services, aliases and
	 * listeners it registers are classes of its own namespace, and every alias
	 * resolves to a class that is its contract.
	 *
	 * A named service id (one with `::` in it) is an id another app looks up;
	 * there only the bound class has to be the registrar's own.
	 *
	 * @param string $registrar The registrar class.
	 *
	 * @return void
	 *
	 * @spec exclude container wiring invariant, no requirement states which contracts exist
	 */
	#[DataProvider('registrars')]
	public function testEachRegistrarWiresOnlyItsOwnComponent(string $registrar): void {
		$calls = $this->recordCalls(registrar: $registrar);

		$this->assertNotEmpty($calls, $registrar . ' registers nothing.');
		$namespace = substr($registrar, 0, (int)strrpos($registrar, '\\') + 1);
		foreach ($calls as [$method, $args]) {
			if ($method === 'registerServiceAlias') {
				[$contract, $implementation] = $args;
				$this->assertStringStartsWith($namespace, $implementation, $contract);
				if (str_contains($contract, '::') === false) {
					$this->assertStringStartsWith($namespace, $contract);
					$this->assertTrue(is_a($implementation, $contract, true), $implementation . ' is not a ' . $contract);
				}

				continue;
			}

			$owned = match ($method) {
				'registerEventListener' => $args[1],
				default => $args[0],
			};
			if ($method === 'registerService' && str_contains($owned, '::') === true) {
				continue;
			}

			$this->assertStringStartsWith($namespace, $owned, $registrar . '::' . $method);
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

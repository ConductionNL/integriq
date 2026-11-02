<?php

/**
 * Golden test: every constructor that takes SynchronizationService or
 * EventService, under whatever contract, still resolves to that class.
 *
 * Scans lib/ for constructor parameters typed as one of the two classes or one
 * of their contracts, resolves each type through the aliases the Service
 * bindings register, and compares the parameter list and the class each
 * resolves to with tests/fixtures/golden/broad-service-collaborators.json.
 * Nextcloud's container shares one instance per registered class, so the same
 * resolved class means the same instance. Set RECORD_GOLDEN=1 to rewrite the
 * fixture.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @covers \OCA\Integriq\Service\ServiceBindings
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\EventService;
use OCA\Integriq\Service\ServiceBindings;
use OCA\Integriq\Service\SynchronizationService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * The collaborators of the two broad services, by constructor parameter.
 */
class BroadServiceCollaboratorsTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/golden/broad-service-collaborators.json';

	/**
	 * Every type a collaborator may use to receive one of the two services.
	 */
	private const TYPES = [
		'OCA\Integriq\Service\SynchronizationService',
		'OCA\Integriq\Service\SynchronizationServiceInterface',
		'OCA\Integriq\Service\SynchronizationLookupInterface',
		'OCA\Integriq\Service\EventService',
		'OCA\Integriq\Service\EventServiceInterface',
		'OCA\Integriq\Service\CloudEventEmitterInterface',
	];

	/**
	 * The same constructor parameters take the services, each resolves to the
	 * same class as before, and that class satisfies the parameter's type.
	 *
	 * @return void
	 *
	 * @spec exclude refactoring guard, it pins which class every collaborator receives
	 */
	public function testEveryCollaboratorResolvesToTheSameClass(): void {
		$aliases = $this->registeredAliases();
		$found = [];
		foreach ($this->collaboratorParameters() as [$class, $param, $type]) {
			$resolved = $type;
			while (isset($aliases[$resolved]) === true) {
				$resolved = $aliases[$resolved];
			}

			self::assertTrue(is_a($resolved, $type, true), $class . '::$' . $param . ' is typed ' . $type);
			$found[$class . '::$' . $param] = $resolved;
		}

		ksort($found);
		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($found, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
			$this->markTestSkipped('recorded');
		}

		self::assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), $found);
		self::assertContains(SynchronizationService::class, $found);
		self::assertContains(EventService::class, $found);

	}//end testEveryCollaboratorResolvesToTheSameClass()

	/**
	 * Every alias the Service bindings register, alias => target.
	 *
	 * @return array<string, string>
	 */
	private function registeredAliases(): array {
		$aliases = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerServiceAlias')->willReturnCallback(
			static function (string $alias, string $target) use (&$aliases): void {
				$aliases[$alias] = $target;
			}
		);

		(new ServiceBindings())->register(context: $context);

		return $aliases;

	}//end registeredAliases()

	/**
	 * Every constructor parameter in lib/ typed as one of the services or contracts.
	 *
	 * @return list<array{0: string, 1: string, 2: string}> Class, parameter and type.
	 */
	private function collaboratorParameters(): array {
		$lib = dirname(__DIR__, 3) . '/lib';
		$found = [];
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($lib, \FilesystemIterator::SKIP_DOTS));
		foreach ($files as $file) {
			$class = $this->classIn((string)$file);
			if ($class === null || class_exists($class) === false) {
				continue;
			}

			$constructor = (new ReflectionClass($class))->getConstructor();
			foreach (($constructor?->getParameters() ?? []) as $param) {
				$type = $param->getType();
				if ($type instanceof ReflectionNamedType && in_array($type->getName(), self::TYPES, true) === true) {
					$found[] = [$class, $param->getName(), $type->getName()];
				}
			}
		}

		return $found;

	}//end collaboratorParameters()

	/**
	 * The class a PHP file declares when it names one of the types, or null.
	 *
	 * @param string $path The file.
	 *
	 * @return string|null The fully qualified class name.
	 */
	private function classIn(string $path): ?string {
		if (str_ends_with($path, '.php') === false) {
			return null;
		}

		$source = (string)file_get_contents($path);
		if (preg_match('/SynchronizationService|SynchronizationLookupInterface|EventService|CloudEventEmitterInterface/', $source) !== 1
			|| preg_match('/^namespace\s+([^;]+);/m', $source, $ns) !== 1
			|| preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', $source, $name) !== 1
		) {
			return null;
		}

		return $ns[1] . '\\' . $name[1];

	}//end classIn()
}//end class

<?php

/**
 * Inject a container double into OCP\AppFramework\App across NC 32-35.
 *
 * `App::$container` is untyped on NC 32-34 and declared `private DIContainer
 * $container` on NC 35. A test that mocks `IAppContainer` and reflects it into
 * the property therefore works on the older half of the declared range and
 * throws on the newer one — reflection assignment still enforces a declared
 * property type:
 *
 *   TypeError: Cannot assign MockObject_IAppContainer to property
 *   OCP\AppFramework\App::$container of type
 *   OC\AppFramework\DependencyInjection\DIContainer
 *
 * Reading the declared type back off the property, rather than naming either
 * class, keeps ONE test body correct on every server in the range: NC 35 gets a
 * DIContainer double, NC 32-34 get the IAppContainer they always got. Both
 * expose the `get()`/`query()` surface these tests stub, because DIContainer
 * implements IAppContainer.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Helpers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Helpers;

use OCP\AppFramework\App;
use OCP\AppFramework\IAppContainer;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Container-injection helpers for tests that build an App without its constructor.
 */
trait AppContainerInjection {
	/**
	 * The class a container double must be, for the server under test.
	 *
	 * @return class-string The declared type of App::$container, or IAppContainer
	 *                      where the property carries no type.
	 */
	private function appContainerType(): string {
		$reflection = new ReflectionClass(App::class);
		if ($reflection->hasProperty('container') === false) {
			return IAppContainer::class;
		}

		$type = $reflection->getProperty('container')->getType();
		if ($type instanceof ReflectionNamedType === true && $type->isBuiltin() === false) {
			$declared = $type->getName();

			// `class_exists()` triggers the autoloader, so this answers "can a
			// double of the declared type actually be built here" rather than
			// "what does the signature say". DIContainer is an OC\ class, not
			// OCP\, and this suite stubs several of those — if it is not
			// loadable the old behaviour is still the best available answer.
			if (class_exists($declared) === true || interface_exists($declared) === true) {
				return $declared;
			}
		}

		return IAppContainer::class;
	}//end appContainerType()

	/**
	 * Put a container double behind App::getContainer().
	 *
	 * @param object $app       An Application built without its constructor.
	 * @param object $container A double of {@see self::appContainerType()}.
	 *
	 * @return void
	 */
	private function injectAppContainer(object $app, object $container): void {
		$reflection = new ReflectionClass(App::class);
		if ($reflection->hasProperty('container') === false) {
			return;
		}

		$property = $reflection->getProperty('container');
		$property->setAccessible(true);
		$property->setValue($app, $container);
	}//end injectAppContainer()
}//end trait

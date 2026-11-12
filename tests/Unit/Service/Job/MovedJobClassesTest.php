<?php

/**
 * Unit tests for MovedJobClasses.
 *
 * A job stored before the repair step ran still names a moved class by its old
 * name; these tests pin that such a name keeps resolving to the moved class.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Job
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec exclude Class-move plumbing; it keeps stored class names resolvable
 *       and adds no domain behaviour.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Job;

use OCA\Integriq\Service\Job\MovedJobClasses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests the old-to-new map and the aliases for the old names.
 *
 * @covers \OCA\Integriq\Service\Job\MovedJobClasses
 */
class MovedJobClassesTest extends TestCase {
	/**
	 * Every old name maps to a class that exists, in the Service component,
	 * and no old name is itself a file under lib/ any more.
	 *
	 * @return void
	 */
	public function testEveryNewNameExistsAndNoOldNameIsAFile(): void {
		$this->assertCount(6, MovedJobClasses::NEW_NAMES);
		foreach (MovedJobClasses::NEW_NAMES as $oldName => $newName) {
			$this->assertTrue(class_exists($newName), $newName);
			$this->assertStringStartsWith('OCA\\Integriq\\Service\\', $newName);
			$relative = str_replace('\\', '/', substr($oldName, strlen('OCA\\Integriq\\')));
			$this->assertFileDoesNotExist(__DIR__ . '/../../../../lib/' . $relative . '.php', $oldName);
		}
	}//end testEveryNewNameExistsAndNoOldNameIsAFile()

	/**
	 * After registerAliases() an old name loads, and is the moved class itself,
	 * so the container builds the same object for either name.
	 *
	 * @return void
	 */
	public function testOldNamesResolveToTheMovedClass(): void {
		MovedJobClasses::registerAliases();
		MovedJobClasses::registerAliases();

		foreach (MovedJobClasses::NEW_NAMES as $oldName => $newName) {
			$this->assertSame($newName, self::loadedName(class: $oldName), $oldName);
		}

		$this->assertNull(self::loadedName(class: 'OCA\\Integriq\\Action\\NoSuchAction'));
	}//end testOldNamesResolveToTheMovedClass()

	/**
	 * The name of the class a name loads, or null when it loads nothing.
	 *
	 * @param string $class The name to autoload
	 *
	 * @return string|null
	 */
	private static function loadedName(string $class): ?string {
		if (class_exists($class) === false) {
			return null;
		}

		return (new ReflectionClass($class))->getName();
	}//end loadedName()
}//end class

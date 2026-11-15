<?php

/**
 * The mapping functions that need Integriq's own services are contributed to
 * OpenRegister's mapping engine, and only on its registration event.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Twig
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/authentication-twig/spec.md#requirement-twig-mapping-runtime-encoding-mapping-execution-file-lookup-slug-req-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Twig;

use OCA\Integriq\Twig\MappingFunctionRegistrationListener;
use OCA\Integriq\Twig\MappingRuntime;
use OCA\OpenRegister\Service\RegisterMappingFunctionsEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Twig\TwigFunction;

/**
 * @covers \OCA\Integriq\Twig\MappingFunctionRegistrationListener
 */
class MappingFunctionRegistrationListenerTest extends TestCase {

	/**
	 * The three functions are registered by name, each backed by the runtime method of that name.
	 *
	 * @return void
	 */
	public function testTheRuntimeFunctionsAreRegistered(): void {
		$event = new RegisterMappingFunctionsEvent();

		(new MappingFunctionRegistrationListener())->handle(event: $event);

		$this->assertSame(
			expected: ['callSource', 'getTargetIdByOriginId', 'getOriginIdByTargetId'],
			actual: $event->getAllowedNames()
		);
		$callables = array_map(
			static fn (TwigFunction $function): mixed => $function->getCallable(),
			$event->getFunctions()
		);
		$this->assertSame(
			expected: [
				[MappingRuntime::class, 'callSource'],
				[MappingRuntime::class, 'getTargetIdByOriginId'],
				[MappingRuntime::class, 'getOriginIdByTargetId'],
			],
			actual: $callables
		);
	}//end testTheRuntimeFunctionsAreRegistered()

	/**
	 * Every registered callable names a method the runtime really has.
	 *
	 * @return void
	 */
	public function testEveryCallableExistsOnTheRuntime(): void {
		$event = new RegisterMappingFunctionsEvent();

		(new MappingFunctionRegistrationListener())->handle(event: $event);

		foreach ($event->getFunctions() as $function) {
			[$class, $method] = $function->getCallable();
			$this->assertTrue(condition: method_exists($class, $method), message: $class.'::'.$method.' does not exist');
		}
	}//end testEveryCallableExistsOnTheRuntime()

	/**
	 * Any other event is ignored, even one that could take a function.
	 *
	 * @return void
	 */
	public function testAnyOtherEventIsIgnored(): void {
		$other = new class extends Event {
			/**
			 * Functions offered to this look-alike.
			 *
			 * @var TwigFunction[]
			 */
			public array $received = [];

			/**
			 * Record a function.
			 *
			 * @param TwigFunction $function The function.
			 *
			 * @return void
			 */
			public function registerFunction(TwigFunction $function): void {
				$this->received[] = $function;
			}//end registerFunction()
		};

		(new MappingFunctionRegistrationListener())->handle(event: $other);

		$this->assertSame(expected: [], actual: $other->received);
	}//end testAnyOtherEventIsIgnored()
}//end class

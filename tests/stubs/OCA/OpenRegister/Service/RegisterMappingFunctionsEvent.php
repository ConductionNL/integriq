<?php

/**
 * Stub for OCA\OpenRegister\Service\RegisterMappingFunctionsEvent.
 *
 * Mirrors OpenRegister's own event: apps add Twig functions to the mapping
 * engine through registerFunction(), which records each function and its name.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Stubs
 * @license  EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCP\EventDispatcher\Event;
use Twig\TwigFunction;

/**
 * Collects the Twig functions apps contribute to the mapping engine.
 */
class RegisterMappingFunctionsEvent extends Event {

	/**
	 * The registered functions.
	 *
	 * @var TwigFunction[]
	 */
	private array $functions = [];

	/**
	 * The names of the registered functions.
	 *
	 * @var string[]
	 */
	private array $allowedNames = [];

	/**
	 * Register a function.
	 *
	 * @param TwigFunction $function The function.
	 *
	 * @return void
	 */
	public function registerFunction(TwigFunction $function): void {
		$this->functions[]    = $function;
		$this->allowedNames[] = $function->getName();
	}//end registerFunction()

	/**
	 * The registered functions.
	 *
	 * @return TwigFunction[]
	 */
	public function getFunctions(): array {
		return $this->functions;
	}//end getFunctions()

	/**
	 * The names of the registered functions.
	 *
	 * @return string[]
	 */
	public function getAllowedNames(): array {
		return $this->allowedNames;
	}//end getAllowedNames()
}//end class

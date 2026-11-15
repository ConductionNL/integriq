<?php

/**
 * Characterisation test for the inbound controllers' raw request body read.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\DSOController;
use OCA\Integriq\Controller\IwmoIjwController;
use OCA\Integriq\Controller\OpenFormulierenController;
use OCA\Integriq\Controller\OsoController;
use OCA\Integriq\Controller\RodController;
use OCA\Integriq\Controller\UwlrEduVController;
use OCA\Integriq\Controller\VerzuimloketController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pins what getRawContent() answers when php://input is empty, as it is under the CLI.
 */
class RawRequestBodyGoldenTest extends TestCase {

	/**
	 * The controllers that read the raw body for signature verification.
	 *
	 * @return array<string,array{0:class-string}>
	 */
	public static function controllers(): array {
		return [
			'dso' => [DSOController::class],
			'iwmo-ijw' => [IwmoIjwController::class],
			'open-formulieren' => [OpenFormulierenController::class],
			'oso' => [OsoController::class],
			'rod' => [RodController::class],
			'uwlr-eduv' => [UwlrEduVController::class],
			'verzuimloket' => [VerzuimloketController::class],
		];

	}//end controllers()

	/**
	 * An empty request body reads as the empty string.
	 *
	 * @param class-string $controller The controller class.
	 *
	 * @return void
	 */
	#[DataProvider('controllers')]
	public function testEmptyBodyReadsAsEmptyString(string $controller): void {
		$reflection = new ReflectionClass($controller);
		$instance = $reflection->newInstanceWithoutConstructor();
		$method = $reflection->getMethod('getRawContent');

		$this->assertSame('', $method->invoke($instance));

	}//end testEmptyBodyReadsAsEmptyString()
}//end class

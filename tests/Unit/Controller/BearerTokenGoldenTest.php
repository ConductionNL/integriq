<?php

/**
 * The wallet and LTI controllers read a bearer token the same way.
 *
 * Pins, for both controllers, which Authorization headers yield a token and
 * which yield none, so the shared reading can move without changing which
 * calls reach the token checks.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
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

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\EudiWalletController;
use OCA\Integriq\Controller\LtiController;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Golden tests for reading the bearer token.
 */
final class BearerTokenGoldenTest extends TestCase {

	/**
	 * Headers and the token each yields, for both controllers.
	 *
	 * @return array<string, array{0: class-string, 1: string, 2: ?string}>
	 */
	public static function headers(): array {
		$rows = [];
		$cases = [
			'a token' => ['Bearer abc.def', 'abc.def'],
			'padded' => ['Bearer   tok  ', 'tok'],
			'blank token' => ['Bearer    ', null],
			'scheme only' => ['Bearer', null],
			'lower case scheme' => ['bearer abc', null],
			'other scheme' => ['Basic abc', null],
			'no header' => ['', null],
		];
		foreach ([EudiWalletController::class, LtiController::class] as $class) {
			foreach ($cases as $name => [$header, $token]) {
				$rows[$class . ' ' . $name] = [$class, $header, $token];
			}
		}

		return $rows;

	}//end headers()

	/**
	 * The token a header yields.
	 *
	 * @param class-string $class  The controller.
	 * @param string       $header The Authorization header.
	 * @param string|null  $token  The token it yields.
	 *
	 * @return void
	 */
	#[DataProvider('headers')]
	public function testTheTokenAHeaderYields(string $class, string $header, ?string $token): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('Authorization')->willReturn($header);
		$controller = (new ReflectionClass($class))->newInstanceWithoutConstructor();

		$read = (function (IRequest $request): ?string {
			$this->request = $request;
			return $this->extractBearerToken();
		})->call($controller, $request);

		$this->assertSame($token, $read);

	}//end testTheTokenAHeaderYields()
}//end class

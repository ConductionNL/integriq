<?php

/**
 * Golden test for SOAPService::getSoapVersion(): which configured value picks
 * SOAP 1.1, which picks 1.2, and which is refused.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/http-call-engine/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use GuzzleHttp\Cookie\CookieJar;
use OCA\Integriq\Service\SOAPService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Pins the SOAP version resolution, loose string comparison included.
 *
 * @spec openspec/specs/http-call-engine/spec.md
 */
class SoapVersionGoldenTest extends TestCase {

	/**
	 * Resolve a configured version through the private method.
	 *
	 * @param string|int|null $configured The configured version.
	 *
	 * @return int
	 */
	private function resolve(string|int|null $configured): int {
		$method = new ReflectionMethod(SOAPService::class, 'getSoapVersion');

		return $method->invoke(new SOAPService(new CookieJar()), $configured);

	}//end resolve()

	/**
	 * Each configured value and the version it resolves to: an integer, or the
	 * name of the ext-soap constant, read only when the extension is loaded.
	 *
	 * @return array<string,array{0:string|int|null,1:int|string}>
	 */
	public static function versions(): array {
		return [
			'int 1' => [1, 1],
			'int 2' => [2, 2],
			'1.1' => ['1.1', 'SOAP_1_1'],
			'1_1' => ['1_1', 'SOAP_1_1'],
			'soap1.1' => ['soap1.1', 'SOAP_1_1'],
			'soap1_1' => ['soap1_1', 'SOAP_1_1'],
			'soap_1_1' => ['soap_1_1', 'SOAP_1_1'],
			'SOAP_1_1' => ['SOAP_1_1', 'SOAP_1_1'],
			'numeric equal 1.10' => ['1.10', 'SOAP_1_1'],
			'numeric equal 01.1' => ['01.1', 'SOAP_1_1'],
			'1.2' => ['1.2', 'SOAP_1_2'],
			'SOAP_1_2' => ['SOAP_1_2', 'SOAP_1_2'],
			'case differs' => ['Soap1.1', 'SOAP_1_2'],
			'unknown' => ['x', 'SOAP_1_2'],
			'empty' => ['', 'SOAP_1_2'],
			'null' => [null, 'SOAP_1_2'],
		];

	}//end versions()

	/**
	 * A configured value resolves as before.
	 *
	 * @param string|int|null $configured The configured version.
	 * @param int|string      $expected   The resolved version, or the name of its SOAP_* constant.
	 *
	 * @return void
	 */
	#[DataProvider('versions')]
	#[RequiresPhpExtension('soap')]
	public function testVersionResolves(string|int|null $configured, int|string $expected): void {
		if (is_string($expected) === true) {
			$expected = constant($expected);
		}

		$this->assertSame($expected, $this->resolve($configured));

	}//end testVersionResolves()

	/**
	 * Integers outside 1 and 2 are refused.
	 *
	 * @return array<string,array{0:int}>
	 */
	public static function refusedIntegers(): array {
		return ['zero' => [0], 'three' => [3], 'negative' => [-1]];

	}//end refusedIntegers()

	/**
	 * An unsupported integer is refused with the configuration message.
	 *
	 * @param int $configured The configured version.
	 *
	 * @return void
	 */
	#[DataProvider('refusedIntegers')]
	public function testUnsupportedIntegerIsRefused(int $configured): void {
		$this->expectException(BadRequestHttpException::class);
		$this->expectExceptionMessage('improper configuration, only soap 1.1 and 1.2 are supported');

		$this->resolve($configured);

	}//end testUnsupportedIntegerIsRefused()
}//end class

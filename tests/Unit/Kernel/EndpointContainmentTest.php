<?php

/**
 * Tests for the endpoint containment predicate.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Kernel
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

namespace OCA\Integriq\Tests\Unit\Kernel;

use OCA\Integriq\Flow\FlowConfigGuard;
use OCA\Integriq\Kernel\EndpointContainment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins which endpoints escape their Source location, and with which code,
 * for both the Kernel predicate and the flow guard that answers through it.
 */
class EndpointContainmentTest extends TestCase {

	/**
	 * Endpoints and the escape code each one yields (null: contained).
	 *
	 * @return array<string, array{string, string|null}>
	 */
	public static function endpoints(): array {
		return [
			'relative path'              => ['/zaken/123', null],
			'bare path'                  => ['zaken?page=2', null],
			'encoded slash in a segment' => ['/a%2Fb', null],
			'newline'                    => ["/zaken\nHost: evil", 'control-character'],
			'delete character'           => ["/zaken\x7F", 'control-character'],
			'scheme-relative'            => ['//evil.example/x', 'scheme-relative'],
			'backslash host'             => ['\\\\evil\\x', 'scheme-relative'],
			'absolute url'               => ['https://evil.example/x', 'absolute-url'],
			'other scheme'               => ['file:/etc/passwd', 'absolute-url'],
			'encoded scheme-relative'    => ['%2F%2Fevil.example', 'decoded-scheme-relative'],
			'parent only'                => ['..', 'path-traversal'],
			'leading parent'             => ['../secret', 'path-traversal'],
			'inner parent'               => ['/a/../b', 'path-traversal'],
			'trailing parent'            => ['/a/..', 'path-traversal'],
			'encoded parent'             => ['/a/%2e%2e/b', 'path-traversal'],
		];

	}//end endpoints()

	/**
	 * The Kernel predicate answers the expected code.
	 *
	 * @param string      $endpoint The endpoint.
	 * @param string|null $expected The expected escape code.
	 *
	 * @return void
	 */
	#[DataProvider('endpoints')]
	public function testEscapeCode(string $endpoint, ?string $expected): void {
		$this->assertSame(expected: $expected, actual: EndpointContainment::escapeCode(endpoint: $endpoint));

	}//end testEscapeCode()

	/**
	 * The flow guard answers exactly what the Kernel predicate answers.
	 *
	 * @param string      $endpoint The endpoint.
	 * @param string|null $expected The expected escape code.
	 *
	 * @return void
	 */
	#[DataProvider('endpoints')]
	public function testFlowGuardAgrees(string $endpoint, ?string $expected): void {
		$this->assertSame(expected: $expected, actual: FlowConfigGuard::endpointEscapeCode(endpoint: $endpoint));

	}//end testFlowGuardAgrees()

	/**
	 * The guard's escape codes keep their values.
	 *
	 * @return void
	 */
	public function testGuardConstantsKeepTheirValues(): void {
		$this->assertSame(expected: 'control-character', actual: FlowConfigGuard::ESCAPE_CONTROL_CHARACTER);
		$this->assertSame(expected: 'scheme-relative', actual: FlowConfigGuard::ESCAPE_SCHEME_RELATIVE);
		$this->assertSame(expected: 'absolute-url', actual: FlowConfigGuard::ESCAPE_ABSOLUTE_URL);
		$this->assertSame(expected: 'decoded-scheme-relative', actual: FlowConfigGuard::ESCAPE_DECODED_SCHEME_RELATIVE);
		$this->assertSame(expected: 'path-traversal', actual: FlowConfigGuard::ESCAPE_PATH_TRAVERSAL);

	}//end testGuardConstantsKeepTheirValues()
}//end class

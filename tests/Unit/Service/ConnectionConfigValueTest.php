<?php

/**
 * Unit tests for ConnectionConfigValue (connection-registry, umbrella D2 and D4).
 *
 * The resolver tests cover these rules through whole rows. These tests pin the
 * three public judgements on their own.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-the-resolver-applies-the-d4-rules-in-order-req-conn-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\ConnectionConfigValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Filled, one of, and text.
 */
class ConnectionConfigValueTest extends TestCase {

	/**
	 * Cases: name => [value, expected filled].
	 *
	 * @return array<string,array{0:mixed,1:bool}>
	 */
	public static function filledCases(): array {
		return [
			'empty array' => [[], false],
			'list with a zero' => [[0], true],
			'text [] ' => [' [] ', false],
			'text { }' => ['{ }', false],
			'text [0] is filled, because a list holding a zero is not empty' => ['[0]', true],
			'text null stays filled' => ['null', true],
			'null' => [null, false],
			'boolean false' => [false, false],
			'text False' => [' False ', false],
			'a word' => ['live', true],
		];
	}//end filledCases()

	/**
	 * Each case counts as filled or empty as listed.
	 *
	 * @param mixed $value The value.
	 * @param bool $filled Whether it counts as filled.
	 *
	 * @return void
	 */
	#[DataProvider('filledCases')]
	public function testIsFilled(mixed $value, bool $filled): void {
		$this->assertSame(expected: $filled, actual: (new ConnectionConfigValue())->isFilled($value));
	}//end testIsFilled()

	/**
	 * A value equals a candidate as trimmed text, case-insensitively, and a non-empty list never does.
	 *
	 * @return void
	 */
	public function testIsOneOf(): void {
		$value = new ConnectionConfigValue();

		$this->assertTrue(condition: $value->isOneOf(' None ', ['none']));
		$this->assertTrue(condition: $value->isOneOf(false, ['FALSE']));
		$this->assertTrue(condition: $value->isOneOf(null, ['']));
		$this->assertTrue(condition: $value->isOneOf([], ['']));
		$this->assertFalse(condition: $value->isOneOf(null, ['none']));
		$this->assertFalse(condition: $value->isOneOf(['none'], ['none', '']));
		$this->assertFalse(condition: $value->isOneOf('0', [0]));
	}//end testIsOneOf()

	/**
	 * Scalars read as trimmed text; null, lists and objects read as the empty string.
	 *
	 * @return void
	 */
	public function testText(): void {
		$value = new ConnectionConfigValue();

		$this->assertSame(expected: ['true', 'false', '24', '1.5', 'x', '', ''], actual: [
			$value->text(true),
			$value->text(false),
			$value->text(24),
			$value->text(1.5),
			$value->text(' x '),
			$value->text(null),
			$value->text(['a']),
		]);
	}//end testText()
}//end class

<?php

/**
 * Unit tests for leaving masked values out of imported data.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Security
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Security;

use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The placeholder is never imported as a value.
 *
 * @covers \OCA\Integriq\Service\Security\SensitiveFieldRegistry
 * @covers \OCA\Integriq\Service\Security\RedactedValueFilter
 * @covers \OCA\Integriq\Service\Security\SensitiveValueMasker
 */
class RedactedValueFilterTest extends TestCase {

	private const MASK = SensitiveFieldRegistry::PLACEHOLDER;

	/**
	 * Placeholders, and arrays of nothing but placeholders, are left out; everything else stays.
	 *
	 * @return void
	 */
	public function testMaskedValuesAreLeftOut(): void {
		$data = [
			'secret' => self::MASK,
			'name' => 'n',
			'auth' => ['user' => self::MASK, 'pass' => self::MASK],
			'mixed' => ['type' => 'basic', 'password' => self::MASK],
			'keys' => [self::MASK, self::MASK],
			'list' => ['a', self::MASK, 'b'],
			'deep' => ['x' => ['y' => self::MASK]],
			'url' => 'https://h.example/?token=' . self::MASK,
			'empty' => [],
			'zero' => 0,
			'nil' => null,
		];

		$this->assertSame(
			[
				'name' => 'n',
				'mixed' => ['type' => 'basic'],
				'list' => ['a', 'b'],
				'url' => 'https://h.example/?token=' . self::MASK,
				'empty' => [],
				'zero' => 0,
				'nil' => null,
			],
			(new SensitiveFieldRegistry())->withoutRedactedValues(data: $data)
		);
	}//end testMaskedValuesAreLeftOut()

	/**
	 * A top-level list keeps its order without gaps.
	 *
	 * @return void
	 */
	public function testAListIsReindexed(): void {
		$this->assertSame(['a', ['b']], (new SensitiveFieldRegistry())->withoutRedactedValues(data: [self::MASK, 'a', [self::MASK, 'b']]));
	}//end testAListIsReindexed()
}//end class

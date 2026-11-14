<?php

/**
 * Characterisation of GatewayDescriptor::fromArray(): every refusal and the
 * descriptor it reads, defaults included.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Gateway
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Gateway;

use InvalidArgumentException;
use OCA\Integriq\Gateway\GatewayDescriptor;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Integriq\Gateway\GatewayDescriptor
 */
class GatewayDescriptorFromArrayTest extends TestCase {

	/**
	 * What fromArray answers for an entry: the descriptor array or the refusal.
	 *
	 * @param array<string,mixed> $entry The entry.
	 *
	 * @return array<string,mixed>|string The descriptor, or the refusal message.
	 */
	private static function read(array $entry): array|string {
		try {
			return GatewayDescriptor::fromArray($entry)->toArray();
		} catch (InvalidArgumentException $refusal) {
			return $refusal->getMessage();
		}
	}

	/**
	 * Every refusal, in the order the fields are checked, and two descriptors.
	 *
	 * @return void
	 */
	public function testEveryEntryReadsAsBefore(): void {
		$valid = ['id' => 'corv', 'standard' => 'CORV 2.0', 'claimLevel' => GatewayDescriptor::CLAIM_PARTIAL, 'claimEvidence' => 'tests'];

		$this->assertSame('A gateway entry without an id cannot be registered.', self::read(['standard' => 'x']));
		$this->assertSame('The gateway entry "corv" declares no standard, so it cannot be registered.', self::read(['id' => 'corv', 'claimLevel' => 'nope']));
		$this->assertSame(
			'The gateway entry "corv" declares no usable claimLevel. It must be one of ' . implode(', ', GatewayDescriptor::CLAIM_LEVELS) . '.',
			self::read(['claimLevel' => 'nope'] + $valid)
		);
		$this->assertSame(
			'The gateway entry "corv" claims ' . GatewayDescriptor::CLAIM_PARTIAL . ' and names no evidence for it.',
			self::read(['claimEvidence' => '  '] + $valid)
		);

		$defaults = self::read($valid);
		$full = self::read(
			['label' => 'CORV', 'jurisdiction' => 7, 'wmebvMet' => ['a' => 'x', 'b' => 'y'], 'wmebvHandedToConsumer' => 'z', 'transport' => 'digikoppeling'] + $valid
		);
		$empty = self::read(['jurisdiction' => ''] + $valid);
		$this->assertSame(
			[$defaults, $full, $empty],
			[
				GatewayDescriptor::fromArray($valid)->toArray(),
				(new GatewayDescriptor('corv', 'CORV', 'CORV 2.0', GatewayDescriptor::CLAIM_PARTIAL, 'tests', '7', ['x', 'y'], ['z'], 'digikoppeling'))->toArray(),
				(new GatewayDescriptor('corv', 'corv', 'CORV 2.0', GatewayDescriptor::CLAIM_PARTIAL, 'tests', null, [], [], 'https'))->toArray(),
			]
		);
		$this->assertSame((new GatewayDescriptor('corv', 'corv', 'CORV 2.0', GatewayDescriptor::CLAIM_PARTIAL, 'tests'))->toArray(), $defaults);
	}
}

<?php

/**
 * Tests for NestedValuePath.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Security
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/brp-connection-monitor-from-pipelinq/specs/source-credential-custody/spec.md#requirement-a-connection-handed-over-by-another-app-keeps-its-secrets-in-keepiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Security;

use OCA\Integriq\Service\Security\NestedValuePath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Reading, writing and emptiness of a value at a key path.
 */
#[CoversClass(NestedValuePath::class)]
class NestedValuePathTest extends TestCase {

	/**
	 * A nested value is read; a missing level or a scalar on the way reads as null.
	 *
	 * @return void
	 */
	public function testGetReadsANestedValueAndNullForAMissingOne(): void {
		$data = ['configuration' => ['authentication' => ['client_id' => 'c'], 'cert' => '']];

		self::assertSame('c', (new NestedValuePath())->get(data: $data, path: ['configuration', 'authentication', 'client_id']));
		self::assertSame('', (new NestedValuePath())->get(data: $data, path: ['configuration', 'cert']));
		self::assertNull((new NestedValuePath())->get(data: $data, path: ['configuration', 'authentication', 'mtls', 'caBundle']));
		self::assertNull((new NestedValuePath())->get(data: $data, path: ['configuration', 'cert', 'deeper']));

	}//end testGetReadsANestedValueAndNullForAMissingOne()

	/**
	 * Set creates missing levels, replaces a scalar on the way, and keeps siblings.
	 *
	 * @return void
	 */
	public function testSetCreatesMissingLevelsAndKeepsSiblings(): void {
		$data = ['configuration' => ['authentication' => ['tokenUrl' => 't'], 'cert' => 'x']];

		$data = (new NestedValuePath())->set(data: $data, path: ['configuration', 'authentication', 'mtls', 'caBundle'], value: 'ref');
		$data = (new NestedValuePath())->set(data: $data, path: ['configuration', 'cert', 'inner'], value: 1);

		self::assertSame(
			['configuration' => ['authentication' => ['tokenUrl' => 't', 'mtls' => ['caBundle' => 'ref']], 'cert' => ['inner' => 1]]],
			$data
		);

	}//end testSetCreatesMissingLevelsAndKeepsSiblings()

	/**
	 * Null, an empty array and a blank string are empty; anything else is not.
	 *
	 * @return void
	 */
	public function testIsEmpty(): void {
		self::assertTrue((new NestedValuePath())->isEmpty(value: null));
		self::assertTrue((new NestedValuePath())->isEmpty(value: []));
		self::assertTrue((new NestedValuePath())->isEmpty(value: '  '));
		self::assertFalse((new NestedValuePath())->isEmpty(value: '0'));
		self::assertFalse((new NestedValuePath())->isEmpty(value: ['credentialRef' => []]));
		self::assertFalse((new NestedValuePath())->isEmpty(value: false));

	}//end testIsEmpty()
}//end class

<?php

/**
 * Characterisation test for the order of ConnectionDeclarationValidator's
 * error messages across a whole declaration file.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\ConnectionDeclarationValidator;
use PHPUnit\Framework\TestCase;

/**
 * Pins every message, and its position, for a file that fails in many ways
 * at once: file fields, entries that are no object, unknown and missing
 * fields per entry and per sub-object, bad required lists and duplicates.
 */
class ConnectionDeclarationErrorOrderTest extends TestCase {

	/**
	 * Every failure is reported once, file first, then entry by entry.
	 *
	 * @return void
	 */
	public function testEveryFailureIsReportedInFileOrder(): void {
		$data = [
			'appId'       => 'Bad App',
			'extra'       => 1,
			'connections' => [
				'not-an-object',
				['key' => 'a', 'surprise' => true, 'adapter' => ['bogus' => 1], 'switch' => []],
				['key' => 'a', 'requiredConfig' => ['x', ['configKey' => 'k', 'jsonPath' => 'p', 'more' => 1]]],
				['key' => 'b'],
				['key' => 'b', 'adapter' => 'not-object'],
				[7 => 'int key'],
			],
		];

		$this->assertSame(
			[
				'/appId: is not allowed',
				'/extra: is not allowed',
				'/app: is required',
				'/connections/0: must be an object',
				'/connections/1/surprise: is not allowed',
				'/connections/1/title: is required',
				'/connections/1/adapter/bogus: is not allowed',
				'/connections/1/switch/configKey: is required',
				'/connections/2/title: is required',
				'/connections/2/requiredConfig: must be an array of non-empty strings or {configKey, jsonPath} objects',
				'/connections/2/key: duplicates key "a"',
				'/connections/3/title: is required',
				'/connections/4/title: is required',
				'/connections/4/adapter: must be an object',
				'/connections/4/key: duplicates key "b"',
				'/connections/5/7: is not allowed',
				'/connections/5/key: is required',
				'/connections/5/title: is required',
			],
			(new ConnectionDeclarationValidator())->validate($data)
		);

	}//end testEveryFailureIsReportedInFileOrder()

	/**
	 * Connections that are no list stop after the file fields; a non-object
	 * file is one message.
	 *
	 * @return void
	 */
	public function testAFileWithoutAConnectionListStopsAtItsOwnFields(): void {
		$validator = new ConnectionDeclarationValidator();

		$this->assertSame(['/app: is required', '/connections: must be an array'], $validator->validate(['connections' => ['k' => []]]));
		$this->assertSame(['/: must be an object'], $validator->validate([1, 2]));

	}//end testAFileWithoutAConnectionListStopsAtItsOwnFields()

}//end class
